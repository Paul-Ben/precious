"use client";

import { useQuery } from "@tanstack/react-query";
import { createContext, useContext, type ReactNode } from "react";
import { api } from "@/lib/api/client";
import type { User } from "@/lib/api/types";
import { can, canAny } from "@/lib/auth/permissions";

const SessionContext = createContext<User | null>(null);

export const meQueryKey = ["me"] as const;

/**
 * Holds the signed-in user (loaded server-side by the layout) and keeps it
 * fresh so permission changes show up without a full reload.
 */
export function SessionProvider({ user, children }: { user: User; children: ReactNode }) {
  const { data } = useQuery({
    queryKey: meQueryKey,
    queryFn: async () => (await api.get<User>("auth/me")).data,
    initialData: user,
    staleTime: 60_000,
    refetchOnWindowFocus: true,
  });

  return <SessionContext.Provider value={data}>{children}</SessionContext.Provider>;
}

export function useSession() {
  const user = useContext(SessionContext);
  if (!user) throw new Error("useSession must be used inside <SessionProvider>.");

  return {
    user,
    can: (permission: string) => can(user, permission),
    canAny: (permissions: string[]) => canAny(user, permissions),
  };
}
