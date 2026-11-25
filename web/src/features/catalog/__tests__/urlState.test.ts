import { describe, expect, it } from 'vitest';
import { EMPTY_QUERY, parseQuery, toSearch } from '../urlState';

const parse = (s: string) => parseQuery(new URLSearchParams(s));

describe('urlState', () => {
  it('drops invalid values instead of keeping them', () => {
    const q = parse(
      'sort=evil&page=-3&outer_diameter_min=abc&seal_type=rubber&stock_status=gone&category=A_B',
    );
    expect(q).toEqual(EMPTY_QUERY);
    expect(toSearch(q)).toBe('');
  });

  it('drops a whole range pair when min > max, and out-of-range numbers', () => {
    expect(parse('width_min=60&width_max=50').ranges).toEqual({});
    expect(parse('width_min=0&inner_diameter_max=5001').ranges).toEqual({});
    expect(parse('width_min=1e3').ranges).toEqual({});
    expect(parse('width_min=5&width_max=5').ranges).toEqual({
      width_min: '5',
      width_max: '5',
    });
  });

  it('keeps valid items of a CSV and drops the bad ones', () => {
    const q = parse('brand=SKF,,NSK,%00,SKF&seal_type=2rs,rubber');
    expect(q.brand).toEqual(['SKF', 'NSK']);
    expect(q.seal_type).toEqual(['2rs']);
  });

  it('serialises in a fixed key order and omits defaults', () => {
    const q = parse(
      'page=2&sort=-price&outer_diameter_min=40&brand=SKF,NSK&q=6205&category=deep-groove-ball',
    );
    expect(toSearch(q)).toBe(
      '?q=6205&category=deep-groove-ball&brand=SKF,NSK&outer_diameter_min=40&sort=-price&page=2',
    );
    expect(toSearch({ ...q, page: 1 })).not.toContain('page');
  });

  it('encodes spaces and symbols in values', () => {
    const q = parse('material=Chrome%20steel&q=62%2005');
    expect(toSearch(q)).toBe('?q=62%2005&material=Chrome%20steel');
  });
});
