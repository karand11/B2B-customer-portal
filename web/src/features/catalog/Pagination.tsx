import { WINDOW_MESSAGE, atWindowEnd } from '../../lib/pageWindow';

const btn =
  'rounded border border-slate-600 px-3 py-1 disabled:cursor-not-allowed disabled:opacity-50';

export default function Pagination({
  page,
  lastPage,
  perPage,
  onPage,
}: {
  page: number;
  lastPage: number;
  /** From the response meta; enables the 10,000 result window clamp. */
  perPage?: number;
  onPage: (page: number) => void;
}) {
  const capped = perPage !== undefined && atWindowEnd(page, perPage, lastPage);
  return (
    <nav
      aria-label="Pagination"
      className="mt-4 flex flex-wrap items-center gap-4"
    >
      <button
        type="button"
        className={btn}
        disabled={page <= 1}
        onClick={() => onPage(page - 1)}
      >
        Previous
      </button>
      <span>
        Page {page} of {lastPage}
      </span>
      <button
        type="button"
        className={btn}
        disabled={page >= lastPage || capped}
        aria-describedby={capped ? 'page-window-hint' : undefined}
        onClick={() => onPage(page + 1)}
      >
        Next
      </button>
      {capped && (
        <p id="page-window-hint" className="w-full text-sm">
          {WINDOW_MESSAGE}
        </p>
      )}
    </nav>
  );
}
