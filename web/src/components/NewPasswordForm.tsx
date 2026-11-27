import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { FormAlert, SubmitButton, TextField } from './FormParts';

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
export type NewPasswordValues = z.infer<typeof schema>;

/** Shared by the reset-password and accept-invitation pages. */
export default function NewPasswordForm({
  onSubmit,
  pending,
  error,
  idle,
  busy,
}: {
  onSubmit: (v: NewPasswordValues) => void;
  pending: boolean;
  error: unknown;
  idle: string;
  busy: string;
}) {
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<NewPasswordValues>({ resolver: zodResolver(schema) });
  return (
    <form onSubmit={handleSubmit(onSubmit)} noValidate>
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
      <FormAlert error={error} />
      <SubmitButton pending={pending} idle={idle} busy={busy} />
    </form>
  );
}
