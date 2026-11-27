import { useEffect, useId, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useDebouncedCallback } from '../catalog/useDebouncedCallback';
import { useAdminCompanies, useAdminCompany } from './api';

const input = 'rounded border border-slate-500 px-2 py-1';

/**
 * Admin lists with no filter in the URL redirect once to an explicit default URL
 * (a visible URL, not hidden state). Clearing the filters afterwards stays cleared.
 * Returns true while the redirect is pending so the caller can hold its request.
 */
export function useAdminDefault(basePath: string, defaultSearch: string) {
  const navigate = useNavigate();
  const [sp] = useSearchParams();
  const [seenFilters, setSeenFilters] = useState(false);
  // Derived state: once the URL has carried filters, a later empty URL is the user's choice.
  if (!seenFilters && sp.toString() !== '') setSeenFilters(true);
  const needs = basePath !== '' && !seenFilters && sp.toString() === '';
  useEffect(() => {
    if (needs) navigate({ search: defaultSearch }, { replace: true });
  }, [needs, navigate, defaultSearch]);
  return needs;
}

/**
 * Company filter for the back-office lists: type to search, pick a result.
 * The URL carries only the numeric id; the chip loads the name.
 */
export function CompanyFilter({
  value,
  onChange,
  label = 'Company',
  removeLabel = 'Remove company filter',
}: {
  value: string;
  onChange: (id: string) => void;
  label?: string;
  removeLabel?: string;
}) {
  const id = useId();
  const [text, setText] = useState('');
  const [term, setTerm] = useState('');
  const [schedule] = useDebouncedCallback((v: string) => setTerm(v), 300);
  const selected = useAdminCompany(value, value !== '');
  const matches = useAdminCompanies(
    `?q=${encodeURIComponent(term)}`,
    term !== '' && value === '',
  );

  if (value)
    return (
      <div className="mb-4">
        <p className="mb-1 text-sm font-semibold">{label}</p>
        <p className="text-sm">
          {selected.data?.name ?? `Company ${value}`}{' '}
          <button
            type="button"
            onClick={() => {
              setText('');
              setTerm('');
              onChange('');
            }}
            className="ml-1 rounded border border-slate-600 px-2 py-0.5"
          >
            {removeLabel}
          </button>
        </p>
      </div>
    );

  return (
    <div className="mb-4">
      <label htmlFor={id} className="mb-1 block text-sm font-semibold">
        {label}
      </label>
      <input
        id={id}
        type="search"
        value={text}
        maxLength={64}
        aria-describedby={`${id}-results`}
        className={input}
        onChange={(e) => {
          setText(e.target.value);
          schedule(e.target.value.trim());
        }}
      />
      <div id={`${id}-results`} aria-live="polite">
        {term && matches.data && matches.data.data.length === 0 && (
          <p className="mt-1 text-xs">No companies found.</p>
        )}
        {term && matches.data && matches.data.data.length > 0 && (
          <ul aria-label="Matching companies" className="mt-1 space-y-1">
            {matches.data.data.slice(0, 8).map((c) => (
              <li key={c.id}>
                <button
                  type="button"
                  onClick={() => onChange(String(c.id))}
                  className="rounded border border-slate-600 px-2 py-0.5 text-sm"
                >
                  {c.name}
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}

/** "Within N days" select for the expiring-soon and due-soon list filters. */
export function WithinDays({
  label,
  value,
  options,
  onChange,
}: {
  label: string;
  value: string;
  options: number[];
  onChange: (v: string) => void;
}) {
  const id = useId();
  const all =
    value && !options.includes(Number(value))
      ? [...options, Number(value)]
      : options;
  return (
    <div className="mb-4">
      <label htmlFor={id} className="mb-1 block text-sm font-semibold">
        {label}
      </label>
      <select
        id={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className={input}
      >
        <option value="">Any</option>
        {all.map((n) => (
          <option key={n} value={String(n)}>
            {n === 0 ? 'Today' : `${n} days`}
          </option>
        ))}
      </select>
    </div>
  );
}
