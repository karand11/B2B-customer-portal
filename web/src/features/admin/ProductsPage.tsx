import { Link } from 'react-router-dom';
import DataTable, { type Column } from '../../components/DataTable';
import PageTitle from '../../components/PageTitle';
import {
  enumParam,
  pageParam,
  sortParam,
  textParam,
  useListUrlState,
  type ListSpec,
} from '../../lib/listUrlState';
import { formatMoney } from '../../lib/format';
import { useCategories } from '../catalog/api';
import { STOCK_LABELS } from '../catalog/format';
import { SearchBox } from '../catalog/FilterSidebar';
import { useAdminProducts, type AdminProduct } from './api';
import { CURRENCY, fmtDay } from './format';
import { linkCls } from './forms';
import { STOCK_STATUSES } from './schemas';

const SORTS = [
  'part_number',
  'name',
  'brand',
  'price',
  'updated_at',
  '-part_number',
  '-name',
  '-brand',
  '-price',
  '-updated_at',
];

interface ProductQuery {
  q: string;
  active: '' | 'true' | 'false';
  category: string;
  brand: string;
  stock_status: '' | (typeof STOCK_STATUSES)[number];
  sort: string;
  page: number;
}
const SPEC: ListSpec<ProductQuery> = {
  q: textParam(64),
  active: enumParam(['true', 'false'] as const),
  category: {
    parse: (raw) =>
      raw !== null && /^[a-z0-9-]{1,64}$/.test(raw) ? raw : undefined,
    format: (v) => v,
    default: '',
  },
  brand: textParam(64),
  stock_status: enumParam(STOCK_STATUSES),
  sort: sortParam(SORTS, 'part_number'),
  page: pageParam,
};

const input = 'rounded border border-slate-500 px-2 py-1';
const lbl = 'mb-1 block text-sm font-semibold';

const COLUMNS: Column<AdminProduct>[] = [
  {
    key: 'part_number',
    header: 'Part number',
    sortKey: 'part_number',
    render: (p) => (
      <Link to={`/admin/products/${p.id}`} className={linkCls}>
        {p.part_number}
      </Link>
    ),
  },
  { key: 'name', header: 'Name', sortKey: 'name', render: (p) => p.name },
  { key: 'brand', header: 'Brand', sortKey: 'brand', render: (p) => p.brand },
  { key: 'category', header: 'Category', render: (p) => p.category.name },
  {
    key: 'price',
    header: 'Price',
    sortKey: 'price',
    render: (p) =>
      p.price_on_request
        ? 'Price on request'
        : formatMoney(p.price_cents, CURRENCY),
  },
  {
    key: 'stock',
    header: 'Stock',
    render: (p) => STOCK_LABELS[p.stock_status] ?? p.stock_status,
  },
  {
    key: 'status',
    header: 'Status',
    render: (p) =>
      p.active ? (
        'Active'
      ) : (
        <span className="inline-block rounded border border-slate-600 bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-800">
          Inactive
        </span>
      ),
  },
  {
    key: 'updated_at',
    header: 'Updated',
    sortKey: 'updated_at',
    render: (p) => fmtDay(p.updated_at),
  },
];

export default function ProductsPage() {
  const { values, search, set, hasFilters, clear } = useListUrlState(SPEC);
  const list = useAdminProducts(search);
  const categories = useCategories();
  return (
    <section>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <PageTitle>Products</PageTitle>
        <div className="mb-4 flex gap-2">
          <Link
            to="/admin/products/new"
            className="rounded bg-blue-700 px-3 py-1 text-white"
          >
            New product
          </Link>
          <Link
            to="/admin/products/import"
            className="rounded border border-slate-600 px-3 py-1"
          >
            Import CSV
          </Link>
        </div>
      </div>
      <div className="mb-4 flex flex-wrap items-end gap-4">
        <SearchBox
          id="products-q"
          label="Part number"
          hint="e.g. 6205 or 6205-2RS"
          value={values.q}
          onCommit={(q) => set({ q }, true)}
        />
        <div className="mb-4">
          <label htmlFor="products-active" className={lbl}>
            Status
          </label>
          <select
            id="products-active"
            value={values.active}
            onChange={(e) =>
              set({ active: e.target.value as ProductQuery['active'] })
            }
            className={input}
          >
            <option value="">All products</option>
            <option value="true">Active only</option>
            <option value="false">Inactive only</option>
          </select>
        </div>
        <div className="mb-4">
          <label htmlFor="products-category" className={lbl}>
            Category
          </label>
          <select
            id="products-category"
            value={values.category}
            onChange={(e) => set({ category: e.target.value })}
            className={input}
          >
            <option value="">All categories</option>
            {values.category &&
              !categories.data?.some((c) => c.slug === values.category) && (
                <option value={values.category}>{values.category}</option>
              )}
            {categories.data?.map((c) => (
              <option key={c.slug} value={c.slug}>
                {c.name}
              </option>
            ))}
          </select>
        </div>
        <SearchBox
          id="products-brand"
          label="Brand (exact)"
          hint="e.g. SKF"
          value={values.brand}
          onCommit={(brand) => set({ brand }, true)}
        />
        <div className="mb-4">
          <label htmlFor="products-stock" className={lbl}>
            Stock
          </label>
          <select
            id="products-stock"
            value={values.stock_status}
            onChange={(e) =>
              set({
                stock_status: e.target.value as ProductQuery['stock_status'],
              })
            }
            className={input}
          >
            <option value="">Any</option>
            {STOCK_STATUSES.map((s) => (
              <option key={s} value={s}>
                {STOCK_LABELS[s]}
              </option>
            ))}
          </select>
        </div>
        {hasFilters && (
          <button
            type="button"
            onClick={clear}
            className="mb-4 rounded border border-slate-600 px-3 py-1"
          >
            Clear filters
          </button>
        )}
      </div>
      <DataTable
        caption="Products"
        columns={COLUMNS}
        rows={list.data?.data}
        rowKey={(p) => p.id}
        sort={values.sort}
        defaultSort={SPEC.sort.default}
        onSortChange={(sort) => set({ sort })}
        page={values.page}
        lastPage={list.data?.meta.last_page ?? 1}
        perPage={list.data?.meta.per_page}
        total={list.data?.meta.total}
        onPageChange={(page) => set({ page })}
        isLoading={list.isPending}
        isPlaceholder={list.isPlaceholderData}
        error={list.error}
        onRetry={() => void list.refetch()}
        emptyMessage="No products yet."
        hasFilters={hasFilters}
        onClearFilters={clear}
        noun={['product', 'products']}
      />
    </section>
  );
}
