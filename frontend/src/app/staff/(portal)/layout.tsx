import { redirect } from "next/navigation";
import { SessionProvider } from "@/features/staff/session-context";
import { StaffShell } from "@/features/staff/staff-shell";
import { getCurrentUser } from "@/lib/server/api";

export default async function StaffPortalLayout({ children }: { children: React.ReactNode }) {
  const session = await getCurrentUser();

  if (session.state === "guest") redirect("/staff/login");
  if (session.state === "unavailable") {
    return (
      <main id="main" className="flex min-h-screen items-center justify-center p-8 text-center">
        <div className="space-y-2">
          <h1 className="text-xl font-semibold">Service unavailable</h1>
          <p className="text-sm text-muted">We couldn&apos;t reach the server. Please refresh in a moment.</p>
        </div>
      </main>
    );
  }

  const { user } = session;
  if (user.type !== "staff") redirect("/account");
  if (user.must_change_password) redirect("/change-password");

  return (
    <SessionProvider user={user}>
      <StaffShell>{children}</StaffShell>
    </SessionProvider>
  );
}
