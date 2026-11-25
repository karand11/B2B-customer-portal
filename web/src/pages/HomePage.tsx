import { useAuth } from '../auth/AuthContext';

export default function HomePage() {
  const { user } = useAuth();
  if (!user) return null;
  return (
    <section>
      <h1 className="mb-2 text-2xl font-semibold">
        {user.role === 'customer' ? 'Customer home' : 'Staff home'}
      </h1>
      <p>
        Welcome, {user.name}. Catalog, quotes and orders arrive in later phases.
      </p>
    </section>
  );
}
