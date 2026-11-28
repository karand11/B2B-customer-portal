import { Link } from 'react-router-dom';
import { useAuth } from '../../auth/AuthContext';
import AddToCart from '../cart/AddToCart';
import type { Product } from './api';
import { SEAL_LABELS, STOCK_LABELS, formatPrice, formatSize } from './format';

const th = 'px-3 py-2 text-left text-sm font-semibold';
// Below md each cell becomes a labelled block (label from data-label, not in the DOM text).
const td =
  'px-3 py-2 text-sm max-md:block max-md:before:mr-2 max-md:before:font-medium max-md:before:content-[attr(data-label)]';

export function SkeletonRows() {
  return (
    <div aria-busy="true" role="status" aria-label="Loading products">
      {Array.from({ length: 6 }, (_, i) => (
        <div
          key={i}
          className="mb-2 h-8 animate-pulse motion-reduce:animate-none rounded bg-slate-200"
        />
      ))}
    </div>
  );
}

export default function ProductTable({
  products,
  currency,
  search,
  busy,
}: {
  products: Product[];
  currency: string;
  search: string;
  busy: boolean;
}) {
  const canOrder = useAuth().user?.role === 'customer';
  return (
    <div className={busy ? 'opacity-80' : undefined} aria-busy={busy}>
      <table className="w-full border-collapse">
        <caption className="sr-only">Product search results</caption>
        <thead className="max-md:sr-only">
          <tr className="border-b border-slate-400 bg-slate-100">
            <th scope="col" className={th}>
              Part number
            </th>
            <th scope="col" className={th}>
              Brand
            </th>
            <th scope="col" className={th}>
              Type
            </th>
            <th scope="col" className={th}>
              Size d x D x B (mm)
            </th>
            <th scope="col" className={th}>
              Seal
            </th>
            <th scope="col" className={th}>
              Stock
            </th>
            <th scope="col" className={th}>
              Price
            </th>
            {canOrder && (
              <th scope="col" className={th}>
                Order
              </th>
            )}
          </tr>
        </thead>
        <tbody>
          {products.map((p) => (
            <tr
              key={p.id}
              className="border-b border-slate-300 max-md:block max-md:py-2"
            >
              <td data-label="Part number" className={td}>
                <Link
                  to={`/catalog/${p.id}`}
                  state={{ from: search }}
                  className="text-blue-800 underline"
                >
                  {p.part_number}
                </Link>
              </td>
              <td data-label="Brand" className={td}>
                {p.brand}
              </td>
              <td data-label="Type" className={td}>
                {p.category.name}
              </td>
              <td data-label="Size" className={td}>
                {formatSize(p)}
              </td>
              <td data-label="Seal" className={td}>
                {SEAL_LABELS[p.seal_type] ?? p.seal_type}
              </td>
              <td data-label="Stock" className={td}>
                {STOCK_LABELS[p.stock_status] ?? p.stock_status}
              </td>
              <td data-label="Price" className={td}>
                {formatPrice(p, currency)}
              </td>
              {canOrder && (
                <td data-label="Order" className={td}>
                  <AddToCart product={p} />
                </td>
              )}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
