import { ApiError, describeError } from '../../lib/api';

export function catalogErrorText(err: unknown): string {
  if (err instanceof ApiError && err.status === 429)
    return err.retryAfter
      ? `Too many requests, try again in ${err.retryAfter} seconds.`
      : 'Too many requests, try again later.';
  return describeError(err);
}

export default function CatalogError({
  error,
  onRetry,
  onClear,
}: {
  error: unknown;
  onRetry: () => void;
  onClear?: () => void;
}) {
  return (
    <div
      role="alert"
      className="rounded border border-red-700 bg-red-50 p-4 text-red-800"
    >
      <p>{catalogErrorText(error)}</p>
      <div className="mt-2 flex gap-2">
        <button
          type="button"
          onClick={onRetry}
          className="rounded bg-blue-700 px-3 py-1 text-white"
        >
          Try again
        </button>
        {onClear && (
          <button
            type="button"
            onClick={onClear}
            className="rounded border border-slate-600 px-3 py-1"
          >
            Clear all filters
          </button>
        )}
      </div>
    </div>
  );
}
