import * as path from 'node:path';

// One place for ports, URLs, the database name and the demo accounts.
// The e2e stack is isolated: API 8100, Vite 5273, database portal_e2e (never 8000, 5173, portal or portal_test).
export const WEB_URL = 'http://localhost:5273';
export const API_ORIGIN = 'http://127.0.0.1:8100';
export const DB_NAME = 'portal_e2e';
export const MAILPIT_URL = process.env.E2E_MAILPIT_URL ?? 'http://127.0.0.1:8025';

export const E2E_DIR = path.resolve(__dirname, '..');
export const API_DIR = path.resolve(E2E_DIR, '..', 'api');
export const AUTH_DIR = path.join(E2E_DIR, '.auth');
export const DATA_FILE = path.join(E2E_DIR, '.data', 'product.json');

// Override when php is not on PATH.
export const PHP = process.env.E2E_PHP ?? 'php';

// Published demo credentials (local/testing seeders only).
export const PASSWORD = 'Demo-Passw0rd!';
export const USERS = {
  customer: { email: 'alice@northwind.test', name: 'Alice Customer' },
  staff: { email: 'staff@portal.test', name: 'Sam Staff' },
  admin: { email: 'admin@portal.test', name: 'Ada Admin' },
} as const;
export type Role = keyof typeof USERS;
export const statePath = (role: Role) => path.join(AUTH_DIR, `${role}.json`);

/**
 * Environment of the API (php -S and every artisan call). Real environment variables beat api/.env
 * (Laravel's loader is immutable), so these cannot be redirected by a developer's .env.
 * global-setup also checks the resulting connection before anything destructive runs.
 * Windows drops empty variables, hence the string 'null' (Laravel's env() turns it into null).
 */
export const API_ENV: Record<string, string> = {
  APP_ENV: 'local', // the demo seeders only run in local/testing
  APP_DEBUG: 'false',
  APP_URL: API_ORIGIN,
  APP_URL_WEB: WEB_URL,
  SANCTUM_STATEFUL_DOMAINS: 'localhost:5273',
  CORS_ALLOWED_ORIGINS: WEB_URL,
  DB_CONNECTION: 'pgsql',
  DB_URL: 'null',
  DB_HOST: '127.0.0.1',
  DB_PORT: '5433',
  DB_DATABASE: DB_NAME,
  DB_USERNAME: 'portal',
  DB_PASSWORD: 'secret', // public docker-compose dev credential, same as CI
  SESSION_DRIVER: 'database',
  SESSION_DOMAIN: 'null',
  SESSION_SECURE_COOKIE: 'false',
  CACHE_STORE: 'array', // limiters and the catalog cache are inert; limits are proven by Pest
  QUEUE_CONNECTION: 'sync', // no worker needed
  MAIL_MAILER: process.env.E2E_MAIL_MAILER ?? 'smtp', // Mailpit; 'log' when no Mailpit is running
  MAIL_SCHEME: 'null',
  MAIL_HOST: '127.0.0.1',
  MAIL_PORT: '1025',
  BCRYPT_ROUNDS: '4', // faster logins; seeded hashes use the same value
  LOG_CHANNEL: 'stderr',
  LOG_LEVEL: 'error',
  // Not supported on Windows; Linux CI benefits (the SPA fires parallel requests).
  ...(process.platform === 'win32' ? {} : { PHP_CLI_SERVER_WORKERS: '4' }),
};
