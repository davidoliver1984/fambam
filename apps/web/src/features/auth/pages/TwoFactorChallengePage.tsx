import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";

import { toLaravelFieldErrors } from "@/api/errors";

import { useTwoFactorChallengeMutation } from "../hooks/useTwoFactorMutations";
import {
  twoFactorChallengeSchema,
  type TwoFactorChallengeFields,
} from "../validation/twoFactorChallengeSchema";

export function TwoFactorChallengePage() {
  const mutation = useTwoFactorChallengeMutation();
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<TwoFactorChallengeFields>({
    resolver: zodResolver(twoFactorChallengeSchema),
    defaultValues: { code: "", recovery_code: "" },
  });
  const submit = handleSubmit(async ({ code, recovery_code }) => {
    try {
      await mutation.mutateAsync(code !== "" ? { code } : { recovery_code });
      window.location.assign("/account");
    } catch (error) {
      const fields = toLaravelFieldErrors(error);
      if (fields.code !== undefined) setError("code", { message: fields.code });
      if (fields.recovery_code !== undefined)
        setError("recovery_code", { message: fields.recovery_code });
    }
  });

  return (
    <main className="auth" aria-labelledby="page-title">
      <p className="eyebrow">fambam</p>
      <h1 id="page-title">Authenticator check</h1>
      <p>
        Enter the code from your authenticator app, or use one recovery code.
      </p>
      <form onSubmit={(event) => void submit(event)}>
        <label htmlFor="two-factor-code">Six-digit code</label>
        <input
          id="two-factor-code"
          inputMode="numeric"
          autoComplete="one-time-code"
          aria-describedby={
            errors.code ? "two-factor-challenge-error" : undefined
          }
          {...register("code")}
        />
        <label htmlFor="recovery-code">Recovery code</label>
        <input
          id="recovery-code"
          autoComplete="one-time-code"
          aria-describedby={
            errors.recovery_code ? "recovery-code-error" : undefined
          }
          {...register("recovery_code")}
        />
        {errors.code && (
          <p id="two-factor-challenge-error" role="alert">
            {errors.code.message}
          </p>
        )}
        {errors.recovery_code && (
          <p id="recovery-code-error" role="alert">
            {errors.recovery_code.message}
          </p>
        )}
        {mutation.isError && <p role="alert">That code was not accepted.</p>}
        <button type="submit" disabled={mutation.isPending}>
          Complete sign in
        </button>
      </form>
    </main>
  );
}
