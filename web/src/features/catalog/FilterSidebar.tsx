import { useEffect, useRef, useState } from 'react';
import type { Category, Filters } from './api';
import { SEAL_LABELS, STOCK_LABELS } from './format';
import {
  RANGES,
  rangeError,
  type CatalogQuery,
  type ListKey,
  type RangeBase,
} from './urlState';
import { useDebouncedCallback } from './useDebouncedCallback';

const inputCls = 'w-full rounded border border-slate-500 px-2 py-1';

interface Props {
  id: string;
  className?: string;
  query: CatalogQuery;
  raw: URLSearchParams;
  filters: Filters | undefined;
  categories: {
    data?: (Category & { product_count: number })[];
    failed: boolean;
  };
  onList: (key: ListKey, values: string[]) => void;
  onSearch: (q: string) => void;
  onRange: (base: RangeBase, min: string, max: string) => void;
}

function Facet({
  legend,
  options,
  selected,
  onChange,
}: {
  legend: string;
  options: { value: string; label: string }[];
  selected: string[];
  onChange: (values: string[]) => void;
}) {
  return (
    <fieldset className="mb-4">
      <legend className="mb-1 text-sm font-semibold">{legend}</legend>
      {options.map((o) => (
        <label key={o.value} className="flex items-center gap-2 py-0.5 text-sm">
          <input
            type="checkbox"
            checked={selected.includes(o.value)}
            onChange={(e) =>
              onChange(
                e.target.checked
                  ? [...selected, o.value]
                  : selected.filter((v) => v !== o.value),
              )
            }
          />
          {o.label}
        </label>
      ))}
    </fieldset>
  );
}

/** Text box with local state; commits to the URL (replace) after a pause. */
function SearchBox({
  value,
  onCommit,
}: {
  value: string;
  onCommit: (q: string) => void;
}) {
  const [text, setText] = useState(value);
  const committed = useRef(value);
  const [schedule, cancel] = useDebouncedCallback((v: string) => {
    committed.current = v;
    onCommit(v);
  }, 300);
  // URL changed from outside (back/forward, clear all): show it and drop any pending commit.
  useEffect(() => {
    if (value !== committed.current) {
      committed.current = value;
      cancel();
      setText(value);
    }
  }, [value, cancel]);
  return (
    <div className="mb-4">
      <label htmlFor="catalog-q" className="mb-1 block text-sm font-semibold">
        Part number
      </label>
      <input
        id="catalog-q"
        type="search"
        value={text}
        maxLength={64}
        aria-describedby="catalog-q-hint"
        className={inputCls}
        onChange={(e) => {
          setText(e.target.value);
          schedule(e.target.value.trim());
        }}
      />
      <p id="catalog-q-hint" className="mt-1 text-xs text-slate-700">
        e.g. 6205 or 6205-2RS
      </p>
    </div>
  );
}

function RangePair({
  base,
  label,
  rawMin,
  rawMax,
  placeholder,
  onCommit,
}: {
  base: RangeBase;
  label: string;
  rawMin: string;
  rawMax: string;
  placeholder: { min: number | null; max: number | null } | undefined;
  onCommit: (min: string, max: string) => void;
}) {
  const [min, setMin] = useState(rawMin);
  const [max, setMax] = useState(rawMax);
  const [error, setError] = useState(() => rangeError(rawMin, rawMax));
  const committed = useRef(`${rawMin}|${rawMax}`);
  const [schedule, cancel] = useDebouncedCallback((a: string, b: string) => {
    const err = rangeError(a, b);
    setError(err);
    if (err) return; // invalid ranges are never sent
    committed.current = `${a}|${b}`;
    onCommit(a, b);
  }, 400);
  useEffect(() => {
    if (`${rawMin}|${rawMax}` !== committed.current) {
      committed.current = `${rawMin}|${rawMax}`;
      cancel();
      setMin(rawMin);
      setMax(rawMax);
      setError(rangeError(rawMin, rawMax));
    }
  }, [rawMin, rawMax, cancel]);

  const errId = `${base}-error`;
  const field = (
    end: 'min' | 'max',
    value: string,
    set: (v: string) => void,
  ) => (
    <div className="flex-1">
      <label htmlFor={`${base}_${end}`} className="mb-1 block text-xs">
        {label} {end === 'min' ? 'min' : 'max'} (mm)
      </label>
      <input
        id={`${base}_${end}`}
        inputMode="decimal"
        autoComplete="off"
        value={value}
        placeholder={placeholder?.[end] != null ? String(placeholder[end]) : ''}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? errId : undefined}
        className={inputCls}
        onChange={(e) => {
          set(e.target.value);
          const [a, b] =
            end === 'min' ? [e.target.value, max] : [min, e.target.value];
          schedule(a.trim(), b.trim());
        }}
      />
    </div>
  );
  return (
    <div className="mb-4">
      <div className="flex gap-2">
        {field('min', min, setMin)}
        {field('max', max, setMax)}
      </div>
      {error && (
        <p id={errId} role="alert" className="mt-1 text-sm text-red-700">
          {error}
        </p>
      )}
    </div>
  );
}

export default function FilterSidebar({
  id,
  className,
  query,
  raw,
  filters,
  categories,
  onList,
  onSearch,
  onRange,
}: Props) {
  const opts = (values: string[], labels?: Record<string, string>) =>
    values.map((v) => ({ value: v, label: labels?.[v] ?? v }));
  return (
    <aside id={id} aria-label="Filters" className={className}>
      <SearchBox value={query.q} onCommit={onSearch} />
      {categories.failed ? (
        <p role="alert" className="mb-4 text-sm text-red-700">
          Could not load categories.
        </p>
      ) : (
        <Facet
          legend="Category"
          options={(categories.data ?? []).map((c) => ({
            value: c.slug,
            label: c.name,
          }))}
          selected={query.category}
          onChange={(v) => onList('category', v)}
        />
      )}
      {filters ? (
        <>
          <Facet
            legend="Brand"
            options={opts(filters.brands)}
            selected={query.brand}
            onChange={(v) => onList('brand', v)}
          />
          <Facet
            legend="Material"
            options={opts(filters.materials)}
            selected={query.material}
            onChange={(v) => onList('material', v)}
          />
          <Facet
            legend="Seal type"
            options={opts(filters.seal_types, SEAL_LABELS)}
            selected={query.seal_type}
            onChange={(v) => onList('seal_type', v)}
          />
          <Facet
            legend="Stock status"
            options={opts(filters.stock_statuses, STOCK_LABELS)}
            selected={query.stock_status}
            onChange={(v) => onList('stock_status', v)}
          />
        </>
      ) : (
        <p className="mb-4 text-sm">Loading filters...</p>
      )}
      {RANGES.map(({ base, label }) => (
        <RangePair
          key={base}
          base={base}
          label={label}
          rawMin={raw.get(`${base}_min`)?.trim() ?? ''}
          rawMax={raw.get(`${base}_max`)?.trim() ?? ''}
          placeholder={filters?.ranges[`${base}_mm`]}
          onCommit={(a, b) => onRange(base, a, b)}
        />
      ))}
    </aside>
  );
}
