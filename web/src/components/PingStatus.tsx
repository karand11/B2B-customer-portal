import { useQuery } from '@tanstack/react-query';
import { api } from '../lib/api';

/** Proves a role-guarded endpoint answers; also the template for loading/error states. */
export default function PingStatus({
  path,
  label,
}: {
  path: string;
  label: string;
}) {
  const { data, isPending, isError, refetch } = useQuery({
    queryKey: ['ping', path],
    queryFn: () => api.get<{ status: string }>(path),
    retry: false,
  });
  if (isPending)
    return (
      <p role="status" aria-label="Loading">
        Checking {label}...
      </p>
    );
  if (isError)
    return (
      <div role="alert" className="text-red-800">
        Could not load {label} status.{' '}
        <button
          type="button"
          onClick={() => void refetch()}
          className="underline"
        >
          Retry
        </button>
      </div>
    );
  return (
    <p>
      {label}: {data.status}
    </p>
  );
}
