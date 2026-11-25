import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import type { Spec } from '../../../test/utils';
import { P1, deferred, setup } from './fixtures';

const DETAIL = 'GET /api/v1/products/17';
const detail = {
  body: { data: { ...P1, description: 'Single row, rubber sealed.' } },
};

describe('product detail page', () => {
  it('renders the spec list, description, formatted price and document title', async () => {
    setup('/catalog/17', { [DETAIL]: detail });
    expect(
      await screen.findByRole('heading', { name: '6205-2RS' }),
    ).toBeInTheDocument();
    const dl = screen.getByText('Inner diameter').closest('dl')!;
    expect(dl).toHaveTextContent('Inner diameter25 mm');
    expect(dl).toHaveTextContent('Outer diameter52 mm');
    expect(dl).toHaveTextContent('Seal type2RS (rubber sealed)');
    expect(dl).toHaveTextContent('StockIn stock');
    expect(dl).toHaveTextContent('Price$1,250.50');
    expect(dl).toHaveTextContent('Single row, rubber sealed.');
    expect(document.title).toBe('6205-2RS');
    expect(screen.queryByRole('button', { name: /quote/i })).toBeNull();
  });

  it('shows a loading skeleton first', async () => {
    const d = deferred<Spec>();
    setup('/catalog/17', { [DETAIL]: () => d.promise });
    expect(
      await screen.findByRole('status', { name: 'Loading product' }),
    ).toHaveAttribute('aria-busy', 'true');
    d.resolve(detail);
    await screen.findByRole('heading', { name: '6205-2RS' });
  });

  it('a 404 shows Product not found with a link to /catalog, no error banner', async () => {
    setup('/catalog/17', {
      [DETAIL]: { status: 404, body: { message: 'Not Found.' } },
    });
    expect(
      await screen.findByRole('heading', { name: 'Product not found' }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('link', { name: 'Back to catalog' }),
    ).toHaveAttribute('href', '/catalog');
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('a non-numeric id shows not found and sends no product request', async () => {
    const { api } = setup('/catalog/abc');
    expect(
      await screen.findByRole('heading', { name: 'Product not found' }),
    ).toBeInTheDocument();
    expect(api.calls.some((c) => c.url === '/api/v1/products/abc')).toBe(false);
  });

  it('a 500 shows an alert; Try again recovers', async () => {
    const user = userEvent.setup();
    let n = 0;
    setup('/catalog/17', {
      [DETAIL]: () => (n++ === 0 ? { status: 500, body: {} } : detail),
    });
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Try again' }));
    expect(
      await screen.findByRole('heading', { name: '6205-2RS' }),
    ).toBeInTheDocument();
  });

  it('Back to results keeps the list filters', async () => {
    const user = userEvent.setup();
    setup('/catalog?brand=SKF&sort=-price', { [DETAIL]: detail });
    await user.click(await screen.findByRole('link', { name: '6205-2RS' }));
    const back = await screen.findByRole('link', { name: 'Back to results' });
    expect(back).toHaveAttribute('href', '/catalog?brand=SKF&sort=-price');
    await user.click(back);
    expect(await screen.findByRole('checkbox', { name: 'SKF' })).toBeChecked();
  });

  it('a deep link falls back to /catalog', async () => {
    setup('/catalog/17', { [DETAIL]: detail });
    expect(
      await screen.findByRole('link', { name: 'Back to results' }),
    ).toHaveAttribute('href', '/catalog');
  });
});
