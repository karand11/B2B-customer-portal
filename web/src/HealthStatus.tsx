import { useQuery } from '@tanstack/react-query';

interface Health {
  status: 'ok';
}

async function fetchHealth(): Promise<Health> {
  const res = await fetch('/api/v1/health', {
    headers: { Accept: 'application/json' },
  });
  if (!res.ok) throw new Error(`Health check failed: ${res.status}`);
  const body: unknown = await res.json();
  if ((body as { status?: unknown } | null)?.status !== 'ok') {
    throw new Error('Unexpected health response');
  }
  return { status: 'ok' };
}

export default function HealthStatus() {
  const { data, isPending, isError } = useQuery({
    queryKey: ['health'],
    queryFn: fetchHealth,
    retry: false,
  });

  // Not live regions: a footer that re-announces on every page load or refetch is noise.
  // Only a failure is announced.
  if (isPending) return <p>Checking API status...</p>;
  if (isError || !data)
    return (
      <p role="alert" className="text-red-700">
        API unavailable. Please try again later.
      </p>
    );
  return <p>API status: {data.status}</p>;
}
