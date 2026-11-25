import { Link } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext';

export default function HomePage() {
  const { user } = useAuth();
  if (!user) return null;
  return (
    <section>
      <h1 className="mb-2 text-2xl font-semibold">
        {user.role === 'customer' ? 'Customer home' : 'Staff home'}
      </h1>
      <p>Welcome, {user.name}. Quotes arrive in a later phase.</p>
      <p className="mt-2">
        <Link to="/catalog" className="text-blue-800 underline">
          Browse the product catalog
        </Link>
      </p>
    </section>
  );
}
