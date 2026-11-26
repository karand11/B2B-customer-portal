import { screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
  admin,
  customer,
  guest,
  meOk,
  staff,
  type Spec,
} from '../../../test/utils';
import { LIST, ME, loc, setup } from './fixtures';
import userEvent from '@testing-library/user-event';

const nav = () => screen.findByRole('navigation', { name: /main/i });

describe('roles and routing', () => {
  it.each([
    ['customer', customer, ['Home', 'Catalog', 'Orders', 'Quotes', 'Cart']],
    ['staff', staff, ['Home', 'Catalog', 'Orders', 'Quotes', 'Staff area']],
    [
      'admin',
      admin,
      ['Home', 'Catalog', 'Orders', 'Quotes', 'Staff area', 'Admin area'],
    ],
  ])(
    '%s sees the Catalog nav item and can open /catalog',
    async (_n, user, links) => {
      setup('/catalog', { [ME]: meOk(user) });
      expect(
        await screen.findByRole('link', { name: '6205-2RS' }),
      ).toBeInTheDocument();
      const items = within(await nav())
        .getAllByRole('link')
        .map((a) => a.textContent);
      expect(items).toEqual(links);
      expect(
        screen.queryByRole('heading', { name: /not allowed/i }),
      ).toBeNull();
    },
  );

  it('Catalog nav link and the Home link navigate to /catalog', async () => {
    const u = userEvent.setup();
    setup('/');
    await u.click(
      await screen.findByRole('link', { name: 'Browse the product catalog' }),
    );
    expect(
      await screen.findByRole('heading', { name: 'Catalog' }),
    ).toBeInTheDocument();
    expect(loc()).toBe('/catalog');
  });

  it('a guest is redirected to /login with the query string kept in next', async () => {
    setup('/catalog?brand=SKF&page=2', { [ME]: guest });
    await screen.findByLabelText('Email');
    const url = new URL(loc()!, 'http://x');
    expect(url.pathname).toBe('/login');
    expect(url.searchParams.get('next')).toBe('/catalog?brand=SKF&page=2');
  });

  it('a guest on a product page is redirected too', async () => {
    setup('/catalog/17', { [ME]: guest });
    await screen.findByLabelText('Email');
    expect(new URL(loc()!, 'http://x').searchParams.get('next')).toBe(
      '/catalog/17',
    );
  });

  it('a 401 on a catalog call clears auth and goes to /login?next=', async () => {
    const unauth: Spec = { status: 401, body: { message: 'Unauthenticated.' } };
    setup('/catalog?brand=SKF', { [LIST]: unauth });
    await screen.findByLabelText('Email');
    const url = new URL(loc()!, 'http://x');
    expect(url.pathname).toBe('/login');
    expect(url.searchParams.get('next')).toBe('/catalog?brand=SKF');
    expect(screen.queryByRole('navigation', { name: /main/i })).toBeNull();
  });
});
