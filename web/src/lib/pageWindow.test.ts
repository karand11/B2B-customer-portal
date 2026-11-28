import {
  MAX_WINDOW,
  WINDOW_MESSAGE,
  atWindowEnd,
  clampPage,
  maxPage,
} from './pageWindow';

describe('pagination window (contract Phase 7: page * per_page <= 10,000)', () => {
  it('matches the contract', () => {
    expect(MAX_WINDOW).toBe(10_000);
    expect(WINDOW_MESSAGE).toBe(
      'Only the first 10,000 results can be paged through. Narrow the search with filters.',
    );
  });

  it.each([
    [24, 416],
    [20, 500],
    [25, 400],
    [100, 100],
    [1, 10_000],
  ])('per_page %i allows pages up to %i', (perPage, last) => {
    expect(maxPage(perPage)).toBe(last);
  });

  it('clamps a page to the window and leaves smaller pages alone', () => {
    expect(clampPage(9_999, 20)).toBe(500);
    expect(clampPage(500, 20)).toBe(500);
    expect(clampPage(3, 20)).toBe(3);
  });

  it('the window ends when the next page would leave it, but only if more pages exist', () => {
    expect(atWindowEnd(416, 24, 900)).toBe(true); // 417 * 24 = 10,008
    expect(atWindowEnd(415, 24, 900)).toBe(false);
    expect(atWindowEnd(416, 24, 416)).toBe(false); // last page: nothing is cut off
    expect(atWindowEnd(500, 20, 501)).toBe(true);
  });
});
