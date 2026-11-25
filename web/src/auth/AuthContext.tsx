import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  type ReactNode,
} from 'react';
import {
  useQuery,
  useQueryClient,
  type QueryClient,
} from '@tanstack/react-query';
import { ApiError, api, csrfCookie, setUnauthorizedHandler } from '../lib/api';
import type { Me } from './types';

export const ME_KEY = ['me'] as const;
// Public data that survives a session reset.
const KEEP = new Set<unknown>([ME_KEY[0], 'health']);

interface AuthValue {
  user: Me | null;
  status: 'pending' | 'error' | 'ready';
  retry: () => void;
  login: (credentials: { email: string; password: string }) => Promise<void>;
  logout: () => Promise<void>;
}

const AuthContext = createContext<AuthValue | null>(null);

async function fetchMe(): Promise<Me | null> {
  try {
    const res = await api.get<{ data: Me }>('/api/v1/me', {
      skipUnauthorized: true,
    });
    return res.data;
  } catch (e) {
    if (e instanceof ApiError && e.status === 401) return null; // guest
    throw e;
  }
}

function endSession(qc: QueryClient) {
  qc.setQueryData(ME_KEY, null);
  qc.removeQueries({ predicate: (q) => !KEEP.has(q.queryKey[0]) });
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const qc = useQueryClient();
  const query = useQuery({ queryKey: ME_KEY, queryFn: fetchMe, retry: false });

  useEffect(() => {
    setUnauthorizedHandler(() => endSession(qc));
    return () => setUnauthorizedHandler(null);
  }, [qc]);

  const login = useCallback<AuthValue['login']>(
    async (credentials) => {
      await csrfCookie();
      const res = await api.post<{ data: Me }>(
        '/api/v1/auth/login',
        credentials,
      );
      qc.setQueryData(ME_KEY, res.data);
    },
    [qc],
  );

  const logout = useCallback(async () => {
    await api.post('/api/v1/auth/logout');
    endSession(qc);
    await csrfCookie().catch(() => {}); // token rotated; best effort
    // No navigate(): ProtectedRoute sees user=null and redirects to /login itself.
  }, [qc]);

  const { data, isError, refetch } = query;
  const value = useMemo<AuthValue>(
    () => ({
      user: data ?? null,
      status: data !== undefined ? 'ready' : isError ? 'error' : 'pending',
      retry: () => void refetch(),
      login,
      logout,
    }),
    [data, isError, refetch, login, logout],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider');
  return ctx;
}
