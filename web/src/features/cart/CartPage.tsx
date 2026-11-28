import ScrollRegion from '../../components/ScrollRegion';
import { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import ConfirmDialog from '../../components/ConfirmDialog';
import { ApiError, describeError } from '../../lib/api';
import { formatMoney } from '../../lib/format';
import CatalogError from '../catalog/CatalogError';
import {
  QUANTITY_ERROR,
  parseQuantity,
  useCart,
  useClearCart,
  useRemoveCartItem,
  useUpdateCartItem,
  type CartLine,
} from './api';
import { REASON_LABELS } from './labels';

const th = 'px-3 py-2 text-left text-sm font-semibold';
const td = 'px-3 py-2 text-sm';
const btn = 'rounded border border-slate-600 px-3 py-1 text-sm';

function Row({
  line,
  currency,
  onDone,
}: {
  line: CartLine;
  currency: string;
  onDone: (msg: string) => void;
}) {
  const [qty, setQty] = useState(String(line.quantity));
  const [invalid, setInvalid] = useState(false);
  const update = useUpdateCartItem();
  const remove = useRemoveCartItem();
  const errId = `cart-err-${line.product_id}`;
  const busy = update.isPending || remove.isPending;

  // Keep the field in step with the server value after any refetch or reorder.
  const [seen, setSeen] = useState(line.quantity);
  if (seen !== line.quantity) {
    setSeen(line.quantity);
    setQty(String(line.quantity));
  }

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    const quantity = parseQuantity(qty);
    setInvalid(quantity === null);
    if (quantity === null || quantity === line.quantity || busy) return;
    update.mutate(
      { product_id: line.product_id, quantity },
      { onSuccess: () => onDone('Quantity updated') },
    );
  };
  const err = invalid
    ? QUANTITY_ERROR
    : update.error instanceof ApiError && update.error.status === 422
      ? (update.error.errors.quantity?.[0] ?? describeError(update.error))
      : update.error || remove.error
        ? describeError(update.error ?? remove.error)
        : null;

  return (
    <tr className="border-b border-slate-300 align-top">
      <td className={td}>
        <Link
          to={`/catalog/${line.product_id}`}
          className="text-blue-800 underline"
        >
          {line.part_number}
        </Link>
      </td>
      <td className={td}>
        {line.name}
        {!line.available && (
          <p className="font-semibold text-red-800">
            {REASON_LABELS[line.unavailable_reason ?? 'inactive']}
          </p>
        )}
      </td>
      <td className={td}>{line.brand}</td>
      <td className={td}>{formatMoney(line.unit_price_cents, currency)}</td>
      <td className={td}>
        <form onSubmit={submit} noValidate className="flex gap-2">
          <input
            type="text"
            inputMode="numeric"
            autoComplete="off"
            aria-label={`Quantity for ${line.part_number}`}
            aria-invalid={invalid || undefined}
            aria-describedby={err ? errId : undefined}
            disabled={!line.available}
            value={qty}
            onChange={(e) => setQty(e.target.value)}
            className="w-16 rounded border border-slate-500 px-2 py-1"
          />
          <button
            type="submit"
            disabled={!line.available || busy}
            aria-label={`Update quantity for ${line.part_number}`}
            className={btn}
          >
            Update
          </button>
        </form>
        {err && (
          <p id={errId} role="alert" className="mt-1 text-red-700">
            {err}
          </p>
        )}
      </td>
      <td className={td}>
        {line.available ? formatMoney(line.line_total_cents, currency) : '-'}
      </td>
      <td className={td}>
        <button
          type="button"
          disabled={busy}
          aria-label={`Remove ${line.part_number} from cart`}
          onClick={() =>
            remove.mutate(line.product_id, {
              onSuccess: () => onDone('Item removed'),
            })
          }
          className={btn}
        >
          Remove
        </button>
      </td>
    </tr>
  );
}

export default function CartPage() {
  const cart = useCart();
  const clear = useClearCart();
  const [confirming, setConfirming] = useState(false);
  const [message, setMessage] = useState('');
  const headingRef = useRef<HTMLHeadingElement>(null);

  // A removed row unmounts its own button: park focus on the heading.
  const done = (msg: string) => {
    setMessage(msg);
    headingRef.current?.focus();
  };

  let body;
  if (cart.isError && !cart.data) {
    body = (
      <CatalogError error={cart.error} onRetry={() => void cart.refetch()} />
    );
  } else if (!cart.data) {
    body = (
      <div role="status" aria-busy="true" aria-label="Loading cart">
        {[0, 1, 2].map((i) => (
          <div
            key={i}
            className="mb-2 h-8 animate-pulse motion-reduce:animate-none rounded bg-slate-200"
          />
        ))}
      </div>
    );
  } else if (cart.data.items.length === 0) {
    body = (
      <p>
        Your cart is empty.{' '}
        <Link to="/catalog" className="text-blue-800 underline">
          Browse the catalog
        </Link>
      </p>
    );
  } else {
    const c = cart.data;
    body = (
      <>
        <ScrollRegion label="Cart table">
          <table className="w-full border-collapse">
            <caption className="sr-only">Items in your cart</caption>
            <thead>
              <tr className="border-b border-slate-400 bg-slate-100">
                {[
                  'Part number',
                  'Name',
                  'Brand',
                  'Unit price',
                  'Quantity',
                  'Line total',
                  'Remove',
                ].map((h) => (
                  <th key={h} scope="col" className={th}>
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {c.items.map((l) => (
                <Row
                  key={l.product_id}
                  line={l}
                  currency={c.currency}
                  onDone={done}
                />
              ))}
            </tbody>
          </table>
        </ScrollRegion>
        <div className="mt-4 text-right">
          <p className="text-lg font-semibold">
            Subtotal: {formatMoney(c.subtotal_cents, c.currency)}
          </p>
          <p className="text-sm">Taxes and shipping are not included.</p>
        </div>
        <div className="mt-4 flex flex-wrap items-center justify-end gap-2">
          <button
            type="button"
            className={btn}
            onClick={() => setConfirming(true)}
          >
            Clear cart
          </button>
          {c.can_checkout ? (
            <Link
              to="/checkout"
              className="rounded bg-blue-700 px-4 py-2 text-white"
            >
              Proceed to checkout
            </Link>
          ) : (
            <>
              <button
                type="button"
                disabled
                aria-describedby="checkout-why"
                className="rounded bg-blue-700 px-4 py-2 text-white disabled:opacity-50"
              >
                Proceed to checkout
              </button>
              <p id="checkout-why" className="w-full text-right text-sm">
                Remove the unavailable items to continue.
              </p>
            </>
          )}
        </div>
        {clear.isError && (
          <p role="alert" className="mt-2 text-right text-red-700">
            {describeError(clear.error)}
          </p>
        )}
        {confirming && (
          <ConfirmDialog
            title="Clear cart?"
            confirmLabel="Yes, clear cart"
            cancelLabel="Keep items"
            pending={clear.isPending}
            onCancel={() => setConfirming(false)}
            onConfirm={() =>
              clear.mutate(undefined, {
                onSuccess: () => done('Cart cleared'),
                onSettled: () => setConfirming(false),
              })
            }
          >
            <p>All items will be removed from your cart.</p>
          </ConfirmDialog>
        )}
      </>
    );
  }

  return (
    <section>
      <h1
        ref={headingRef}
        tabIndex={-1}
        className="mb-4 text-2xl font-semibold"
      >
        Your cart
      </h1>
      <p role="status" aria-live="polite" className="mb-2 min-h-6 text-sm">
        {message}
      </p>
      {body}
    </section>
  );
}
