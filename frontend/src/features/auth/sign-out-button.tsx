"use client";

import { useQueryClient } from "@tanstack/react-query";
import { LogOut } from "lucide-react";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { api } from "@/lib/api/client";
import { cn } from "@/lib/utils";

export function SignOutButton({ redirectTo = "/login", className }: { redirectTo?: string; className?: string }) {
  const router = useRouter();
  const queryClient = useQueryClient();
  const [pending, setPending] = useState(false);

  async function signOut() {
    setPending(true);
    try {
      await api.post("auth/logout");
    } catch {
      // The BFF clears the session cookie even if the API call fails.
    }
    queryClient.clear();
    router.replace(redirectTo);
    router.refresh();
  }

  return (
    <button
      type="button"
      onClick={signOut}
      disabled={pending}
      className={cn(
        "inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-muted hover:bg-surface-muted hover:text-foreground",
        className,
      )}
    >
      <LogOut className="size-4" aria-hidden="true" />
      {pending ? "Signing out…" : "Sign out"}
    </button>
  );
}
