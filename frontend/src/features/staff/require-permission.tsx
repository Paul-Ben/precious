"use client";

import type { ReactNode } from "react";
import { UnauthorizedState } from "@/components/ui/states";
import { useSession } from "./session-context";

/**
 * Hides a page section when the user lacks the permission. This is a UX aid
 * only - the API enforces the same permission on every request.
 */
export function RequirePermission({ permission, children }: { permission: string | string[]; children: ReactNode }) {
  const { canAny } = useSession();
  const list = Array.isArray(permission) ? permission : [permission];

  return canAny(list) ? <>{children}</> : <UnauthorizedState />;
}
