import "server-only";

import { cookies } from "next/headers";
import type { NextResponse } from "next/server";
import { serverConfig } from "./config";

export async function getSessionToken(): Promise<string | undefined> {
  const store = await cookies();
  return store.get(serverConfig.sessionCookie)?.value;
}

/**
 * Store the API token in an httpOnly cookie. JavaScript in the browser can
 * never read it, which protects it from XSS.
 */
export function setSessionCookies(
  response: NextResponse,
  token: string,
  userType: string,
  expiresAt?: string,
): void {
  const expires = expiresAt ? new Date(expiresAt) : undefined;
  const base = {
    path: "/",
    sameSite: "lax" as const,
    secure: serverConfig.secureCookies,
    expires: expires && !Number.isNaN(expires.getTime()) ? expires : undefined,
  };

  response.cookies.set(serverConfig.sessionCookie, token, { ...base, httpOnly: true });
  response.cookies.set(serverConfig.userTypeCookie, userType, { ...base, httpOnly: true });
}

export function clearSessionCookies(response: NextResponse): void {
  response.cookies.delete(serverConfig.sessionCookie);
  response.cookies.delete(serverConfig.userTypeCookie);
}
