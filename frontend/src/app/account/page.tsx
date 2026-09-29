import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { ChangePasswordForm } from "@/features/auth/change-password-form";
import { MyReservations } from "@/features/booking/my-reservations";
import { SignOutButton } from "@/features/auth/sign-out-button";
import { APP_NAME } from "@/lib/config";
import { getCurrentUser } from "@/lib/server/api";

export const metadata: Metadata = { title: "My account", robots: { index: false } };

/**
 * Customer portal placeholder. Reservations, bills and payments are added in
 * later milestones.
 */
export default async function AccountPage() {
  const session = await getCurrentUser();

  if (session.state === "guest") redirect("/login?next=/account");
  if (session.state === "unavailable") {
    return <p className="p-8 text-center text-sm text-muted">We couldn&apos;t reach the server. Please refresh in a moment.</p>;
  }

  const { user } = session;
  if (user.must_change_password) redirect("/change-password");
  if (user.type === "staff") redirect("/staff/dashboard");

  return (
    <div className="min-h-screen">
      <header className="border-b border-border bg-surface">
        <div className="mx-auto flex h-16 max-w-4xl items-center justify-between px-4">
          <Link href="/" className="font-[family-name:var(--font-display)] text-lg font-semibold">
            {APP_NAME}
          </Link>
          <SignOutButton />
        </div>
      </header>
      <main id="main" className="mx-auto max-w-4xl space-y-6 px-4 py-8">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Hello, {user.name.split(" ")[0]}</h1>
          <p className="mt-1 text-sm text-muted">Your reservations, bills and receipts.</p>
        </div>
        <MyReservations />
        <Card>
          <CardHeader title="Profile" />
          <CardBody>
            <dl className="grid gap-4 text-sm sm:grid-cols-2">
              <div>
                <dt className="text-muted">Name</dt>
                <dd className="font-medium">{user.name}</dd>
              </div>
              <div>
                <dt className="text-muted">Email</dt>
                <dd className="font-medium">{user.email}</dd>
              </div>
              <div>
                <dt className="text-muted">Phone</dt>
                <dd className="font-medium">{user.phone ?? "—"}</dd>
              </div>
            </dl>
          </CardBody>
        </Card>
        <Card>
          <CardHeader title="Change password" />
          <CardBody className="max-w-md">
            <ChangePasswordForm />
          </CardBody>
        </Card>
      </main>
    </div>
  );
}
