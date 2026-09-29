import type { User } from "@/lib/api/types";

/**
 * UI-only permission helpers. They hide controls the user cannot use; the
 * Laravel API remains the authority and enforces every permission itself.
 */
export function can(user: User | null | undefined, permission: string): boolean {
  if (!user) return false;
  if (user.is_super_admin) return true;
  return user.permissions?.includes(permission) ?? false;
}

export function canAny(user: User | null | undefined, permissions: string[]): boolean {
  return permissions.some((p) => can(user, p));
}

export function homeFor(user: Pick<User, "type" | "must_change_password">): string {
  if (user.must_change_password) return "/change-password";
  return user.type === "staff" ? "/staff/dashboard" : "/account";
}

/** Only allow same-site relative redirects (prevents open redirects). */
export function safeNext(next: string | null | undefined, fallback: string): string {
  if (!next || !next.startsWith("/") || next.startsWith("//") || next.startsWith("/\\")) {
    return fallback;
  }
  return next;
}
