import { Link } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation } from '@tanstack/react-query';
import { z } from 'zod';
import { api } from '../lib/api';
import { FormAlert, SubmitButton, TextField } from '../components/FormParts';

const schema = z.object({
  email: z
    .string()
    .min(1, 'Email is required')
    .email('Enter a valid email address'),
});
type Values = z.infer<typeof schema>;

// Same wording whether or not the account exists (contract: non-revealing).
const GENERIC =
  'If an account exists for that email, a reset link has been sent.';

export default function ForgotPasswordPage() {
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<Values>({ resolver: zodResolver(schema) });
  const mutation = useMutation({
    mutationFn: (v: Values) => api.post('/api/v1/auth/forgot-password', v),
  });

  return (
    <section>
      <h1 className="mb-4 text-2xl font-semibold">Forgot password</h1>
      {mutation.isSuccess ? (
        <p role="status" aria-label="Reset link requested">
          {GENERIC}
        </p>
      ) : (
        <form onSubmit={handleSubmit((v) => mutation.mutate(v))} noValidate>
          <TextField
            id="email"
            label="Email"
            type="email"
            autoComplete="email"
            error={errors.email?.message}
            {...register('email')}
          />
          <FormAlert error={mutation.error} />
          <SubmitButton
            pending={mutation.isPending}
            idle="Send reset link"
            busy="Sending..."
          />
        </form>
      )}
      <p className="mt-4">
        <Link to="/login" className="text-blue-800 underline">
          Back to sign in
        </Link>
      </p>
    </section>
  );
}
