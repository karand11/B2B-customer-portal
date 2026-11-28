/// <reference types="vitest/config" />
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { SPA_SECURITY_HEADERS } from './security-headers.ts';

// The e2e setup points the dev proxy at its own API port.
const apiTarget = process.env.API_PROXY_TARGET ?? 'http://localhost:8000';

export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    proxy: {
      '/api': apiTarget,
      '/sanctum': apiTarget,
    },
  },
  build: {
    rolldownOptions: {
      output: {
        // Stable vendor chunks: they keep their hash (and the browser cache) across app
        // deploys. Route code is split by React.lazy in AppRoutes.tsx.
        codeSplitting: {
          groups: [
            {
              name: 'react',
              test: /node_modules[\\/](react|react-dom|scheduler)[\\/]/,
            },
            {
              name: 'router',
              test: /node_modules[\\/](react-router|react-router-dom)[\\/]/,
            },
            {
              name: 'forms',
              test: /node_modules[\\/](react-hook-form|@hookform|zod)[\\/]/,
            },
            { name: 'query', test: /node_modules[\\/]@tanstack[\\/]/ },
          ],
        },
      },
    },
  },
  // Enforced on the production build (e2e runs `vite preview`); dev sends none (HMR).
  preview: { headers: SPA_SECURITY_HEADERS },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    globals: true,
    // userEvent typing through debounced filters is slow when many test files run in parallel.
    testTimeout: 15_000,
  },
});
