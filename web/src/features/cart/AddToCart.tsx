import { useId, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../auth/AuthContext';
import { ApiError, describeError } from '../../lib/api';
import { QUANTITY_ERROR, parseQuantity, useAddToCart } from './api';
import { REASON_LABELS } from './labels';

interface Props {
  product: {
    id: number;
    part_number: string;
    price_on_request: boolean;
    stock_status: string;
  };
}

/** Customers only; the API still rejects everyone else with 403. */
export default function AddToCart({ product }: Props) {
  const { user } = useAuth();
  const id = useId();
  const [qty, setQty] = useState('1');
  const [invalid, setInvalid] = useState(false);
  const [done, setDone] = useState('');
  const add = useAddToCart();
  if (user?.role !== 'customer') return null;

  const blocked = product.price_on_request
    ? REASON_LABELS.price_on_request
    : product.stock_status === 'out_of_stock'
      ? REASON_LABELS.out_of_stock
      : null;
  if (blocked)
    return (
      <div className="flex items-center gap-2">
        <button
          type="button"
          disabled
          aria-describedby={`${id}-why`}
          className="rounded border border-slate-500 px-3 py-1 text-sm disabled:opacity-60"
        >
          Add to cart
        </button>
        <span id={`${id}-why`} className="text-sm">
          {blocked}
        </span>
        <Link
          to={`/quotes/new?product=${product.id}`}
          aria-label={`Request quote for ${product.part_number}`}
          className="rounded bg-blue-700 px-3 py-1 text-sm text-white"
        >
          Request quote
        </Link>
      </div>
    );

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    setDone('');
    const quantity = parseQuantity(qty);
    setInvalid(quantity === null);
    if (quantity === null || add.isPending) return;
    add.mutate(
      { product_id: product.id, quantity },
      {
        onSuccess: () =>
          setDone(`Added ${quantity} x ${product.part_number} to your cart`),
      },
    );
  };

  const serverError = add.error
    ? add.error instanceof ApiError && add.error.status === 422
      ? (add.error.errors.product_id?.[0] ??
        add.error.errors.quantity?.[0] ??
        describeError(add.error))
      : describeError(add.error)
    : null;
  const error = invalid ? QUANTITY_ERROR : serverError;

  return (
    <form
      onSubmit={submit}
      noValidate
      className="flex flex-wrap items-center gap-2"
    >
      <input
        type="text"
        inputMode="numeric"
        autoComplete="off"
        aria-label={`Quantity for ${product.part_number}`}
        aria-invalid={invalid || undefined}
        aria-describedby={error ? `${id}-err` : undefined}
        value={qty}
        onChange={(e) => setQty(e.target.value)}
        className="w-16 rounded border border-slate-500 px-2 py-1 text-sm"
      />
      <button
        type="submit"
        disabled={add.isPending}
        className="rounded bg-blue-700 px-3 py-1 text-sm text-white disabled:opacity-60"
      >
        {add.isPending ? 'Adding...' : 'Add to cart'}
      </button>
      <span role="status" aria-live="polite" className="text-sm">
        {done}
      </span>
      {error && (
        <span
          id={`${id}-err`}
          role="alert"
          className="w-full text-sm text-red-700"
        >
          {error}
        </span>
      )}
    </form>
  );
}
