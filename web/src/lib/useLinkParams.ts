import { useEffect, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';

/**
 * Reads the emailed-link secrets (token, email) once, then replaces the history
 * entry with the bare path so they leave the address bar, history and Referer.
 */
export function useLinkParams() {
  const { pathname, search } = useLocation();
  const navigate = useNavigate();
  const [link] = useState(() => {
    const p = new URLSearchParams(search);
    return { token: p.get('token'), email: p.get('email') };
  });
  useEffect(() => {
    if (search) navigate(pathname, { replace: true });
  }, [search, pathname, navigate]);
  return link;
}
