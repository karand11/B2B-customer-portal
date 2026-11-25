import { useEffect, useRef, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { ApiError } from '../../lib/api';
import { useCategories, useFilters, useProducts } from './api';
import CatalogError from './CatalogError';
import FilterSidebar from './FilterSidebar';
import { SEAL_LABELS, SORT_LABELS, STOCK_LABELS } from './format';
import Pagination from './Pagination';
import ProductTable, { SkeletonRows } from './ProductTable';
import {
  EMPTY_QUERY,
  RANGES,
  SORTS,
  hasFilters,
  type CatalogQuery,
  type ListKey,
  type RangeBase,
} from './urlState';
import { useCatalogQuery } from './useCatalogQuery';

const LIST_LABELS: Record<ListKey, string> = {
  category: 'Category',
  brand: 'Brand',
  material: 'Material',
  seal_type: 'Seal type',
  stock_status: 'Stock status',
};

function sortLabel(s: string) {
  const desc = s.startsWith('-');
  const base = SORT_LABELS[desc ? s.slice(1) : s];
  return `${base} (${desc ? 'descending' : 'ascending'})`;
}

export default function CatalogPage() {
  const { query, raw, update } = useCatalogQuery();
  const { search } = useLocation();
  const products = useProducts(query);
  const filters = useFilters();
  const categories = useCategories();
  const [filtersOpen, setFiltersOpen] = useState(false);
  const headingRef = useRef<HTMLHeadingElement>(null);

  const list = products.data;
  const clear = () => update(EMPTY_QUERY);

  // A page past the end (hand-edited URL, shrunk catalog) jumps to the last page.
  const overshoot =
    list &&
    !products.isPlaceholderData &&
    list.data.length === 0 &&
    list.meta.total > 0
      ? list.meta.last_page
      : null;
  useEffect(() => {
    if (overshoot && query.page > overshoot) update({ page: overshoot }, true);
  }, [overshoot, query.page, update]);

  const catName = (slug: string) =>
    categories.data?.find((c) => c.slug === slug)?.name ?? slug;
  const valueLabel = (k: ListKey, v: string) =>
    k === 'category'
      ? catName(v)
      : k === 'seal_type'
        ? (SEAL_LABELS[v] ?? v)
        : k === 'stock_status'
          ? (STOCK_LABELS[v] ?? v)
          : v;

  const chips: { label: string; patch: Partial<CatalogQuery> }[] = [];
  if (query.q)
    chips.push({ label: `Part number: ${query.q}`, patch: { q: '' } });
  for (const k of Object.keys(LIST_LABELS) as ListKey[])
    for (const v of query[k])
      chips.push({
        label: `${LIST_LABELS[k]}: ${valueLabel(k, v)}`,
        patch: { [k]: query[k].filter((x) => x !== v) },
      });
  for (const { base, label } of RANGES) {
    const min = query.ranges[`${base}_min`];
    const max = query.ranges[`${base}_max`];
    if (!min && !max) continue;
    const rest = { ...query.ranges };
    delete rest[`${base}_min`];
    delete rest[`${base}_max`];
    const span =
      min && max ? `${min} to ${max}` : min ? `from ${min}` : `up to ${max}`;
    chips.push({ label: `${label}: ${span} mm`, patch: { ranges: rest } });
  }

  const setRange = (base: RangeBase, min: string, max: string) => {
    const ranges = { ...query.ranges };
    delete ranges[`${base}_min`];
    delete ranges[`${base}_max`];
    if (min) ranges[`${base}_min`] = min;
    if (max) ranges[`${base}_max`] = max;
    update({ ranges }, true);
  };

  const failed = products.isError
    ? products.error
    : filters.isError
      ? filters.error
      : null;
  const retry = () => {
    if (products.isError) void products.refetch();
    if (filters.isError) void filters.refetch();
  };
  const total = list?.meta.total;

  let body;
  if (failed) {
    body = (
      <CatalogError
        error={failed}
        onRetry={retry}
        onClear={
          failed instanceof ApiError && failed.status === 422
            ? clear
            : undefined
        }
      />
    );
  } else if (!list || !filters.data) {
    body = <SkeletonRows />;
  } else if (list.meta.total === 0) {
    body = hasFilters(query) ? (
      <div>
        <p>No products match your search.</p>
        <button
          type="button"
          onClick={clear}
          className="mt-2 rounded border border-slate-600 px-3 py-1"
        >
          Clear all filters
        </button>
      </div>
    ) : (
      <p>The catalog is empty.</p>
    );
  } else {
    body = (
      <>
        <ProductTable
          products={list.data}
          currency={filters.data.currency}
          search={search}
          busy={products.isPlaceholderData}
        />
        {list.meta.last_page > 1 && (
          <Pagination
            page={list.meta.current_page}
            lastPage={list.meta.last_page}
            onPage={(page) => {
              update({ page });
              headingRef.current?.focus();
            }}
          />
        )}
      </>
    );
  }

  return (
    <div className="grid gap-6 lg:grid-cols-[16rem_1fr]">
      <div>
        <button
          type="button"
          className="mb-2 rounded border border-slate-600 px-3 py-1 lg:hidden"
          aria-expanded={filtersOpen}
          aria-controls="catalog-filters"
          onClick={() => setFiltersOpen((o) => !o)}
        >
          Filters
        </button>
        <FilterSidebar
          id="catalog-filters"
          className={filtersOpen ? undefined : 'max-lg:hidden'}
          query={query}
          raw={raw}
          filters={filters.data}
          categories={{ data: categories.data, failed: categories.isError }}
          onList={(key, values) => update({ [key]: values })}
          onSearch={(q) => update({ q }, true)}
          onRange={setRange}
        />
        {hasFilters(query) && (
          <button
            type="button"
            onClick={clear}
            className="rounded border border-slate-600 px-3 py-1"
          >
            Clear all filters
          </button>
        )}
      </div>
      <section aria-labelledby="catalog-heading">
        <h1
          id="catalog-heading"
          ref={headingRef}
          tabIndex={-1}
          className="mb-2 text-2xl font-semibold"
        >
          Catalog
        </h1>
        <p role="status" aria-live="polite" className="mb-2 min-h-6 text-sm">
          {total !== undefined && !failed
            ? `${total} ${total === 1 ? 'product' : 'products'} found`
            : ''}
        </p>
        {chips.length > 0 && (
          <ul aria-label="Active filters" className="mb-3 flex flex-wrap gap-2">
            {chips.map((c) => (
              <li key={c.label}>
                <button
                  type="button"
                  aria-label={`Remove filter ${c.label}`}
                  onClick={() => update(c.patch)}
                  className="rounded-full border border-slate-600 px-3 py-0.5 text-sm"
                >
                  {c.label} <span aria-hidden="true">x</span>
                </button>
              </li>
            ))}
          </ul>
        )}
        <div className="mb-3">
          <label htmlFor="catalog-sort" className="mr-2 text-sm font-semibold">
            Sort by
          </label>
          <select
            id="catalog-sort"
            value={query.sort ?? ''}
            onChange={(e) => update({ sort: e.target.value || null })}
            className="rounded border border-slate-500 px-2 py-1"
          >
            <option value="">Default</option>
            {SORTS.map((s) => (
              <option key={s} value={s}>
                {sortLabel(s)}
              </option>
            ))}
          </select>
        </div>
        {body}
      </section>
    </div>
  );
}
