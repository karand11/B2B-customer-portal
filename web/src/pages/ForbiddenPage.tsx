import { Link } from 'react-router-dom';

export default function ForbiddenPage() {
  return (
    <section>
      <h1 className="mb-2 text-2xl font-semibold">Not allowed</h1>
      <p className="mb-4">You do not have permission to view this page.</p>
      <Link to="/" className="text-blue-800 underline">
        Back to home
      </Link>
    </section>
  );
}
