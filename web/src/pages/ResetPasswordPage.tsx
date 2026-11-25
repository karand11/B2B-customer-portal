import { Link, useSearchParams } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation } from '@tanstack/react-query';
import { z } from 'zod';
import { api } from '../lib/api';
import { FormAlert, SubmitButton, TextField } from '../components/FormParts';

const schema = z
  .object({
    password: z
      .string()
      .min(10, 'Password must be at least 10 characters')
      .max(255, 'Password must be at most 255 characters'),
    password_confirmation: z.string().min(1, 'Please confirm your password'),
  })
  .refine((v) => v.password === v.password_confirmation, {
    path: ['password_confirmation'],
    message: 'Passwords do not match',
  });
type Values = z.infer<typeof schema>;

const INVALID = 'This password reset link is invalid or has expired.';

export default function ResetPasswordPage() {
  const [params] = useSearchParams();
  const token = params.get('token');
  const email = params.get('email');
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<Values>({ resolver: zodResolver(schema) });
  const mutation = useMutation({
    mutationFn: (v: Values) =>
      api.post('/api/v1/auth/reset-password', { token, email, ...v }),
  });

  if (!token || !email)
    return (
      <section>
        <h1 className="mb-4 text-2xl font-semibold">Reset password</h1>
        <p role="alert" className="mb-4 text-red-800">
          {INVALID}
        </p>
        <Link to="/forgot-password" className="text-blue-800 underline">
          Request a new link
        </Link>
      </section>
    );

  if (mutation.isSuccess)
    return (
      <section>
        <h1 className="mb-4 text-2xl font-semibold">Reset password</h1>
        <p role="status" aria-label="Password reset" className="mb-4">
          Your password has been reset. You can now sign in.
        </p>
        <Link to="/login" className="text-blue-800 underline">
          Sign in
        </Link>
      </section>
    );

  return (
    <section>
      <h1 className="mb-4 text-2xl font-semibold">Reset password</h1>
      <form onSubmit={handleSubmit((v) => mutation.mutate(v))} noValidate>
        <TextField
          id="password"
          label="New password"
          type="password"
          autoComplete="new-password"
          error={errors.password?.message}
          {...register('password')}
        />
        <TextField
          id="password_confirmation"
          label="Confirm new password"
          type="password"
          autoComplete="new-password"
          error={errors.password_confirmation?.message}
          {...register('password_confirmation')}
        />
        <FormAlert error={mutation.error} />
        <SubmitButton
          pending={mutation.isPending}
          idle="Reset password"
          busy="Resetting..."
        />
      </form>
    </section>
  );
}
