import { useEffect, useRef, type ComponentProps, type ReactNode } from 'react';
import type { FieldValues, Path, UseFormSetError } from 'react-hook-form';
import { ApiError, describeError } from '../../lib/api';

const control = 'w-full rounded border border-slate-500 px-3 py-2';

function Wrap({
  id,
  label,
  hint,
  error,
  children,
}: {
  id: string;
  label: string;
  hint?: string;
  error?: string;
  children: ReactNode;
}) {
  return (
    <div className="mb-4">
      <label htmlFor={id} className="mb-1 block text-sm font-medium">
        {label}
      </label>
      {children}
      {hint && (
        <p id={`${id}-hint`} className="mt-1 text-xs text-slate-700">
          {hint}
        </p>
      )}
      {error && (
        <p
          id={`${id}-error`}
          role="alert"
          className="mt-1 text-sm text-red-700"
        >
          {error}
        </p>
      )}
    </div>
  );
}

const describedBy = (id: string, hint?: string, error?: string) =>
  [hint ? `${id}-hint` : '', error ? `${id}-error` : '']
    .filter(Boolean)
    .join(' ') || undefined;

export function SelectField({
  label,
  id,
  error,
  hint,
  children,
  ...rest
}: {
  label: string;
  id: string;
  error?: string;
  hint?: string;
  children: ReactNode;
} & ComponentProps<'select'>) {
  return (
    <Wrap id={id} label={label} hint={hint} error={error}>
      <select
        id={id}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy(id, hint, error)}
        className={control}
        {...rest}
      >
        {children}
      </select>
    </Wrap>
  );
}

export function TextAreaField({
  label,
  id,
  error,
  hint,
  ...rest
}: {
  label: string;
  id: string;
  error?: string;
  hint?: string;
} & ComponentProps<'textarea'>) {
  return (
    <Wrap id={id} label={label} hint={hint} error={error}>
      <textarea
        id={id}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy(id, hint, error)}
        className={control}
        {...rest}
      />
    </Wrap>
  );
}

/** Text input with an optional hint; TextField from FormParts has no hint slot. */
export function HintedField({
  label,
  id,
  error,
  hint,
  ...rest
}: {
  label: string;
  id: string;
  error?: string;
  hint?: string;
} & ComponentProps<'input'>) {
  return (
    <Wrap id={id} label={label} hint={hint} error={error}>
      <input
        id={id}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy(id, hint, error)}
        className={control}
        {...rest}
      />
    </Wrap>
  );
}

/**
 * Puts 422 messages for known fields on the field (`map` renames API keys to form
 * fields) and returns the rest as one form-level message. Non-422 errors become text too.
 */
export function mapServerErrors<V extends FieldValues>(
  err: unknown,
  setError: UseFormSetError<V>,
  fields: readonly Path<V>[],
  map: Record<string, Path<V>> = {},
): string | null {
  if (!(err instanceof ApiError) || err.status !== 422)
    return describeError(err);
  const rest: string[] = [];
  let mapped = 0;
  for (const [key, msgs] of Object.entries(err.errors)) {
    const target =
      map[key] ?? (fields as readonly string[]).find((f) => f === key);
    if (target) {
      setError(target as Path<V>, { type: 'server', message: msgs[0] });
      mapped++;
    } else rest.push(...msgs);
  }
  if (rest.length) return [...new Set(rest)].join(' ');
  return mapped ? null : err.message;
}

export const CONFLICT_TEXT =
  'This record was changed by someone else. Reload the latest version, then re-apply your changes.';

/** Shown on a 409. Nothing was overwritten; reloading refetches and resets the form. */
export function ConflictBanner({
  onReload,
  reloading,
  children,
}: {
  onReload: () => void;
  reloading: boolean;
  children?: ReactNode;
}) {
  return (
    <div
      role="alert"
      className="mb-4 rounded border border-amber-700 bg-amber-50 p-3 text-amber-900"
    >
      <p>{CONFLICT_TEXT}</p>
      <button
        type="button"
        disabled={reloading}
        onClick={onReload}
        className="mt-2 rounded border border-slate-600 bg-white px-3 py-1 text-slate-900 disabled:opacity-60"
      >
        Reload latest
      </button>
      {children}
    </div>
  );
}

export const linkCls = 'text-blue-800 underline';

/** Form-level message (already plain text). Takes focus when it appears, like FormAlert. */
export function TextAlert({ message }: { message: string | null }) {
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (message) ref.current?.focus();
  }, [message]);
  if (!message) return null;
  return (
    <div
      ref={ref}
      role="alert"
      tabIndex={-1}
      className="mb-4 rounded border border-red-700 bg-red-50 p-3 text-red-800"
    >
      {message}
    </div>
  );
}
