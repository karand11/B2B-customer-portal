<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Volume data for the load rig (docs/phase-7-design.md section 6.1): companies with billing addresses, 100 customers (load001@load.test ...), one staff and
 * one admin user, 5,000 products, 50,000 orders with items and history, 10,000 quotes (mixed statuses, items, history), 20,000 invoices (from the
 * orders, gap-free numbers, counters aligned, items, history) and 200,000 audit rows over 12 months. Set-based SQL only (no Faker, no factories: the
 * load image installs --no-dev). Quotes get NO attachments (a row without its file would break quotes:storage-check and the download).
 *
 * DESTRUCTIVE and therefore double guarded: it TRUNCATES users, companies, products, orders and everything that references them, so it runs only when
 * APP_ENV is local, testing or loadtest AND the database name ends in _load, _loadtest or _test. It can never run in production or staging.
 * The password of the generated users comes from LOADTEST_PASSWORD (never a published demo password).
 * Orders, quote lines and invoice lines reference only priced products. Invoices exist only for orders that can be invoiced (confirmed, shipped, delivered).
 */
class SeedLoadTestData extends Command
{
    protected $signature = 'portal:loadtest-seed {--products=5000} {--companies=20} {--users=100} {--orders=50000} {--quotes=10000} {--invoices=20000} {--audit=200000} {--scale=1 : Multiplies --products, --orders, --quotes, --invoices and --audit}';

    protected $description = 'Replace the data of a LOAD database with volume data for the load test (never runs in production)';

    private const ENVIRONMENTS = ['local', 'testing', 'loadtest'];

    /** `_test_<n>` is the per-worker database of `pest --parallel`. */
    private const DATABASE_NAME = '/_(load|loadtest|test)(_\d+)?$/D';

