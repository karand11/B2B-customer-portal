import { useEffect, useRef, type ComponentProps } from 'react';
import { describeError } from '../lib/api';

/** Labelled input with its error wired up via aria-describedby and role=alert. */
export function TextField({
  label,
  error,
  id,
  ...input
}: { label: string; error?: string; id: string } & ComponentProps<'input'>) {
  return (
    <div className="mb-4">
      <label htmlFor={id} className="mb-1 block text-sm font-medium">
        {label}
      </label>
      <input
        id={id}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? `${id}-error` : undefined}
        className="w-full rounded border border-slate-500 px-3 py-2"
        {...input}
      />
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

/** Form-level server error. Takes focus when it appears so keyboard and screen reader users notice it. */
export function FormAlert({ error }: { error: unknown }) {
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (error) ref.current?.focus();
  }, [error]);
  if (!error) return null;
  return (
    <div
      ref={ref}
      role="alert"
      tabIndex={-1}
      className="mb-4 rounded border border-red-700 bg-red-50 p-3 text-red-800"
    >
      {describeError(error)}
    </div>
  );
}

export function SubmitButton({
  pending,
  idle,
  busy,
}: {
  pending: boolean;
  idle: string;
  busy: string;
}) {
  return (
    <button
      type="submit"
      disabled={pending}
      className="rounded bg-blue-700 px-4 py-2 text-white hover:bg-blue-800 disabled:opacity-60"
    >
      {pending ? busy : idle}
    </button>
  );
}
