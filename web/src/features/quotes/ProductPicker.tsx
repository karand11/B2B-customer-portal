import { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '../../lib/api';
import CatalogError from '../catalog/CatalogError';
import type { Product, ProductList } from '../catalog/api';
import { useDebouncedCallback } from '../catalog/useDebouncedCallback';

/** Inline catalog lookup reusing GET /products?q=. Prices are neither shown nor sent. */
export default function ProductPicker({
  added,
  full,
  onAdd,
  onClose,
}: {
  added: Set<number>;
  full: boolean;
  onAdd: (p: Product) => void;
  onClose: () => void;
}) {
  const [text, setText] = useState('');
  const [q, setQ] = useState('');
  const [note, setNote] = useState('');
  const input = useRef<HTMLInputElement>(null);
  const [schedule] = useDebouncedCallback((v: string) => setQ(v), 300);
  useEffect(() => input.current?.focus(), []);

  const results = useQuery({
    queryKey: ['catalog', 'picker', q],
    queryFn: () =>
      api.get<ProductList>(
        `/api/v1/products?q=${encodeURIComponent(q)}&per_page=10`,
      ),
    enabled: q !== '',
  });
  const list = results.data?.data;

  return (
    <section
      aria-labelledby="picker-h"
      className="mb-4 rounded border border-slate-400 p-3"
      onKeyDown={(e) => {
        if (e.key === 'Escape') onClose();
      }}
    >
      <h3 id="picker-h" className="mb-2 font-semibold">
        Add catalog item
      </h3>
      <label htmlFor="picker-q" className="mb-1 block text-sm font-medium">
        Search the catalog
      </label>
      <input
        id="picker-q"
        ref={input}
        type="search"
        autoComplete="off"
        maxLength={64}
        aria-describedby="picker-hint"
        value={text}
        onChange={(e) => {
          setText(e.target.value);
          schedule(e.target.value.trim());
        }}
        className="w-full max-w-sm rounded border border-slate-500 px-2 py-1"
      />
      <p id="picker-hint" className="mt-1 text-xs text-slate-700">
        Part number or name, e.g. 6205. Prices are quoted by our staff.
      </p>
      <p role="status" aria-live="polite" className="mt-2 min-h-5 text-sm">
        {note ||
          (results.isFetching
            ? 'Searching...'
            : list
              ? `${list.length} ${list.length === 1 ? 'result' : 'results'}`
              : '')}
      </p>
      {results.isError && (
        <CatalogError
          error={results.error}
          onRetry={() => void results.refetch()}
        />
      )}
      {list && list.length === 0 && <p>No products match your search.</p>}
      {list && list.length > 0 && (
        <ul aria-label="Search results" className="divide-y divide-slate-300">
          {list.map((p) => {
            const have = added.has(p.id);
            return (
              <li key={p.id} className="flex items-center gap-3 py-1 text-sm">
                <span className="flex-1">
                  <span className="font-semibold">{p.part_number}</span>{' '}
                  {p.name}
                </span>
                {have && <span>Already in this quote</span>}
                <button
                  type="button"
                  aria-label={`Add ${p.part_number}`}
                  disabled={full || have}
                  onClick={() => {
                    onAdd(p);
                    setNote(`Added ${p.part_number} to the quote.`);
                  }}
                  className="rounded border border-slate-600 px-2 py-0.5 disabled:opacity-60"
                >
                  Add
                </button>
              </li>
            );
          })}
        </ul>
      )}
      <button
        type="button"
        onClick={onClose}
        className="mt-2 rounded border border-slate-600 px-3 py-1"
      >
        Close search
      </button>
    </section>
  );
}
