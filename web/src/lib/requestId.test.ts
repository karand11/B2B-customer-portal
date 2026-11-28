import { ApiError, api, describeError } from './api';
import { mockApi } from '../test/utils';

describe('request id (X-Request-Id)', () => {
  it('is kept on the thrown error and shown on generic error text', async () => {
    mockApi({
      'GET /api/v1/x': {
        status: 500,
        headers: { 'X-Request-Id': '6f1c2d3e-aaaa-bbbb-cccc-0123456789ab' },
        body: { message: 'Server Error' },
      },
    });
    const err = await api.get('/api/v1/x').catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).requestId).toBe(
      '6f1c2d3e-aaaa-bbbb-cccc-0123456789ab',
    );
    expect(describeError(err)).toBe(
      'Something went wrong. Please try again. Reference: 6f1c2d3e-aaaa-bbbb-cccc-0123456789ab',
    );
  });

  it('is null when the header is absent', async () => {
    mockApi({ 'GET /api/v1/x': { status: 500, body: { message: 'x' } } });
    const err = (await api
      .get('/api/v1/x')
      .catch((e: unknown) => e)) as ApiError;
    expect(err.requestId).toBeNull();
    expect(describeError(err)).toBe('Something went wrong. Please try again.');
  });

  it.each([
    '<img src=x onerror=1>',
    'short',
    'a'.repeat(65),
    'id with spaces!',
  ])(
    'drops an id that is not plain text of the documented shape: %j',
    async (bad) => {
      mockApi({
        'GET /api/v1/x': {
          status: 500,
          headers: { 'X-Request-Id': bad },
          body: {},
        },
      });
      const err = (await api
        .get('/api/v1/x')
        .catch((e: unknown) => e)) as ApiError;
      expect(err.requestId).toBeNull();
      expect(describeError(err)).not.toContain('Reference');
    },
  );

  it('is not appended to validation or rate-limit messages', () => {
    const id = 'abcdef12-3456';
    expect(
      describeError(
        new ApiError(422, 'm', { email: ['Bad email.'] }, null, {
          requestId: id,
        }),
      ),
    ).toBe('Bad email.');
    expect(
      describeError(new ApiError(429, 'm', {}, 5, { requestId: id })),
    ).toBe('Too many attempts. Please try again in 5 seconds.');
  });

  it('keeps the JSON body (the checkout 409 carries the fresh cart)', async () => {
    mockApi({
      'GET /api/v1/x': {
        status: 409,
        body: { message: 'm', errors: {}, cart: { subtotal_cents: 5 } },
      },
    });
    const err = (await api
      .get('/api/v1/x')
      .catch((e: unknown) => e)) as ApiError;
    expect(err.body).toMatchObject({ cart: { subtotal_cents: 5 } });
  });
});
