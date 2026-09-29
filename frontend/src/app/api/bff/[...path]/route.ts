import { NextResponse, type NextRequest } from "next/server";
import { serverConfig } from "@/lib/server/config";
import { clearSessionCookies, getSessionToken, setSessionCookies } from "@/lib/server/session";

/**
 * Backend-for-frontend proxy: /api/bff/<path> -> ${API_BASE_URL}/<path>
 *
 * - Attaches the API token from the httpOnly session cookie.
 * - Captures tokens returned by login endpoints into that cookie and strips
 *   them from the response, so browser JavaScript never sees a token.
 * - Rejects cross-site state-changing requests (CSRF protection).
 */

export const dynamic = "force-dynamic";

const TOKEN_ISSUING_PATHS = new Set([
  "auth/login",
  "auth/staff/login",
  "auth/register",
  "auth/two-factor/verify",
]);

const SAFE_SEGMENT = /^[A-Za-z0-9._-]+$/;
const FORWARDED_REQUEST_HEADERS = ["content-type", "accept-language", "user-agent", "x-request-id"];

function failure(status: number, code: string, message: string) {
  return NextResponse.json({ success: false, code, message }, { status });
}

function isSameOrigin(request: NextRequest): boolean {
  const origin = request.headers.get("origin");
  if (origin) {
    try {
      return new URL(origin).host === request.headers.get("host");
    } catch {
      return false;
    }
  }
  // Browsers always send Sec-Fetch-Site; non-browser clients without Origin are
  // not a CSRF vector (they cannot carry the victim's cookies).
  const site = request.headers.get("sec-fetch-site");
  return site === null || site === "same-origin";
}

async function handle(request: NextRequest, context: { params: Promise<{ path: string[] }> }) {
  const { path: segments } = await context.params;

  if (!segments.length || segments.some((s) => !SAFE_SEGMENT.test(s) || s === "." || s === "..")) {
    return failure(400, "BAD_PATH", "Invalid API path.");
  }

  const method = request.method.toUpperCase();
  if (method !== "GET" && method !== "HEAD" && !isSameOrigin(request)) {
    return failure(403, "CSRF_REJECTED", "Cross-site request rejected.");
  }

  const path = segments.join("/");
  const upstreamUrl = `${serverConfig.apiBaseUrl}/${path}${request.nextUrl.search}`;

  const headers = new Headers({ Accept: "application/json" });
  for (const name of FORWARDED_REQUEST_HEADERS) {
    const value = request.headers.get(name);
    if (value) headers.set(name, value);
  }
  const clientIp = request.headers.get("x-forwarded-for")?.split(",")[0]?.trim();
  if (clientIp) headers.set("X-Forwarded-For", clientIp);

  const token = await getSessionToken();
  if (token) headers.set("Authorization", `Bearer ${token}`);

  let upstream: Response;
  try {
    upstream = await fetch(upstreamUrl, {
      method,
      headers,
      body: method === "GET" || method === "HEAD" ? undefined : await request.text(),
      cache: "no-store",
      redirect: "manual",
    });
  } catch {
    const response = failure(502, "UPSTREAM_UNAVAILABLE", "The server is unreachable. Please try again shortly.");
    if (path === "auth/logout") clearSessionCookies(response);
    return response;
  }

  const text = await upstream.text();
  let body: Record<string, unknown> | null = null;
  try {
    body = text ? (JSON.parse(text) as Record<string, unknown>) : null;
  } catch {
    body = null;
  }

  if (!body) {
    const response = failure(
      upstream.status >= 400 ? upstream.status : 502,
      "BAD_UPSTREAM_RESPONSE",
      "Unexpected response from the server.",
    );
    if (path === "auth/logout" || upstream.status === 401) clearSessionCookies(response);
    return response;
  }

  // Capture issued tokens into the httpOnly cookie.
  let issued: { token: string; userType: string; expiresAt?: string } | null = null;
  const data = body.data as Record<string, unknown> | undefined;
  if (upstream.ok && TOKEN_ISSUING_PATHS.has(path) && data && typeof data.token === "string") {
    const user = data.user as { type?: string } | undefined;
    issued = {
      token: data.token,
      userType: user?.type ?? "customer",
      expiresAt: typeof data.expires_at === "string" ? data.expires_at : undefined,
    };
    const { token: _omit, token_type: _omitType, ...safe } = data;
    void _omit;
    void _omitType;
    body = { ...body, data: safe };
  }

  const response = NextResponse.json(body, { status: upstream.status });

  const requestId = upstream.headers.get("x-request-id");
  if (requestId) response.headers.set("X-Request-Id", requestId);
  response.headers.set("Cache-Control", "no-store");

  if (issued) {
    setSessionCookies(response, issued.token, issued.userType, issued.expiresAt);
  } else if (path === "auth/logout" || upstream.status === 401) {
    clearSessionCookies(response);
  }

  return response;
}

export const GET = handle;
export const POST = handle;
export const PUT = handle;
export const PATCH = handle;
export const DELETE = handle;
