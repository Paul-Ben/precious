import { NextResponse, type NextRequest } from "next/server";

/**
 * Fast routing guard: send visitors without a session cookie to the right
 * sign-in page. Real authorisation happens in the Laravel API; layouts also
 * verify the session server-side.
 */

const SESSION_COOKIE = process.env.SESSION_COOKIE_NAME || "hp_session";

export function proxy(request: NextRequest) {
  const { pathname, search } = request.nextUrl;
  const hasSession = Boolean(request.cookies.get(SESSION_COOKIE)?.value);

  if (hasSession) return NextResponse.next();

  const loginPath = pathname.startsWith("/staff") ? "/staff/login" : "/login";
  const url = new URL(loginPath, request.url);
  url.searchParams.set("next", `${pathname}${search}`);

  return NextResponse.redirect(url);
}

export const config = {
  matcher: [
    "/account/:path*",
    "/change-password",
    // Everything under /staff except the staff login page.
    "/staff/((?!login).*)",
    "/staff",
  ],
};
