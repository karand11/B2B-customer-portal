// The API sends plain YYYY-MM-DD strings: they are shown and compared as strings,
// never parsed with new Date(string) (that shifts the day in negative-offset zones).
// "Today" is only needed for the date inputs; the server rules use UTC, so we do too.
export const todayUtc = (): string => new Date().toISOString().slice(0, 10);

export const plusDaysUtc = (days: number): string =>
  new Date(Date.now() + days * 86_400_000).toISOString().slice(0, 10);
