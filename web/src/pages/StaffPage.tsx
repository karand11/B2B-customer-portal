import PingStatus from '../components/PingStatus';

export default function StaffPage() {
  return (
    <section>
      <h1 className="mb-2 text-2xl font-semibold">Staff home</h1>
      <PingStatus path="/api/v1/staff/ping" label="Staff API" />
    </section>
  );
}
