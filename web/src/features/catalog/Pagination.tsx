const btn =
  'rounded border border-slate-600 px-3 py-1 disabled:cursor-not-allowed disabled:opacity-50';

export default function Pagination({
  page,
  lastPage,
  onPage,
}: {
  page: number;
  lastPage: number;
  onPage: (page: number) => void;
}) {
  return (
    <nav aria-label="Pagination" className="mt-4 flex items-center gap-4">
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
        disabled={page >= lastPage}
        onClick={() => onPage(page + 1)}
      >
        Next
      </button>
    </nav>
  );
}
