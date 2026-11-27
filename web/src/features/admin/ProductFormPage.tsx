import { useRef, useState } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { useForm, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import ConfirmDialog from '../../components/ConfirmDialog';
import { FormAlert, SubmitButton, TextField } from '../../components/FormParts';
import PageTitle from '../../components/PageTitle';
import { ApiError } from '../../lib/api';
import { formatMoney } from '../../lib/format';
import { centsToInput, parsePrice } from '../quotes/money';
import { useCategories } from '../catalog/api';
import CatalogError from '../catalog/CatalogError';
import { SEAL_LABELS, STOCK_LABELS } from '../catalog/format';
import {
  isConflict,
  useAdminProduct,
  useCreateProduct,
  useSetProductActive,
  useUpdateProduct,
  type AdminProduct,
} from './api';
import { CURRENCY } from './format';
import {
  ConflictBanner,
  SelectField,
  TextAlert,
  TextAreaField,
  linkCls,
  mapServerErrors,
} from './forms';
import {
  SEAL_TYPES,
  STOCK_STATUSES,
  productSchema,
  toProductInput,
  type ProductValues,
} from './schemas';

const FIELDS = [
  'category_id',
  'part_number',
  'name',
  'description',
  'brand',
  'material',
  'inner_diameter_mm',
  'outer_diameter_mm',
  'width_mm',
  'seal_type',
  'stock_status',
  'price_on_request',
  'price',
] as const;

const LABELS: Record<(typeof FIELDS)[number], string> = {
  category_id: 'Category',
  part_number: 'Part number',
  name: 'Name',
  description: 'Description',
  brand: 'Brand',
  material: 'Material',
  inner_diameter_mm: 'Inner diameter (mm)',
  outer_diameter_mm: 'Outer diameter (mm)',
  width_mm: 'Width (mm)',
  seal_type: 'Seal type',
  stock_status: 'Stock status',
  price_on_request: 'Price on request',
  price: 'Price',
};

const EMPTY: ProductValues = {
  category_id: '',
  part_number: '',
  name: '',
  description: '',
  brand: '',
  material: '',
  inner_diameter_mm: '',
  outer_diameter_mm: '',
  width_mm: '',
  seal_type: 'open',
  stock_status: 'in_stock',
  price_on_request: false,
  price: '',
};

const fromProduct = (p: AdminProduct): ProductValues => ({
  category_id: String(p.category.id),
  part_number: p.part_number,
  name: p.name,
  description: p.description ?? '',
  brand: p.brand,
  material: p.material,
  inner_diameter_mm: String(p.inner_diameter_mm),
  outer_diameter_mm: String(p.outer_diameter_mm),
  width_mm: String(p.width_mm),
  seal_type: p.seal_type,
  stock_status: p.stock_status,
  price_on_request: p.price_on_request,
  price: centsToInput(p.price_cents),
});

const btn = 'rounded border border-slate-600 px-3 py-1 disabled:opacity-60';

function Form({
  product,
  onReload,
}: {
  product?: AdminProduct;
  onReload?: () => Promise<AdminProduct | undefined>;
}) {
  const navigate = useNavigate();
  const create = useCreateProduct();
  const update = useUpdateProduct(String(product?.id ?? ''));
  const setActive = useSetProductActive(String(product?.id ?? ''));
  const categories = useCategories();
  const form = useForm<ProductValues>({
    resolver: zodResolver(productSchema),
    defaultValues: product ? fromProduct(product) : EMPTY,
  });
  const {
    register,
    handleSubmit,
    setError,
    setValue,
    control,
    reset,
    formState: { errors, dirtyFields },
  } = form;
  // The version the form was loaded with. A background refetch must not silently change it.
  const loadedVersion = useRef(product?.version ?? 0);
  const [formError, setFormError] = useState<string | null>(null);
  const [conflict, setConflict] = useState(false);
  const [mine, setMine] = useState<{ label: string; value: string }[] | null>(
    null,
  );
  const [reloading, setReloading] = useState(false);
  const [notice, setNotice] = useState('');
  const [confirming, setConfirming] = useState<
    'activate' | 'deactivate' | null
  >(null);
  const [activeError, setActiveError] = useState<unknown>(null);
  const guard = useRef(false);
  const por = useWatch({ control, name: 'price_on_request' });
  const priceText = useWatch({ control, name: 'price' });
  const partNumber = register('part_number');

  const newCents = por ? null : parsePrice(priceText ?? '');
  const priceChanged =
    product &&
    newCents !== undefined &&
    (newCents !== product.price_cents || por !== product.price_on_request);
  const priceWarning = !priceChanged
    ? ''
    : por
      ? 'Saving will switch this product to "Price on request".'
      : `Saving will change the price from ${
          product.price_cents === null
            ? 'price on request'
            : formatMoney(product.price_cents, CURRENCY)
        } to ${formatMoney(newCents, CURRENCY)}. Existing orders, quotes and invoices keep their original prices.`;

  const submit = (e: React.FormEvent) => {
    void handleSubmit(async (values) => {
      if (guard.current) return;
      guard.current = true;
      setFormError(null);
      setNotice('');
      try {
        const body = toProductInput(values);
        if (!product) {
          const res = await create.mutateAsync(body);
          navigate(`/admin/products/${res.data.id}`, {
            state: { notice: 'Product created.' },
          });
          return;
        }
        const res = await update.mutateAsync({
          ...body,
          version: loadedVersion.current,
        });
        loadedVersion.current = res.data.version;
        reset(fromProduct(res.data));
        setMine(null);
        setNotice('Product saved.');
      } catch (e) {
        if (isConflict(e)) {
          setMine(
            FIELDS.filter((f) => dirtyFields[f]).map((f) => ({
              label: LABELS[f],
              value: String(values[f] ?? ''),
            })),
          );
          setConflict(true);
        } else {
          setFormError(
            mapServerErrors(e, setError, FIELDS, { price_cents: 'price' }),
          );
        }
      } finally {
        guard.current = false;
      }
    })(e);
  };

  const reload = async () => {
    if (!onReload) return;
    setReloading(true);
    try {
      const fresh = await onReload();
      if (fresh) {
        reset(fromProduct(fresh));
        loadedVersion.current = fresh.version;
        setConflict(false);
      }
    } finally {
      setReloading(false);
    }
  };

  const toggle = (active: boolean) =>
    setActive.mutate(active, {
      onSuccess: (res) => {
        const fresh = res.data;
        // Only my own change happened since load: keep editing without a false conflict.
        if (fresh.version === loadedVersion.current + 1)
          loadedVersion.current = fresh.version;
        setNotice(active ? 'Product activated.' : 'Product deactivated.');
        setConfirming(null);
      },
      onError: (e) => {
        setActiveError(e);
        setConfirming(null);
      },
    });

  const text = (name: (typeof FIELDS)[number]) =>
    errors[name]?.message as string | undefined;

  return (
    <>
      {notice && (
        <p
          role="status"
          className="mb-4 rounded border border-green-700 bg-green-50 p-3 text-green-900"
        >
          {notice}
        </p>
      )}
      {conflict && (
        <ConflictBanner onReload={() => void reload()} reloading={reloading} />
      )}
      {!conflict && mine && mine.length > 0 && (
        <details className="mb-4 rounded border border-slate-400 p-3">
          <summary className="cursor-pointer font-semibold">
            Your changes
          </summary>
          <p className="mt-2 text-sm">
            These are the values you had entered. Type them again if you still
            want them.
          </p>
          <ul className="mt-1 list-disc pl-5 text-sm">
            {mine.map((m) => (
              <li key={m.label}>
                {m.label}: {m.value}
              </li>
            ))}
          </ul>
          <button
            type="button"
            onClick={() => setMine(null)}
            className={`mt-2 ${btn}`}
          >
            Dismiss
          </button>
        </details>
      )}
      <form onSubmit={submit} noValidate aria-label="Product">
        <fieldset className="mb-4">
          <legend className="mb-2 text-lg font-semibold">Identity</legend>
          <SelectField
            id="category_id"
            label="Category"
            error={text('category_id')}
            {...register('category_id')}
          >
            <option value="">Choose a category</option>
            {categories.data?.map((c) => (
              <option key={c.id} value={String(c.id)}>
                {c.name}
              </option>
            ))}
          </SelectField>
          <TextField
            id="part_number"
            label="Part number"
            error={text('part_number')}
            {...partNumber}
            onBlur={(e) => {
              void partNumber.onBlur(e);
              setValue('part_number', e.target.value.trim().toUpperCase(), {
                shouldDirty: true,
              });
            }}
          />
          <TextField
            id="name"
            label="Name"
            error={text('name')}
            {...register('name')}
          />
          <TextAreaField
            id="description"
            label="Description (optional)"
            rows={4}
            error={text('description')}
            {...register('description')}
          />
          <TextField
            id="brand"
            label="Brand"
            error={text('brand')}
            {...register('brand')}
          />
          <TextField
            id="material"
            label="Material"
            error={text('material')}
            {...register('material')}
          />
        </fieldset>

        <fieldset className="mb-4">
          <legend className="mb-2 text-lg font-semibold">Dimensions</legend>
          <TextField
            id="inner_diameter_mm"
            label="Inner diameter (mm)"
            inputMode="decimal"
            error={text('inner_diameter_mm')}
            {...register('inner_diameter_mm')}
          />
          <TextField
            id="outer_diameter_mm"
            label="Outer diameter (mm)"
            inputMode="decimal"
            error={text('outer_diameter_mm')}
            {...register('outer_diameter_mm')}
          />
          <TextField
            id="width_mm"
            label="Width (mm)"
            inputMode="decimal"
            error={text('width_mm')}
            {...register('width_mm')}
          />
          <SelectField
            id="seal_type"
            label="Seal type"
            error={text('seal_type')}
            {...register('seal_type')}
          >
            {SEAL_TYPES.map((s) => (
              <option key={s} value={s}>
                {SEAL_LABELS[s]}
              </option>
            ))}
          </SelectField>
        </fieldset>

        <fieldset className="mb-4">
          <legend className="mb-2 text-lg font-semibold">
            Stock and price
          </legend>
          <SelectField
            id="stock_status"
            label="Stock status"
            error={text('stock_status')}
            {...register('stock_status')}
          >
            {STOCK_STATUSES.map((s) => (
              <option key={s} value={s}>
                {STOCK_LABELS[s]}
              </option>
            ))}
          </SelectField>
          <label className="mb-3 flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              {...register('price_on_request', {
                onChange: (e: { target: { checked: boolean } }) => {
                  if (e.target.checked)
                    setValue('price', '', { shouldValidate: false });
                },
              })}
            />
            Price on request
          </label>
          <TextField
            id="price"
            label={`Price (${CURRENCY})`}
            inputMode="decimal"
            placeholder="12.50"
            disabled={por}
            error={text('price')}
            {...register('price')}
          />
          <p
            role="status"
            aria-live="polite"
            className="-mt-2 mb-2 min-h-5 text-sm text-amber-900"
          >
            {priceWarning}
          </p>
        </fieldset>

        <TextAlert message={formError} />
        <SubmitButton
          pending={create.isPending || update.isPending}
          idle={product ? 'Save changes' : 'Create product'}
          busy="Saving..."
        />
        <Link to="/admin/products" className={`ml-4 ${linkCls}`}>
          Back to products
        </Link>
      </form>

      {product && (
        <section
          aria-labelledby="active-h"
          className="mt-8 border-t border-slate-300 pt-4"
        >
          <h2 id="active-h" className="mb-2 text-lg font-semibold">
            Availability
          </h2>
          <p className="mb-2 text-sm">
            {product.active
              ? 'This product is active and visible in the catalog.'
              : 'This product is inactive. Customers cannot see or order it.'}
          </p>
          <button
            type="button"
            className={btn}
            onClick={() => {
              setActiveError(null);
              setConfirming(product.active ? 'deactivate' : 'activate');
            }}
          >
            {product.active ? 'Deactivate product' : 'Activate product'}
          </button>
          <FormAlert error={activeError} />
        </section>
      )}
      {confirming && (
        <ConfirmDialog
          title={
            confirming === 'deactivate'
              ? 'Deactivate this product?'
              : 'Activate this product?'
          }
          confirmLabel={confirming === 'deactivate' ? 'Deactivate' : 'Activate'}
          cancelLabel="Cancel"
          tone={confirming === 'deactivate' ? 'danger' : 'primary'}
          pending={setActive.isPending}
          onCancel={() => setConfirming(null)}
          onConfirm={() => toggle(confirming === 'activate')}
        >
          <p>
            {confirming === 'deactivate'
              ? 'Customers will no longer find or order this product, and it cannot be added to carts or quote requests. Existing carts keep the line but cannot check out with it. Past orders, quotes and invoices are not changed.'
              : 'The product becomes visible in the catalog and can be ordered again.'}
          </p>
        </ConfirmDialog>
      )}
    </>
  );
}