    public function handle(): int
    {
        $password = (string) config('portal.loadtest_password');
        $database = (string) DB::connection()->getDatabaseName();

        if (! app()->environment(self::ENVIRONMENTS)) {
            return $this->refuse('Refusing to run: this command only runs when APP_ENV is local, testing or loadtest.');
        }
        if (preg_match(self::DATABASE_NAME, $database) !== 1) {
            return $this->refuse('Refusing to run: the database name must end in _load, _loadtest or _test (the command empties the catalog, users and orders).');
        }
        if (strlen($password) < 12) {
            return $this->refuse('Set LOADTEST_PASSWORD (at least 12 characters): the load users never get a published demo password.');
        }

        $scale = max(1, (int) $this->option('scale'));
        $companies = max(1, (int) $this->option('companies'));
        $users = max(1, (int) $this->option('users'));
        $products = max(3, (int) $this->option('products') * $scale);
        $volume = fn (string $option) => max(0, (int) $this->option($option) * $scale);
        $orders = $volume('orders');
        $hash = Hash::make($password);
        $counts = ['quotes' => 0, 'invoices' => 0, 'audit' => 0];

        DB::transaction(function () use ($companies, $users, $products, $orders, $hash, $volume, &$counts) {
            (new RolesSeeder)->run();
            (new CategorySeeder)->run();

            // CASCADE reaches orders, quotes, invoices and the audit log (all reference users or companies); the counters table has no foreign key.
            DB::statement('TRUNCATE TABLE users, companies, products, invoice_number_counters, audit_log RESTART IDENTITY CASCADE');
            DB::table('model_has_roles')->where('model_type', User::class)->delete();

            $this->companies($companies);
            $this->users($users, $companies, $hash);
            $this->products($products);
            $orders > 0 && $this->orders($orders, $users);
            $volume('quotes') > 0 && $this->quotes($volume('quotes'), $users);
            $volume('invoices') > 0 && $this->invoices($volume('invoices'));
            $volume('audit') > 0 && $this->audit($volume('audit'), $companies, $users, $products);

            $counts = [
                'quotes' => DB::table('quote_requests')->count(), 'invoices' => DB::table('invoices')->count(), 'audit' => DB::table('audit_log')->count(),
            ];
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        DB::statement('ANALYZE');

        $this->info(sprintf(
            'Load data ready: %d companies, %d customers (load001@load.test ...), 1 staff user (loadstaff@load.test), 1 admin user (loadadmin@load.test), %d products, %d orders, %d quotes, %d invoices, %d audit rows.',
            $companies, $users, $products, $orders, $counts['quotes'], $counts['invoices'], $counts['audit'],
        ));

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }

    private function companies(int $n): void
    {
        // A billing address and tax id: invoices copy them into their buyer snapshot.
        DB::statement("INSERT INTO companies (name, billing_address, tax_id, created_at, updated_at)
            SELECT 'Load Co ' || lpad(g::text, 3, '0'), 'Load Street ' || g || E'\n' || (10000 + g) || ' Loadtown', 'LOAD-TAX-' || lpad(g::text, 3, '0'), now(), now()
            FROM generate_series(1, ?) g", [$n]);
    }

    private function users(int $n, int $companies, string $hash): void
    {
        DB::statement("INSERT INTO users (name, email, password, email_verified_at, company_id, created_at, updated_at)
            SELECT 'Load Customer ' || lpad(g::text, 3, '0'), 'load' || lpad(g::text, 3, '0') || '@load.test', ?, now(),
                   (SELECT id FROM companies ORDER BY id OFFSET ((g - 1) % ?) LIMIT 1), now(), now()
            FROM generate_series(1, ?) g", [$hash, $companies, $n]);
        DB::statement("INSERT INTO users (name, email, password, email_verified_at, created_at, updated_at)
            VALUES ('Load Staff', 'loadstaff@load.test', ?, now(), now(), now()), ('Load Admin', 'loadadmin@load.test', ?, now(), now(), now())", [$hash, $hash]);

        foreach (['customer' => "email ~ '^load[0-9]+@load.test$'", 'staff' => "email = 'loadstaff@load.test'", 'admin' => "email = 'loadadmin@load.test'"] as $role => $where) {
            DB::statement("INSERT INTO model_has_roles (role_id, model_type, model_id)
                SELECT (SELECT id FROM roles WHERE name = ? AND guard_name = 'web'), ?, id FROM users WHERE {$where}", [$role, User::class]);
        }
    }

    /** Part numbers like 6205-2RS, NU104-ZZ, 30105 (family plus a running 2 to 3 digit size code, so fragments such as 6205 or NU match); 40 brands, 4 materials, mixed stock, 3% price on request, 2% inactive. */
    private function products(int $n): void
    {
        DB::statement("INSERT INTO products (category_id, part_number, name, brand, inner_diameter_mm, outer_diameter_mm, width_mm, material, seal_type,
                price_cents, price_on_request, stock_status, active, created_at, updated_at)
            SELECT (SELECT id FROM categories ORDER BY id OFFSET (g % (SELECT count(*) FROM categories)) LIMIT 1),
                   pn, 'Bearing ' || pn, 'Brand ' || (g % 40),
                   inner_mm, inner_mm + 10 + (g * 13 % 90), 4 + (g % 40),
                   (ARRAY['Chrome steel', 'Stainless steel', 'Ceramic', 'Brass'])[1 + g % 4],
                   (ARRAY['open', '2rs', 'zz'])[1 + g % 3],
                   CASE WHEN g % 33 = 0 THEN NULL ELSE 500 + (g * 37 % 90000) END,
                   g % 33 = 0,
                   (ARRAY['in_stock', 'low_stock', 'out_of_stock', 'on_order'])[1 + g % 4],
                   g % 50 <> 0, now(), now()
            FROM (SELECT g, 5 + (g * 7 % 120) AS inner_mm,
                         (ARRAY['62', '63', '60', '32', 'NU', '16', '72', '22', '30', '51', 'NJ', 'NA'])[1 + g % 12] || (CASE WHEN g <= 108 THEN '0' ELSE '' END) || ((g - 1) / 12 + 1)::text
                             || (ARRAY['', '-2RS', '-ZZ', '-C3'])[1 + g % 4] AS pn
                  FROM generate_series(1, ?) g) s", [$n]);
    }

    /** CTE of the priced products with a gapless row number, to join generated lines on (a plain hash-joinable equality). */
    private const PRICED_PRODUCTS = 'WITH p AS (SELECT id, part_number, name, brand, price_cents, row_number() OVER (ORDER BY id) AS rn FROM products WHERE price_cents IS NOT NULL) ';

    private function pricedProductCount(): int
    {
        return max(1, DB::table('products')->whereNotNull('price_cents')->count());
    }

    /** Orders over the last year of the generated customers, about three lines each (consecutive priced products), totals summed from the lines. */
    private function orders(int $n, int $customers): void
    {
        DB::statement("INSERT INTO orders (order_number, company_id, created_by_user_id, status, currency, po_reference, subtotal_cents, total_cents, created_at, updated_at)
            SELECT 'ORD-' || translate(substr(h, 1, 5), '0123456789abcdef', 'ABCDEFGHJKMNPQRS') || '-' || translate(substr(h, 6, 5), '0123456789abcdef', 'ABCDEFGHJKMNPQRS'),
                   u.company_id, u.id,
                   (ARRAY['pending', 'confirmed', 'shipped', 'delivered', 'delivered', 'cancelled', 'confirmed', 'delivered', 'pending', 'shipped'])[1 + g % 10],
                   ?,
                   CASE WHEN g % 10 < 7 THEN (ARRAY['PO-' || u.company_id || '-' || g, 'PO/' || extract(year FROM ts)::int || '/' || lpad(g::text, 6, '0'),
                        'Load PO ' || u.company_id || ' ' || g, 'PROJECT-BEARINGS-RESTOCK-' || g])[1 + (g / 10) % 4] END,
                   0, 0, ts, ts
            FROM (SELECT g, lpad(to_hex(g), 10, '0') AS h, now() - (g % 365) * interval '1 day' - (g % 86400) * interval '1 second' AS ts
                  FROM generate_series(1, ?) g) s
            JOIN (SELECT id, company_id, row_number() OVER (ORDER BY id) AS rn FROM users WHERE company_id IS NOT NULL) u ON u.rn = 1 + s.g % ?",
            [config('catalog.currency'), $n, $customers]);

        // The product count is resolved once here: a per-row count(*) OVER () in the join condition forced a nested loop (hours at 500,000 orders).
        DB::statement(self::PRICED_PRODUCTS.'INSERT INTO order_items (order_id, product_id, part_number, name, brand, unit_price_cents, quantity, line_total_cents, created_at, updated_at)
            SELECT o.id, p.id, p.part_number, p.name, p.brand, p.price_cents, 1 + (o.id + k) % 20, p.price_cents * (1 + (o.id + k) % 20), o.created_at, o.created_at
            FROM orders o CROSS JOIN generate_series(0, 2) k
            JOIN p ON p.rn = 1 + (o.id * 3 + k) % ?', [$this->pricedProductCount()]);

        DB::statement('UPDATE orders SET subtotal_cents = s.total, total_cents = s.total
            FROM (SELECT order_id, sum(line_total_cents) AS total FROM order_items GROUP BY order_id) s WHERE s.order_id = orders.id');

        DB::statement('INSERT INTO order_status_history (order_id, from_status, to_status, actor_user_id, created_at)
            SELECT id, NULL, \'pending\', created_by_user_id, created_at FROM orders');
        DB::statement("INSERT INTO order_status_history (order_id, from_status, to_status, actor_user_id, created_at)
            SELECT o.id, 'pending', o.status, (SELECT id FROM users WHERE email = 'loadstaff@load.test'), o.created_at + interval '1 hour'
            FROM orders o WHERE o.status <> 'pending'");
    }

    /** The staff user's id (author of every status change the generated history attributes to staff). */
    private function staffId(): int
    {
        return (int) DB::table('users')->where('email', 'loadstaff@load.test')->value('id');
    }

    /**
     * Quotes over the last year, mixed statuses (a quoted quote is valid for days still or already past its date: the effective-expired predicate),
     * two or three lines each (priced only once sent), a history chain per status, totals summed from the lines. No attachments.
     */
    private function quotes(int $n, int $customers): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO quote_requests (quote_number, company_id, created_by_user_id, status, currency, po_reference, customer_note, valid_until, sent_at, revision,
                subtotal_cents, total_cents, created_at, updated_at)
            SELECT 'QUO-' || translate(substr(s.h, 1, 5), '0123456789abcdef', 'ABCDEFGHJKMNPQRS') || '-' || translate(substr(s.h, 6, 5), '0123456789abcdef', 'ABCDEFGHJKMNPQRS'),
                   u.company_id, u.id, s.st, ?, CASE WHEN s.g % 3 = 0 THEN 'PO-Q-' || s.g END, 'Load test request ' || s.g,
                   CASE s.st WHEN 'quoted' THEN CASE WHEN s.g % 5 < 3 THEN current_date + (s.g % 30 + 1) ELSE current_date - (s.g % 20 + 1) END
                             WHEN 'accepted' THEN current_date + 5 WHEN 'expired' THEN current_date - (s.g % 60 + 1) END,
                   CASE WHEN s.st IN ('quoted', 'accepted', 'expired') THEN s.ts + interval '2 hours' END,
                   CASE WHEN s.st IN ('quoted', 'accepted', 'expired') THEN 1 ELSE 0 END,
                   CASE WHEN s.st IN ('quoted', 'accepted', 'expired') THEN 0 END, CASE WHEN s.st IN ('quoted', 'accepted', 'expired') THEN 0 END, s.ts, s.ts
            FROM (SELECT g, lpad(to_hex(g), 10, '0') AS h, now() - (g % 365) * interval '1 day' - (g % 86400) * interval '1 second' AS ts,
                         (ARRAY['submitted', 'submitted', 'in_review', 'in_review', 'quoted', 'quoted', 'quoted', 'accepted', 'declined', 'expired'])[1 + g % 10] AS st
                  FROM generate_series(1, ?) g) s
            JOIN (SELECT id, company_id, row_number() OVER (ORDER BY id) AS rn FROM users WHERE company_id IS NOT NULL) u ON u.rn = 1 + s.g % ?
            SQL, [config('catalog.currency'), $n, $customers]);

        DB::statement(self::PRICED_PRODUCTS.<<<'SQL'
            , l AS (
                SELECT q.id AS qid, q.status, q.created_at, k + 1 AS pos, p.id AS product_id, p.part_number, p.name, p.brand, 1 + (q.id + k) % 20 AS qty, p.price_cents AS price
                FROM quote_requests q CROSS JOIN generate_series(0, 1) k JOIN p ON p.rn = 1 + (q.id * 3 + k) % ?
                UNION ALL
                SELECT q.id, q.status, q.created_at, 3, NULL, NULL, 'Custom bearing, drawing on request', NULL, 2 + q.id % 9, 1000 + (q.id % 500) * 10 FROM quote_requests q WHERE q.id % 2 = 0)
            INSERT INTO quote_items (quote_request_id, position, product_id, part_number, name, brand, quantity, unit_price_cents, line_total_cents, created_at, updated_at)
            SELECT qid, pos, product_id, part_number, name, brand, qty,
                   CASE WHEN status IN ('quoted', 'accepted', 'expired') THEN price END,
                   CASE WHEN status IN ('quoted', 'accepted', 'expired') THEN price * qty END, created_at, created_at
            FROM l
            SQL, [$this->pricedProductCount()]);

        DB::statement("UPDATE quote_requests SET subtotal_cents = s.total, total_cents = s.total
            FROM (SELECT quote_request_id, sum(line_total_cents) AS total FROM quote_items GROUP BY quote_request_id) s
            WHERE s.quote_request_id = quote_requests.id AND quote_requests.status IN ('quoted', 'accepted', 'expired')");

        // One row per step of the chain that leads to the quote's status; the system expiry has no actor.
        DB::statement(<<<'SQL'
            INSERT INTO quote_status_history (quote_request_id, from_status, to_status, actor_user_id, created_at)
            SELECT q.id, t.f, t.t, CASE t.who WHEN 'c' THEN q.created_by_user_id WHEN 's' THEN ?::bigint END, q.created_at + t.step * interval '1 hour'
            FROM quote_requests q
            JOIN (VALUES (1, NULL::text, 'submitted', 'c'), (2, 'submitted', 'in_review', 's'), (3, 'in_review', 'quoted', 's'),
                         (4, 'quoted', 'accepted', 'c'), (5, 'quoted', 'expired', 'n'), (6, 'submitted', 'declined', 'c')) AS t(step, f, t, who)
              ON t.step = ANY (CASE q.status WHEN 'submitted' THEN ARRAY[1] WHEN 'in_review' THEN ARRAY[1, 2] WHEN 'quoted' THEN ARRAY[1, 2, 3]
                                             WHEN 'accepted' THEN ARRAY[1, 2, 3, 4] WHEN 'expired' THEN ARRAY[1, 2, 3, 5] WHEN 'declined' THEN ARRAY[1, 6] END)
            SQL, [$this->staffId()]);
    }

    /**
     * Invoices for the first $n invoiceable orders: numbers 1..N per issue year with no gap (counters aligned afterwards), dates and statuses
     * mixed (open ones issued long ago are effectively overdue, stored overdue ones are past due, paid and void ones follow), items copied from the order lines,
     * status history. Written in their FINAL state in one INSERT: the immutability triggers forbid later edits.
     */
    private function invoices(int $n): void
    {
        $seller = json_encode([
            'name' => 'Load Seller GmbH', 'address_lines' => ['Seller Street 1', '10115 Sellertown'], 'tax_id' => 'LOAD-SELLER-TAX', 'email' => 'billing@load.test',
            'phone' => '+49 30 000000', 'bank' => ['account_holder' => 'Load Seller GmbH', 'bank_name' => 'Load Bank', 'iban' => 'XX00 LOAD 0000', 'bic' => 'LOADBIC'],
            'payment_terms_text' => 'Pay by bank transfer.', 'footer' => 'Load Seller GmbH, registered office Sellertown.', 'vat_label' => 'VAT',
        ], JSON_THROW_ON_ERROR);

        DB::statement(<<<'SQL'
            INSERT INTO invoices (public_id, number, number_year, number_seq, company_id, order_id, order_number, created_by_user_id, status, issue_date, due_date, paid_on,
                currency, po_reference, vat_rate_bp, subtotal_cents, tax_cents, total_cents, seller, buyer, created_at, updated_at)
            SELECT gen_random_uuid(), 'INV-' || d.yr || '-' || lpad(d.seq::text, 6, '0'), d.yr, d.seq, d.company_id, d.order_id, d.order_number, ?::bigint,
                   CASE d.slot WHEN 'paid' THEN 'paid' WHEN 'void' THEN 'void' WHEN 'overdue' THEN CASE WHEN d.issue_date + 30 < current_date THEN 'overdue' ELSE 'open' END ELSE 'open' END,
                   d.issue_date, d.issue_date + 30, CASE WHEN d.slot = 'paid' THEN LEAST(d.issue_date + (d.order_id % 25)::int, current_date) END,
                   d.currency, d.po_reference, d.vat, d.subtotal_cents, (d.subtotal_cents * d.vat + 5000) / 10000, d.subtotal_cents + (d.subtotal_cents * d.vat + 5000) / 10000,
                   ?::jsonb, jsonb_build_object('name', c.name, 'address_lines', to_jsonb(string_to_array(c.billing_address, E'\n')), 'tax_id', c.tax_id),
                   d.issue_date + interval '9 hours', d.issue_date + interval '9 hours'
            FROM (SELECT p.*, extract(year FROM p.issue_date)::int AS yr,
                         row_number() OVER (PARTITION BY extract(year FROM p.issue_date) ORDER BY p.issue_date, p.order_id) AS seq,
                         (ARRAY['open', 'open', 'open', 'open', 'overdue', 'overdue', 'paid', 'paid', 'paid', 'void'])[1 + p.n % 10] AS slot,
                         CASE WHEN p.order_id % 2 = 0 THEN 1900 ELSE 700 END AS vat
                  FROM (SELECT o.id AS order_id, o.order_number, o.company_id, o.po_reference, o.subtotal_cents, o.currency,
                               LEAST(o.created_at::date + (o.id % 3)::int, current_date) AS issue_date, row_number() OVER (ORDER BY o.id) AS n
                        FROM orders o WHERE o.status IN ('confirmed', 'shipped', 'delivered') ORDER BY o.id LIMIT ?) p) d
            JOIN companies c ON c.id = d.company_id
            SQL, [$this->staffId(), $seller, $n]);

        // Gap-free: the counter of a year is its highest number.
        DB::statement('INSERT INTO invoice_number_counters (year, last_value, updated_at) SELECT number_year, max(number_seq), now() FROM invoices GROUP BY number_year');

        DB::statement('INSERT INTO invoice_items (invoice_id, position, part_number, name, brand, quantity, unit_price_cents, line_total_cents, created_at)
            SELECT i.id, row_number() OVER (PARTITION BY i.id ORDER BY oi.id), oi.part_number, oi.name, oi.brand, oi.quantity, oi.unit_price_cents, oi.line_total_cents, i.created_at
            FROM invoices i JOIN order_items oi ON oi.order_id = i.order_id');

        DB::statement(<<<'SQL'
            INSERT INTO invoice_status_history (invoice_id, from_status, to_status, actor_user_id, note, created_at)
            SELECT id, NULL::text, 'open', ?::bigint, NULL::text, created_at FROM invoices
            UNION ALL SELECT id, 'open', 'paid', ?::bigint, NULL, paid_on::timestamp + interval '10 hours' FROM invoices WHERE status = 'paid'
            UNION ALL SELECT id, 'open', 'overdue', NULL, NULL, due_date::timestamp + interval '1 day' FROM invoices WHERE status = 'overdue'
            UNION ALL SELECT id, 'open', 'void', ?::bigint, 'Issued in error', created_at + interval '1 day' FROM invoices WHERE status = 'void'
            SQL, [$this->staffId(), $this->staffId(), $this->staffId()]);
    }

    /**
     * Audit rows spread over 12 months with valid actions, subject types and actors (the CHECKs hold), pointing at real rows of the generated data.
     * Kinds whose subject table is empty are left out.
     */
    private function audit(int $n, int $companies, int $customers, int $products): void
    {
        $orders = DB::table('orders')->count();
        $quotes = DB::table('quote_requests')->count();
        $invoices = DB::table('invoices')->count();
        $staff = $this->staffId();
        $admin = (int) DB::table('users')->where('email', 'loadadmin@load.test')->value('id');

        // [action, subject type, actor role, needs rows of]; repeated entries weight the mix.
        $kinds = array_filter([
            ['order.status_changed', 'order', 'staff', $orders], ['order.status_changed', 'order', 'staff', $orders], ['order.status_changed', 'order', 'staff', $orders],
            ['quote.sent', 'quote', 'staff', $quotes], ['quote.sent', 'quote', 'staff', $quotes], ['quote.reviewed', 'quote', 'staff', $quotes], ['quote.accepted', 'quote', 'customer', $quotes],
            ['invoice.created', 'invoice', 'staff', $invoices], ['invoice.created', 'invoice', 'staff', $invoices], ['invoice.paid', 'invoice', 'staff', $invoices], ['invoice.paid', 'invoice', 'staff', $invoices],
            ['product.updated', 'product', 'staff', 1], ['product.updated', 'product', 'staff', 1], ['product.updated', 'product', 'staff', 1], ['product.created', 'product', 'staff', 1],
            ['company.updated', 'company', 'admin', 1], ['user.invited', 'user', 'admin', 1], ['user.updated', 'user', 'admin', 1], ['user.deactivated', 'user', 'admin', 1],
        ], fn (array $k) => $k[3] > 0);
        $kinds = array_values($kinds);
        $values = implode(', ', array_map(fn (array $k, int $i) => sprintf("(%d, '%s', '%s', '%s')", $i, $k[0], $k[1], $k[2]), $kinds, array_keys($kinds)));
        $kindCount = count($kinds);
        $users = $customers + 2;

        // Every number below is an integer computed here: nothing is interpolated from outside.
        DB::statement(<<<SQL
            INSERT INTO audit_log (occurred_at, actor_user_id, actor_role, action, subject_type, subject_id, subject_label, company_id, changes, metadata)
            SELECT now() - (s.g % 365) * interval '1 day' - ((s.g * 7919) % 86400) * interval '1 second',
                   CASE k.role WHEN 'staff' THEN {$staff} WHEN 'admin' THEN {$admin} ELSE 1 + s.g % {$customers} END, k.role, k.action, k.subject_type,
                   CASE k.subject_type
                       WHEN 'order' THEN (SELECT order_number FROM orders WHERE id = 1 + s.g % GREATEST({$orders}, 1))
                       WHEN 'quote' THEN (SELECT quote_number FROM quote_requests WHERE id = 1 + s.g % GREATEST({$quotes}, 1))
                       WHEN 'invoice' THEN (SELECT number FROM invoices WHERE id = 1 + s.g % GREATEST({$invoices}, 1))
                       WHEN 'product' THEN (1 + s.g % {$products})::text WHEN 'company' THEN (1 + s.g % {$companies})::text ELSE (1 + s.g % {$users})::text END,
                   NULL,
                   CASE k.subject_type
                       WHEN 'order' THEN (SELECT company_id FROM orders WHERE id = 1 + s.g % GREATEST({$orders}, 1))
                       WHEN 'quote' THEN (SELECT company_id FROM quote_requests WHERE id = 1 + s.g % GREATEST({$quotes}, 1))
                       WHEN 'invoice' THEN (SELECT company_id FROM invoices WHERE id = 1 + s.g % GREATEST({$invoices}, 1))
                       WHEN 'company' THEN 1 + s.g % {$companies} END,
                   CASE k.action
                       WHEN 'order.status_changed' THEN jsonb_build_object('before', jsonb_build_object('status', 'pending'), 'after', jsonb_build_object('status', 'confirmed'))
                       WHEN 'invoice.paid' THEN jsonb_build_object('before', jsonb_build_object('status', 'open'), 'after', jsonb_build_object('status', 'paid'))
                       WHEN 'product.updated' THEN jsonb_build_object('before', jsonb_build_object('price_cents', 100 + s.g % 900), 'after', jsonb_build_object('price_cents', 120 + s.g % 900))
                       ELSE '{}'::jsonb END,
                   '{}'::jsonb
            FROM generate_series(1, {$n}) s(g)
            JOIN (VALUES {$values}) AS k(idx, action, subject_type, role) ON k.idx = s.g % {$kindCount}
            SQL);
    }
}
