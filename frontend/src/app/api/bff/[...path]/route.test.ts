import { NextRequest } from "next/server";
import { beforeEach, describe, expect, it, vi } from "vitest";

const cookieJar = new Map<string, string>();

vi.mock("next/headers", () => ({
  cookies: async () => ({
    get: (name: string) => (cookieJar.has(name) ? { name, value: cookieJar.get(name)! } : undefined),
  }),
}));

// serverConfig is read at import time.
process.env.API_BASE_URL = "http://api.test/api/v1";
const { POST, GET } = await import("./route");

function ctx(path: string[]) {
  return { params: Promise.resolve({ path }) };
}

function upstream(body: unknown, status = 200) {
  return vi.spyOn(globalThis, "fetch").mockResolvedValue(
    new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json", "X-Request-Id": "req-1" } }),
  );
}

describe("BFF proxy", () => {
  beforeEach(() => {
    cookieJar.clear();
  });

  it("moves an issued token into an httpOnly cookie and strips it from the body", async () => {
    upstream({
      success: true,
      message: "Signed in.",
      data: { two_factor_required: false, token: "secret-token", token_type: "Bearer", expires_at: "2030-01-01T00:00:00Z", user: { type: "staff" } },
      meta: {},
    });

    const req = new NextRequest("http://app.test/api/bff/auth/staff/login", {
      method: "POST",
      headers: { origin: "http://app.test", host: "app.test", "content-type": "application/json" },
      body: JSON.stringify({ email: "a@b.c", password: "x" }),
    });

    const res = await POST(req, ctx(["auth", "staff", "login"]));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.data.token).toBeUndefined();
    expect(body.data.token_type).toBeUndefined();
    expect(JSON.stringify(body)).not.toContain("secret-token");

    const cookie = res.headers.get("set-cookie") ?? "";
    expect(cookie).toContain("hp_session=secret-token");
    expect(cookie.toLowerCase()).toContain("httponly");
    expect(res.headers.get("x-request-id")).toBe("req-1");
  });

  it("attaches the session token as a bearer header", async () => {
    cookieJar.set("hp_session", "abc");
    const fetchMock = upstream({ success: true, message: "OK", data: {}, meta: {} });

    await GET(new NextRequest("http://app.test/api/bff/users?search=ada"), ctx(["users"]));

    const [url, init] = fetchMock.mock.calls[0]!;
    expect(url).toBe("http://api.test/api/v1/users?search=ada");
    expect(new Headers(init!.headers).get("authorization")).toBe("Bearer abc");
  });

  it("rejects cross-site state-changing requests", async () => {
    const fetchMock = vi.spyOn(globalThis, "fetch");
    const req = new NextRequest("http://app.test/api/bff/users", {
      method: "POST",
      headers: { origin: "http://evil.test", host: "app.test" },
      body: "{}",
    });

    const res = await POST(req, ctx(["users"]));

    expect(res.status).toBe(403);
    expect((await res.json()).code).toBe("CSRF_REJECTED");
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("rejects path traversal", async () => {
    const res = await GET(new NextRequest("http://app.test/api/bff/x"), ctx(["..", "admin"]));
    expect(res.status).toBe(400);
  });

  it("clears the session when the API says the token is invalid", async () => {
    cookieJar.set("hp_session", "expired");
    upstream({ success: false, message: "Unauthenticated.", code: "UNAUTHENTICATED" }, 401);

    const res = await GET(new NextRequest("http://app.test/api/bff/auth/me"), ctx(["auth", "me"]));

    expect(res.status).toBe(401);
    expect(res.headers.get("set-cookie") ?? "").toMatch(/hp_session=;/);
  });

  it("returns a friendly error when the API is unreachable", async () => {
    vi.spyOn(globalThis, "fetch").mockRejectedValue(new Error("ECONNREFUSED"));

    const res = await GET(new NextRequest("http://app.test/api/bff/health"), ctx(["health"]));

    expect(res.status).toBe(502);
    expect((await res.json()).code).toBe("UPSTREAM_UNAVAILABLE");
  });
});
