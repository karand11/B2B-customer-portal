<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use App\Support\CatalogCache;
use App\Support\Mm;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of single products (the CSV importer upserts in bulk). Every change runs in one transaction with its audit row,
 * under a row lock for updates, and flushes the catalog cache once the transaction has finished.
 * Existing orders, quotes and invoices keep their own snapshot lines, so none of this touches them.
 */
class ProductAdmin
{
    /** @param  array<string, mixed>  $attributes  server-built columns (ProductWriteRequest::productAttributes()) */
    public function create(User $actor, array $attributes, bool $active): Product
    {
        $product = $this->guardUnique(fn () => DB::transaction(function () use ($actor, $attributes, $active) {
            $product = (new Product)->forceFill($attributes + ['active' => $active]);
            $product->save();
            $product->refresh();

            $this->audit('product.created', $product, $actor, [], $this->values($product), ['description_changed' => $product->description !== null]);

            return $product;
        }));

        CatalogCache::flush();

        return $product->load('category:id,slug,name');
    }

    /** @param  array<string, mixed>  $attributes  server-built columns; a stale `$version` is a 409 and nothing is written */
    public function update(User $actor, Product $product, array $attributes, int $version): Product
    {
        $updated = $this->guardUnique(fn () => DB::transaction(function () use ($actor, $product, $attributes, $version) {
            $locked = $this->lock($product, $version);
            $before = $this->values($locked);
            $descriptionBefore = $locked->description;

            $locked->forceFill($attributes);
            if (! $locked->isDirty()) {
                return $locked; // a no-op PUT: no write, no version bump, no audit row
            }

            $locked->save();
            $locked->refresh();
            $this->audit('product.updated', $locked, $actor, $before, $this->values($locked), ['description_changed' => $descriptionBefore !== $locked->description]);

            return $locked;
        }));

        CatalogCache::flush();

        return $updated->load('category:id,slug,name');
    }

    /** Idempotent: already in the requested state is a no-op (no audit row, no version bump). */
    public function setActive(User $actor, Product $product, bool $active): Product
    {
        $changed = DB::transaction(function () use ($actor, $product, $active) {
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($locked->active === $active) {
                return $locked;
            }

            $locked->forceFill(['active' => $active])->save();
            $locked->refresh();
            $this->audit($active ? 'product.activated' : 'product.deactivated', $locked, $actor, ['active' => ! $active], ['active' => $active]);

            return $locked;
        });

        CatalogCache::flush();

        return $changed->load('category:id,slug,name');
    }

    private function lock(Product $product, int $version): Product
    {
        $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

        if ($locked->version !== $version) {
            $message = 'This record was changed by someone else. Reload it and try again.';

            throw ValidationException::withMessages(['version' => $message])->status(409);
        }

        return $locked;
    }

    /** The unique index on part_number_search is the backstop for a race the Form Request cannot see: same 422 as the friendly check. */
    private function guardUnique(\Closure $write): Product
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'products_part_number_search_unique')) {
                throw $e;
            }

            throw ValidationException::withMessages(['part_number' => 'This part number is already in use.']);
        }
    }

    /** @return array<string, mixed> the audited columns of a product (millimetres as numbers, as the API shows them) */
    private function values(Product $product): array
    {
        return self::auditValues($product->attributesToArray());
    }

    /**
     * The audited columns of a product row or of validated import values (shared with the CSV import, so both write the same shape).
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public static function auditValues(array $a): array
    {
        return [
            'part_number' => $a['part_number'], 'name' => $a['name'], 'brand' => $a['brand'], 'category_id' => $a['category_id'],
            'inner_diameter_mm' => Mm::number($a['inner_diameter_mm']), 'outer_diameter_mm' => Mm::number($a['outer_diameter_mm']),
            'width_mm' => Mm::number($a['width_mm']), 'material' => $a['material'], 'seal_type' => $a['seal_type'],
            'price_cents' => $a['price_cents'], 'price_on_request' => $a['price_on_request'],
            'stock_status' => $a['stock_status'], 'active' => $a['active'],
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    private function audit(string $action, Product $product, User $actor, array $before, array $after, array $metadata = []): void
    {
        AuditLog::record($action, 'product', (string) $product->id, $product->part_number, $actor, $before, $after, $metadata);
    }
}
