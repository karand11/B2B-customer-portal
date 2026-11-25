import PingStatus from '../components/PingStatus';

export default function AdminPage() {
  return (
    <section>
      <h1 className="mb-2 text-2xl font-semibold">Admin area</h1>
      <PingStatus path="/api/v1/admin/ping" label="Admin API" />
    </section>
  );
}
