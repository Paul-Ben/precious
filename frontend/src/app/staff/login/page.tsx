import type { Metadata } from "next";
import Link from "next/link";
import { Suspense } from "react";
import { AuthShell } from "@/features/auth/auth-shell";
import { LoginForm } from "@/features/auth/login-form";

export const metadata: Metadata = { title: "Staff sign in", robots: { index: false } };

export default function StaffLoginPage() {
  return (
    <AuthShell
      eyebrow="Staff portal"
      title="Staff sign in"
      subtitle="Administrators will be asked for a code sent to their email."
      footer={
        <Link href="/login" className="underline underline-offset-4">
          Guest sign in
        </Link>
      }
    >
      <Suspense>
        <LoginForm portal="staff" />
      </Suspense>
    </AuthShell>
  );
}
