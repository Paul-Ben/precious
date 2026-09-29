import Link from "next/link";
import type { ReactNode } from "react";
import { APP_NAME } from "@/lib/config";

export function AuthShell({
  title,
  subtitle,
  children,
  footer,
  eyebrow,
}: {
  title: string;
  subtitle?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
  eyebrow?: string;
}) {
  return (
    <div className="flex min-h-screen flex-col items-center justify-center px-4 py-10">
      <Link href="/" className="mb-8 font-[family-name:var(--font-display)] text-xl font-semibold tracking-tight">
        {APP_NAME}
      </Link>
      <main id="main" className="w-full max-w-md rounded-2xl border border-border bg-surface p-6 shadow-sm sm:p-8">
        {eyebrow && <p className="mb-2 text-xs font-medium uppercase tracking-widest text-accent">{eyebrow}</p>}
        <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-muted">{subtitle}</p>}
        <div className="mt-6">{children}</div>
      </main>
      {footer && <div className="mt-6 text-center text-sm text-muted">{footer}</div>}
    </div>
  );
}
