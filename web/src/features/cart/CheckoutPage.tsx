import { useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Link, Navigate, useNavigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { FormAlert, SubmitButton, TextField } from '../../components/FormParts';
import { ApiError } from '../../lib/api';
import { formatMoney } from '../../lib/format';
import { poReferenceSchema } from '../../lib/poReference';
import CatalogError from '../catalog/CatalogError';
import { usePlaceOrder } from '../orders/api';
import { resetCart, useCart } from './api';

// Same rules as the API (contract: POST /orders). The server stays the authority.
const schema = z.object({ po_reference: poReferenceSchema });
type Values = z.infer<typeof schema>;

export default function CheckoutPage() {
  const cart = useCart();
  const place = usePlaceOrder();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [placed, setPlaced] = useState(false);
  const inFlight = useRef(false); // sync guard: a double click beats any re-render
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { po_reference: '' },
  });

  const submit = (v: Values) => {
    if (inFlight.current) return;
    inFlight.current = true;
    place.mutate(v.po_reference || undefined, {
      onSuccess: (res) => {
        setPlaced(true); // batched with the cart reset: no bounce to /cart
        resetCart(qc, res.data.currency);
        navigate(`/orders/${res.data.number}`, { state: { placed: true } });
      },
      onError: (e) => {
        if (e instanceof ApiError && e.status === 422 && e.errors.po_reference)
          setError('po_reference', { message: e.errors.po_reference[0] });
      },
      onSettled: () => {
        inFlight.current = false;
      },
    });
  };

  if (cart.isError && !cart.data)
    return (
      <CatalogError error={cart.error} onRetry={() => void cart.refetch()} />
    );
  if (!cart.data)
    return (
      <div role="status" aria-busy="true" aria-label="Loading cart">
        <div className="h-24 animate-pulse rounded bg-slate-200" />
      </div>
    );
  const c = cart.data;
  // After a successful order the cart is empty on purpose: do not bounce to /cart.
  if (c.items.length === 0 && !placed) return <Navigate to="/cart" replace />;

  const cartErrors =
    place.error instanceof ApiError && place.error.status === 422
      ? (place.error.errors.cart ?? [])
      : [];
  // Field errors are shown inline; everything else goes to the form alert.
  const otherError =
    cartErrors.length > 0 ||
    (place.error instanceof ApiError && place.error.errors.po_reference)
      ? null
      : place.error;

  return (
    <section>
      <h1 className="mb-4 text-2xl font-semibold">Checkout</h1>
      <table className="mb-4 w-full border-collapse">
        <caption className="sr-only">Order review</caption>
        <thead>
          <tr className="border-b border-slate-400 bg-slate-100">
            {[
              'Part number',
              'Name',
              'Quantity',
              'Unit price',
              'Line total',
            ].map((h) => (
              <th key={h} scope="col" className="px-3 py-2 text-left text-sm">
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {c.items.map((l) => (
            <tr
              key={l.product_id}
              className="border-b border-slate-300 text-sm"
            >
              <td className="px-3 py-2">{l.part_number}</td>
              <td className="px-3 py-2">
                {l.name}
                {!l.available && (
                  <p className="font-semibold text-red-800">
                    Unavailable: remove it from your cart.
                  </p>
                )}
              </td>
              <td className="px-3 py-2">{l.quantity}</td>
              <td className="px-3 py-2">
                {formatMoney(l.unit_price_cents, c.currency)}
              </td>
              <td className="px-3 py-2">
                {l.available
                  ? formatMoney(l.line_total_cents, c.currency)
                  : '-'}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <p className="mb-1 text-lg font-semibold">
        Total: {formatMoney(c.subtotal_cents, c.currency)}
      </p>
      <p className="mb-4 text-sm">
        Taxes and shipping are not included. Your order will be reviewed and
        confirmed by our staff.
      </p>

      {cartErrors.length > 0 && (
        <div
          role="alert"
          className="mb-4 rounded border border-red-700 bg-red-50 p-3 text-red-800"
        >
          <p>Your order could not be placed:</p>
          <ul className="list-disc pl-5">
            {cartErrors.map((m) => (
              <li key={m}>{m}</li>
            ))}
          </ul>
          <Link to="/cart" className="underline">
            Back to your cart
          </Link>
        </div>
      )}

      <form
        onSubmit={(e) => void handleSubmit((v) => submit(v))(e)}
        noValidate
        className="max-w-md"
      >
        <TextField
          id="po_reference"
          label="PO reference (optional)"
          autoComplete="off"
          maxLength={80}
          error={errors.po_reference?.message}
          {...register('po_reference')}
        />
        <FormAlert error={otherError} />
        <SubmitButton
          pending={place.isPending}
          idle="Place order"
          busy="Placing order..."
        />
      </form>
    </section>
  );
}
