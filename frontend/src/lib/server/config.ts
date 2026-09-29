import "server-only";

/** Server-only configuration. Never import from client components. */
export const serverConfig = {
  apiBaseUrl: (process.env.API_BASE_URL || "http://localhost:8000/api/v1").replace(/\/+$/, ""),
  sessionCookie: process.env.SESSION_COOKIE_NAME || "hp_session",
  /** Non-sensitive hint cookie used by proxy.ts for routing only. */
  userTypeCookie: "hp_user_type",
  secureCookies: process.env.NODE_ENV === "production",
};
