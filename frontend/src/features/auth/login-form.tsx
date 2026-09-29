"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { api } from "@/lib/api/client";
import { errorMessage, isApiError } from "@/lib/api/errors";
import type { LoginResult, TwoFactorChallenge, User } from "@/lib/api/types";
import { homeFor, safeNext } from "@/lib/auth/permissions";
import { applyServerErrors } from "@/lib/forms";
import { loginSchema, otpSchema, type LoginValues, type OtpValues } from "@/lib/validation/auth";

type Portal = "customer" | "staff";

/**
 * Two-step sign-in: credentials, then (when required) the 6-digit code that
 * was emailed to the user.
 */
export function LoginForm({ portal }: { portal: Portal }) {
  const [challenge, setChallenge] = useState<TwoFactorChallenge | null>(null);
  const router = useRouter();
  const params = useSearchParams();
  const queryClient = useQueryClient();

  function finish(user: User) {
    queryClient.clear();
    const fallback = homeFor(user);
    const target = user.must_change_password ? "/change-password" : safeNext(params.get("next"), fallback);
    router.replace(target);
    router.refresh();
  }

  if (challenge) {
    return <OtpStep challenge={challenge} onChallenge={setChallenge} onBack={() => setChallenge(null)} onSuccess={finish} />;
  }

  return <CredentialsStep portal={portal} onTwoFactor={setChallenge} onSuccess={finish} />;
}

function CredentialsStep({
  portal,
  onTwoFactor,
  onSuccess,
}: {
  portal: Portal;
  onTwoFactor: (c: TwoFactorChallenge) => void;
  onSuccess: (u: User) => void;
}) {
  const [formError, setFormError] = useState<string | null>(null);
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<LoginValues>({ resolver: zodResolver(loginSchema), defaultValues: { email: "", password: "" } });

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null);
    try {
      const res = await api.post<LoginResult>(portal === "staff" ? "auth/staff/login" : "auth/login", values);
      if (res.data.two_factor_required) onTwoFactor(res.data.two_factor);
      else onSuccess(res.data.user);
    } catch (error) {
      if (!applyServerErrors(error, setError, ["email", "password"])) setFormError(errorMessage(error));
    }
  });

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-4">
      {formError && <Alert tone="danger">{formError}</Alert>}
      <Field label="Email address" error={errors.email?.message} required>
        <Input type="email" autoComplete="username" inputMode="email" {...register("email")} />
      </Field>
      <Field label="Password" error={errors.password?.message} required>
        <Input type="password" autoComplete="current-password" {...register("password")} />
      </Field>
      <div className="flex justify-end">
        <Link href="/forgot-password" className="text-sm font-medium text-foreground underline-offset-4 hover:underline">
          Forgot password?
        </Link>
      </div>
      <Button type="submit" className="w-full" size="lg" loading={isSubmitting}>
        Sign in
      </Button>
    </form>
  );
}

function OtpStep({
  challenge,
  onChallenge,
  onBack,
  onSuccess,
}: {
  challenge: TwoFactorChallenge;
  onChallenge: (c: TwoFactorChallenge) => void;
  onBack: () => void;
  onSuccess: (u: User) => void;
}) {
  const [formError, setFormError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [cooldown, setCooldown] = useState(60);
  const [resending, setResending] = useState(false);
  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<OtpValues>({ resolver: zodResolver(otpSchema), defaultValues: { code: "" } });

  useEffect(() => {
    if (cooldown <= 0) return;
    const t = setTimeout(() => setCooldown((c) => c - 1), 1000);
    return () => clearTimeout(t);
  }, [cooldown]);

  const onSubmit = handleSubmit(async ({ code }) => {
    setFormError(null);
    setNotice(null);
    try {
      const res = await api.post<{ user: User }>("auth/two-factor/verify", { challenge_id: challenge.challenge_id, code });
      onSuccess(res.data.user);
    } catch (error) {
      if (isApiError(error) && (error.code === "TWO_FACTOR_EXPIRED" || error.code === "TWO_FACTOR_LOCKED")) {
        setFormError(error.message);
        return;
      }
      if (isApiError(error) && error.code === "TWO_FACTOR_INVALID") {
        const remaining = error.body.remaining_attempts as number | undefined;
        setError("code", {
          type: "server",
          message: remaining !== undefined ? `Incorrect code. ${remaining} attempt(s) left.` : error.message,
        });
        reset({ code: "" }, { keepErrors: true });
        return;
      }
      if (!applyServerErrors(error, setError, ["code"])) setFormError(errorMessage(error));
    }
  });

  async function resend() {
    setResending(true);
    setFormError(null);
    try {
      const res = await api.post<{ two_factor: TwoFactorChallenge }>("auth/two-factor/resend", {
        challenge_id: challenge.challenge_id,
      });
      onChallenge(res.data.two_factor);
      setNotice("We sent you a new code.");
      setCooldown(60);
    } catch (error) {
      if (isApiError(error) && typeof error.body.retry_after === "number") setCooldown(error.body.retry_after);
      setFormError(errorMessage(error));
    } finally {
      setResending(false);
    }
  }

  const locked = formError !== null && /sign in again/i.test(formError);

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-4">
      <p className="text-sm text-muted">
        We emailed a 6-digit code to <span className="font-medium text-foreground">{challenge.destination}</span>. It
        expires in 10 minutes.
      </p>
      {formError && <Alert tone="danger">{formError}</Alert>}
      {notice && <Alert tone="success">{notice}</Alert>}
      <Field label="Verification code" error={errors.code?.message} required>
        <Input
          autoFocus
          inputMode="numeric"
          autoComplete="one-time-code"
          maxLength={6}
          placeholder="••••••"
          className="text-center font-mono text-lg tracking-[0.5em]"
          {...register("code")}
        />
      </Field>
      <Button type="submit" className="w-full" size="lg" loading={isSubmitting} disabled={locked}>
        Verify and sign in
      </Button>
      <div className="flex items-center justify-between text-sm">
        <button type="button" onClick={onBack} className="font-medium underline-offset-4 hover:underline">
          Use a different account
        </button>
        <button
          type="button"
          onClick={resend}
          disabled={cooldown > 0 || resending || locked}
          className="font-medium underline-offset-4 hover:underline disabled:cursor-not-allowed disabled:text-muted disabled:no-underline"
        >
          {cooldown > 0 ? `Resend code in ${cooldown}s` : resending ? "Sending…" : "Resend code"}
        </button>
      </div>
    </form>
  );
}
