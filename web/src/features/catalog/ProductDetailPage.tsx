import { useEffect } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import { ApiError } from '../../lib/api';
import { useFilters, useProduct } from './api';
import CatalogError from './CatalogError';
import { SEAL_LABELS, STOCK_LABELS, formatPrice } from './format';

function NotFound() {
  return (
    <section>
      <h1 className="mb-2 text-2xl font-semibold">Product not found</h1>
      <p className="mb-2">
        This product does not exist or is no longer available.
      </p>
      <Link to="/catalog" className="text-blue-800 underline">
        Back to catalog
      </Link>
    </section>
  );
}

export default function ProductDetailPage() {
  const { id = '' } = useParams();
  const validId = /^\d{1,18}$/.test(id);
  const product = useProduct(id, validId);
  const filters = useFilters();
  const { state } = useLocation();
  const from = (state as { from?: unknown } | null)?.from;
  // Only a query string produced by the list page is honoured.
  const back = `/catalog${typeof from === 'string' && from.startsWith('?') ? from : ''}`;
  const partNumber = product.data?.part_number;

  useEffect(() => {
    if (!partNumber) return;
    const prev = document.title;
    document.title = partNumber;
    return () => {
      document.title = prev;
    };
  }, [partNumber]);

  if (!validId) return <NotFound />;
  if (product.error instanceof ApiError && product.error.status === 404)
    return <NotFound />;
  const failed = product.isError
    ? product.error
    : filters.isError
      ? filters.error
      : null;
  if (failed)
    return (
      <CatalogError
        error={failed}
        onRetry={() => {
          if (product.isError) void product.refetch();
          if (filters.isError) void filters.refetch();
        }}
      />
    );
  const p = product.data;
  if (!p || !filters.data)
    return (
      <div role="status" aria-busy="true" aria-label="Loading product">
        <div className="mb-2 h-8 w-1/2 animate-pulse rounded bg-slate-200" />
        <div className="h-32 animate-pulse rounded bg-slate-200" />
      </div>
    );

  const specs: [string, string][] = [
    ['Brand', p.brand],
    ['Type', p.category.name],
    ['Inner diameter', `${p.inner_diameter_mm} mm`],
    ['Outer diameter', `${p.outer_diameter_mm} mm`],
    ['Width', `${p.width_mm} mm`],
    ['Material', p.material],
    ['Seal type', SEAL_LABELS[p.seal_type] ?? p.seal_type],
    ['Stock', STOCK_LABELS[p.stock_status] ?? p.stock_status],
    ['Price', formatPrice(p, filters.data.currency)],
    ['Description', p.description ?? 'No description available.'],
  ];
  return (
    <section>
      <Link to={back} className="text-blue-800 underline">
        Back to results
      </Link>
      <h1 className="mt-2 text-2xl font-semibold">{p.part_number}</h1>
      <p className="mb-4">
        {p.name} ({p.brand}, {p.category.name})
      </p>
      <dl className="grid max-w-2xl grid-cols-[10rem_1fr] gap-x-4 gap-y-2">
        {specs.map(([k, v]) => (
          <div key={k} className="contents">
            <dt className="font-semibold">{k}</dt>
            <dd>{v}</dd>
          </div>
        ))}
      </dl>
    </section>
  );
}
