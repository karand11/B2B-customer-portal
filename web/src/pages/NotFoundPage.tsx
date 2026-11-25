import { Link } from 'react-router-dom';

export default function NotFoundPage() {
  return (
    <section>
      <h1 className="mb-2 text-2xl font-semibold">Page not found</h1>
      <Link to="/" className="text-blue-800 underline">
        Back to home
      </Link>
    </section>
  );
}
