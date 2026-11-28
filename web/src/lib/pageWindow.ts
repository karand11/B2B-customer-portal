/** Result window of every list endpoint (contract Phase 7): page * per_page <= 10,000. */
export const MAX_WINDOW = 10_000;
export const WINDOW_MESSAGE =
  'Only the first 10,000 results can be paged through. Narrow the search with filters.';

/** The SPA never sends per_page, so these are the API defaults. */
export const PER_PAGE = { catalog: 24, list: 20, admin: 25 } as const;

export const maxPage = (perPage: number) =>
  Math.max(1, Math.floor(MAX_WINDOW / perPage));

/** A hand-edited URL page past the window is pulled back instead of reaching the 422. */
export const clampPage = (page: number, perPage: number) =>
  Math.min(page, maxPage(perPage));

/** More pages exist but the next one is outside the window. */
export const atWindowEnd = (page: number, perPage: number, lastPage: number) =>
  page < lastPage && (page + 1) * perPage > MAX_WINDOW;
