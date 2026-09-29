import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { AuthShell } from "@/features/auth/auth-shell";
import { ChangePasswordForm } from "@/features/auth/change-password-form";
import { SignOutButton } from "@/features/auth/sign-out-button";
import { getCurrentUser } from "@/lib/server/api";

export const metadata: Metadata = { title: "Change password", robots: { index: false } };

export default async function ChangePasswordPage() {
  const session = await getCurrentUser();

  if (session.state === "guest") redirect("/login");
  if (session.state === "unavailable") {
    return (
      <AuthShell title="Service unavailable">
        <p className="text-sm text-muted">We couldn&apos;t reach the server. Please refresh in a moment.</p>
      </AuthShell>
    );
  }

  const { user } = session;
  const forced = user.must_change_password;

  if (!forced) redirect(user.type === "staff" ? "/staff/account" : "/account");

  return (
    <AuthShell
      eyebrow={user.type === "staff" ? "Staff portal" : undefined}
      title="Set your own password"
      subtitle="You signed in with a temporary password. Choose a new one to continue."
      footer={<SignOutButton redirectTo={user.type === "staff" ? "/staff/login" : "/login"} />}
    >
      <ChangePasswordForm forced />
    </AuthShell>
  );
}
