import { Link } from 'react-router-dom';
import PageTitle from '../../components/PageTitle';
import { formatMoney } from '../../lib/format';
import CatalogError from '../catalog/CatalogError';
import { useDashboard } from './api';

function Card({
  to,
  label,
  value,
  sub,
}: {
  to: string;
  label: string;
  value: number;
  sub?: string;
}) {
  return (
    <li>
      <Link
        to={to}
        className="block h-full rounded border border-slate-400 p-4 hover:bg-slate-50"
      >
        <span className="block text-sm font-semibold">{label}</span>
        <span className="block text-3xl font-semibold">{value}</span>
        {sub && <span className="block text-sm">{sub}</span>}
      </Link>
    </li>
  );
}

export default function DashboardPage() {
  const q = useDashboard();
  const d = q.data;
  let body;
  if (q.isError && !d) {
    body = <CatalogError error={q.error} onRetry={() => void q.refetch()} />;
  } else if (!d) {
    body = (
      <div
        role="status"
        aria-busy="true"
        aria-label="Loading dashboard"
        className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
      >
        {[0, 1, 2, 3, 4, 5, 6, 7].map((i) => (
          <div key={i} className="h-24 animate-pulse rounded bg-slate-200" />
        ))}
      </div>
    );
  } else {
    body = (
      <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Card
          to="/admin/quotes?status=submitted,in_review"
          label="Open quotes"
          value={d.quotes.open}
        />
        <Card
          to="/admin/quotes?status=quoted"
          label="Awaiting customer"
          value={d.quotes.awaiting_customer}
        />
        <Card
          to="/admin/quotes?status=quoted&expiring_within_days=7"
          label="Quotes expiring soon"
          value={d.quotes.expiring_soon}
        />
        <Card
          to={`/admin/orders?from=${d.week_start}`}
          label="Orders this week"
          value={d.orders.this_week}
        />
        <Card
          to="/admin/orders?status=pending"
          label="Pending orders"
          value={d.orders.pending}
        />
        <Card
          to="/admin/invoices?status=overdue"
          label="Overdue invoices"
          value={d.invoices.overdue_count}
          sub={formatMoney(d.invoices.overdue_total_cents, d.currency)}
        />
        <Card
          to="/admin/invoices?status=open&due_within_days=7"
          label="Invoices due soon"
          value={d.invoices.due_soon_count}
          sub={formatMoney(d.invoices.due_soon_total_cents, d.currency)}
        />
        <Card
          to="/admin/products?active=false"
          label="Inactive products"
          value={d.products.inactive}
        />
      </ul>
    );
  }
  return (
    <section>
      <PageTitle>Dashboard</PageTitle>
      {body}
      {d && (
        <p className="mt-4 text-sm text-slate-700">
          Week starting {d.week_start}. Updated {d.generated_at}.
        </p>
      )}
    </section>
  );
}
