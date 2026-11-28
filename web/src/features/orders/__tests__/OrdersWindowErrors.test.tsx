import { screen } from '@testing-library/react';
import { ORDERS, setupApp } from '../../cart/__tests__/fixtures';
import { WINDOW_MESSAGE } from '../../../lib/pageWindow';

describe('orders list errors', () => {
  it('shows the server window message (422 page), as plain text', async () => {
    setupApp('/orders?page=2', {
      [ORDERS]: {
        status: 422,
        body: { message: WINDOW_MESSAGE, errors: { page: [WINDOW_MESSAGE] } },
      },
    });
    expect(await screen.findByRole('alert')).toHaveTextContent(WINDOW_MESSAGE);
  });

  it('never requests a page outside the window from a hand-edited URL', async () => {
    const { api } = setupApp('/orders?page=9000', {
      [ORDERS]: { status: 500, body: {} },
    });
    await screen.findByRole('alert');
    expect(api.calls.some((c) => c.url === '/api/v1/orders?page=500')).toBe(
      true,
    );
    expect(api.calls.some((c) => c.url.includes('page=9000'))).toBe(false);
  });

  it('shows the request id on a generic list error', async () => {
    setupApp('/orders', {
      [ORDERS]: {
        status: 500,
        headers: { 'X-Request-Id': 'req-abcdef-01' },
        body: { message: 'Server Error' },
      },
    });
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Reference: req-abcdef-01',
    );
  });
});
