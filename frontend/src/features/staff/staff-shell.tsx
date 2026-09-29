"use client";

import { Menu, UserCircle2, X } from "lucide-react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useState, type ReactNode } from "react";
import { Badge } from "@/components/ui/badge";
import { SignOutButton } from "@/features/auth/sign-out-button";
import { APP_NAME } from "@/lib/config";
import { cn, initials } from "@/lib/utils";
import { staffNavigation } from "./navigation";
import { useSession } from "./session-context";

function NavLinks({ onNavigate }: { onNavigate?: () => void }) {
  const pathname = usePathname();
  const { canAny } = useSession();

  return (
    <nav aria-label="Staff" className="space-y-6">
      {staffNavigation.map((section) => {
        const items = section.items.filter((item) => item.permissions.length === 0 || canAny(item.permissions));
        if (items.length === 0) return null;

        return (
          <div key={section.title}>
            <p className="px-3 pb-2 text-xs font-medium uppercase tracking-wider text-muted">{section.title}</p>
            <ul className="space-y-1">
              {items.map((item) => {
                const active = pathname === item.href || pathname.startsWith(`${item.href}/`);
                const Icon = item.icon;

                if (item.soon) {
                  return (
                    <li key={item.href}>
                      <span
                        className="flex cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-muted/70"
                        aria-disabled="true"
                      >
                        <Icon className="size-4" aria-hidden="true" />
                        {item.label}
                        <Badge className="ml-auto">Soon</Badge>
                      </span>
                    </li>
                  );
                }

                return (
                  <li key={item.href}>
                    <Link
                      href={item.href}
                      onClick={onNavigate}
                      aria-current={active ? "page" : undefined}
                      className={cn(
                        "flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition",
                        active ? "bg-brand text-brand-foreground" : "text-foreground hover:bg-surface-muted",
                      )}
                    >
                      <Icon className="size-4" aria-hidden="true" />
                      {item.label}
                    </Link>
                  </li>
                );
              })}
            </ul>
          </div>
        );
      })}
    </nav>
  );
}

export function StaffShell({ children }: { children: ReactNode }) {
  const { user } = useSession();
  const [open, setOpen] = useState(false);

  const brand = (
    <Link href="/staff/dashboard" className="block px-3 font-[family-name:var(--font-display)] text-lg font-semibold">
      {APP_NAME}
      <span className="block font-sans text-xs font-normal text-muted">Staff portal</span>
    </Link>
  );

  return (
    <div className="min-h-screen lg:grid lg:grid-cols-[260px_1fr] print:block">
      {/* Desktop sidebar */}
      <aside className="hidden border-r print:hidden border-border bg-surface lg:flex lg:flex-col">
        <div className="py-5">{brand}</div>
        <div className="flex-1 overflow-y-auto px-3 pb-6">
          <NavLinks />
        </div>
      </aside>

      {/* Mobile drawer */}
      {open && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <button type="button" className="absolute inset-0 bg-black/40" aria-label="Close menu" onClick={() => setOpen(false)} />
          <aside className="relative flex h-full w-72 max-w-[85%] flex-col bg-surface shadow-xl">
            <div className="flex items-center justify-between py-5 pr-3">
              {brand}
              <button type="button" onClick={() => setOpen(false)} className="rounded-md p-2 hover:bg-surface-muted" aria-label="Close menu">
                <X className="size-5" />
              </button>
            </div>
            <div className="flex-1 overflow-y-auto px-3 pb-6">
              <NavLinks onNavigate={() => setOpen(false)} />
            </div>
          </aside>
        </div>
      )}

      <div className="flex min-w-0 flex-col">
        <header className="print:hidden sticky top-0 z-30 flex h-16 items-center justify-between gap-3 border-b border-border bg-surface/90 px-4 backdrop-blur sm:px-6">
          <button
            type="button"
            onClick={() => setOpen(true)}
            className="rounded-md p-2 hover:bg-surface-muted lg:hidden"
            aria-label="Open menu"
            aria-expanded={open}
          >
            <Menu className="size-5" />
          </button>
          <div className="ml-auto flex items-center gap-2">
            <Link
              href="/staff/account"
              className="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-surface-muted"
              title="My account"
            >
              <span
                className="flex size-8 items-center justify-center rounded-full bg-accent/20 text-xs font-semibold"
                aria-hidden="true"
              >
                {initials(user.name) || <UserCircle2 className="size-4" />}
              </span>
              <span className="hidden text-left sm:block">
                <span className="block font-medium leading-tight">{user.name}</span>
                <span className="block text-xs leading-tight text-muted">{user.roles.join(", ")}</span>
              </span>
            </Link>
            <SignOutButton redirectTo="/staff/login" />
          </div>
        </header>
        <main id="main" className="flex-1 px-4 py-6 sm:px-6 lg:px-8">
          {children}
        </main>
      </div>
    </div>
  );
}
