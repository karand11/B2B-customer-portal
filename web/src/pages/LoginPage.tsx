import { Link, useLocation } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation } from '@tanstack/react-query';
import { z } from 'zod';
import { useAuth } from '../auth/AuthContext';
import { FormAlert, SubmitButton, TextField } from '../components/FormParts';

const schema = z.object({
  email: z
    .string()
    .min(1, 'Email is required')
    .email('Enter a valid email address'),
  password: z.string().min(1, 'Password is required'),
});
type Values = z.infer<typeof schema>;

export default function LoginPage() {
  const { login } = useAuth();
  // Set by the accept-invitation page after success.
  const notice = (useLocation().state as { notice?: unknown } | null)?.notice;
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<Values>({ resolver: zodResolver(schema) });
  // On success AuthContext updates `me`; GuestRoute then redirects to the safe `next`.
  const mutation = useMutation({ mutationFn: login });

  return (
    <section>
      <h1 className="mb-4 text-2xl font-semibold">Sign in</h1>
      {typeof notice === 'string' && (
        <p role="status" className="mb-4 text-green-900">
          {notice}
        </p>
      )}
      <form onSubmit={handleSubmit((v) => mutation.mutate(v))} noValidate>
        <TextField
          id="email"
          label="Email"
          type="email"
          autoComplete="username"
          error={errors.email?.message}
          {...register('email')}
        />
        <TextField
          id="password"
          label="Password"
          type="password"
          autoComplete="current-password"
          error={errors.password?.message}
          {...register('password')}
        />
        <FormAlert error={mutation.error} />
        <SubmitButton
          pending={mutation.isPending}
          idle="Sign in"
          busy="Signing in..."
        />
      </form>
      <p className="mt-4">
        <Link to="/forgot-password" className="text-blue-800 underline">
          Forgot your password?
        </Link>
      </p>
    </section>
  );
}
