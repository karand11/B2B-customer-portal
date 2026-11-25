import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import App from './App';
import { ApiError } from './lib/api';
import './index.css';

// Client errors (401, 404, 422, 429) will not change on retry; retry network/5xx twice.
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: (count, err) =>
        count < 2 && !(err instanceof ApiError && err.status < 500),
    },
  },
});

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <App />
    </QueryClientProvider>
  </StrictMode>,
);
