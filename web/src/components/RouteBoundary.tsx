import { Component, Suspense, type ReactNode } from 'react';
import { useLocation } from 'react-router-dom';

/** Shown while a route's code loads. role="status" so it is announced politely. */
export function RouteLoading() {
  return (
    <div role="status" aria-busy="true" aria-label="Loading page">
      <p>Loading...</p>
      <div className="mt-2 h-24 animate-pulse motion-reduce:animate-none rounded bg-slate-200" />
    </div>
  );
}

class Boundary extends Component<{ children: ReactNode }, { failed: boolean }> {
  state = { failed: false };
  static getDerivedStateFromError() {
    return { failed: true };
  }
  render() {
    if (!this.state.failed) return this.props.children;
    // Typically a chunk that no longer exists after a new deploy, or a dropped connection.
    return (
      <div
        role="alert"
        className="rounded border border-red-700 bg-red-50 p-4 text-red-800"
      >
        <p>Could not load this page. Check your connection and try again.</p>
        <button
          type="button"
          onClick={() => window.location.reload()}
          className="mt-2 rounded bg-blue-700 px-3 py-1 text-white"
        >
          Reload page
        </button>
      </div>
    );
  }
}

/** Suspense for lazy routes plus a failure state; the error clears when the route changes. */
export default function RouteBoundary({ children }: { children: ReactNode }) {
  const { pathname } = useLocation();
  return (
    <Boundary key={pathname}>
      <Suspense fallback={<RouteLoading />}>{children}</Suspense>
    </Boundary>
  );
}
