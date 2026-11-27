import { describe, expect, it } from 'vitest';
import {
  csvParam,
  dateParam,
  enumParam,
  intParam,
  pageParam,
  parseList,
  sortParam,
  textParam,
  toListSearch,
  type ListSpec,
} from './listUrlState';

interface Q {
  q: string;
  role: ('a' | 'b' | 'c')[];
  kind: '' | 'x' | 'y';
  company_id: string;
  from: string;
  sort: string;
  page: number;
}
const SPEC: ListSpec<Q> = {
  q: textParam(5),
  role: csvParam(['a', 'b', 'c'] as const),
  kind: enumParam(['x', 'y'] as const),
  company_id: intParam,
  from: dateParam,
  sort: sortParam(['name', '-name'], 'name'),
  page: pageParam,
};
const parse = (qs: string) => parseList(SPEC, new URLSearchParams(qs));

describe('list url state', () => {
  it('parses valid values and falls back to defaults for invalid ones', () => {
    expect(
      parse(
        'q=ab&role=c,a&kind=y&company_id=12&from=2026-11-27&sort=-name&page=3',
      ),
    ).toEqual({
      q: 'ab',
      role: ['a', 'c'],
      kind: 'y',
      company_id: '12',
      from: '2026-11-27',
      sort: '-name',
      page: 3,
    });
    expect(
      parse(
        'q=toolongvalue&role=zz&kind=z&company_id=0&from=2026-02-30&sort=bogus&page=0',
      ),
    ).toEqual({
      q: '',
      role: [],
      kind: '',
      company_id: '',
      from: '',
      sort: 'name',
      page: 1,
    });
    expect(parse('page=10001').page).toBe(1);
    expect(parse('page=1.5').page).toBe(1);
  });

  it('writes a canonical string: fixed key order, defaults omitted, commas literal, no per_page', () => {
    expect(toListSearch(SPEC, parse(''))).toBe('');
    expect(
      toListSearch(SPEC, parse('page=2&role=b,a&q=ab&per_page=100&sort=name')),
    ).toBe('?q=ab&role=a,b&page=2');
    expect(toListSearch(SPEC, { ...parse(''), q: 'a b&c' })).toBe(
      '?q=a%20b%26c',
    );
  });
});
