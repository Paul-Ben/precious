import Link from "next/link";
import { APP_NAME } from "@/lib/config";

export default function PublicLayout({ children }: LayoutProps<"/">) {
  return (
    <div className="flex min-h-screen flex-col">
      <header className="border-b border-border bg-surface/80 backdrop-blur">
        <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
          <Link href="/" className="font-[family-name:var(--font-display)] text-lg font-semibold tracking-tight">
            {APP_NAME}
          </Link>
          <nav className="flex items-center gap-2 text-sm" aria-label="Main">
            <Link href="/login" className="rounded-lg px-3 py-2 font-medium hover:bg-surface-muted">
              Sign in
            </Link>
            <Link href="/register" className="rounded-lg bg-brand px-3 py-2 font-medium text-brand-foreground">
              Create account
            </Link>
          </nav>
        </div>
      </header>
      <main id="main" className="flex-1">
        {children}
      </main>
      <footer className="border-t border-border py-6 text-center text-xs text-muted">
        © {new Date().getFullYear()} {APP_NAME} ·{" "}
        <Link href="/staff/login" className="underline underline-offset-4">
          Staff sign in
        </Link>
      </footer>
    </div>
  );
}