export function ProductCreatePage() {
  return (
    <section>
      <PageTitle>New product</PageTitle>
      <Form />
    </section>
  );
}

export default function ProductEditPage() {
  const { id = '' } = useParams();
  const { state } = useLocation();
  const valid = /^[1-9]\d{0,17}$/.test(id);
  const q = useAdminProduct(id, valid);
  const created = (state as { notice?: unknown } | null)?.notice;

  if (!valid || (q.error instanceof ApiError && q.error.status === 404))
    return (
      <section>
        <PageTitle>Product not found</PageTitle>
        <Link to="/admin/products" className={linkCls}>
          Back to products
        </Link>
      </section>
    );
  if (q.isError && !q.data)
    return <CatalogError error={q.error} onRetry={() => void q.refetch()} />;
  const p = q.data;
  if (!p)
    return (
      <div role="status" aria-busy="true" aria-label="Loading product">
        <div className="mb-2 h-8 w-1/2 animate-pulse rounded bg-slate-200" />
        <div className="h-32 animate-pulse rounded bg-slate-200" />
      </div>
    );
  return (
    <section>
      <PageTitle>Product {p.part_number}</PageTitle>
      {!p.active && (
        <p className="mb-4">
          <span className="inline-block rounded border border-slate-600 bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-800">
            Inactive
          </span>
        </p>
      )}
      {typeof created === 'string' && (
        <p role="status" className="mb-4 text-green-900">
          {created}
        </p>
      )}
      <Form
        key={p.id}
        product={p}
        onReload={async () => (await q.refetch()).data}
      />
    </section>
  );
}
