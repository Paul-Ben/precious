import "server-only";

import { cache } from "react";
import type { ApiEnvelope, User } from "@/lib/api/types";
import { serverConfig } from "./config";
import { getSessionToken } from "./session";

/**
 * Server-side call to the Laravel API with the session token.
 * Used by Server Components (e.g. layouts that need the current user).
 */
export async function serverApi<T>(path: string): Promise<{ status: number; body: ApiEnvelope<T> | null }> {
  const token = await getSessionToken();

  try {
    const response = await fetch(`${serverConfig.apiBaseUrl}/${path.replace(/^\/+/, "")}`, {
      headers: {
        Accept: "application/json",
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      cache: "no-store",
    });

    const body = (await response.json().catch(() => null)) as ApiEnvelope<T> | null;
    return { status: response.status, body };
  } catch {
    return { status: 0, body: null };
  }
}

export type CurrentUserResult =
  | { state: "authenticated"; user: User }
  | { state: "guest" }
  | { state: "unavailable" };

/**
 * The signed-in user, or why there isn't one. Memoised per request.
 */
export const getCurrentUser = cache(async (): Promise<CurrentUserResult> => {
  const token = await getSessionToken();
  if (!token) return { state: "guest" };

  const { status, body } = await serverApi<User>("auth/me");

  if (status === 200 && body?.success) return { state: "authenticated", user: body.data };
  if (status === 401 || status === 403) return { state: "guest" };

  return { state: "unavailable" };
});
