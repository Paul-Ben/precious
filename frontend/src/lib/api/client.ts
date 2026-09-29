import { ApiError } from "./errors";
import type { ApiEnvelope, ApiFailure, ApiSuccess, Paginated, PaginationMeta } from "./types";

/**
 * Browser-side API client. All calls go through the Next.js BFF route
 * (/api/bff/*), which attaches the httpOnly session token server-side.
 * The token is never readable by JavaScript.
 */

const BFF_PREFIX = "/api/bff";

type Query = Record<string, string | number | boolean | null | undefined>;

export interface RequestOptions {
  query?: Query;
  body?: unknown;
  signal?: AbortSignal;
}

function buildUrl(path: string, query?: Query): string {
  const clean = path.replace(/^\/+/, "");
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== null && value !== "") {
      params.set(key, String(value));
    }
  }
  const qs = params.toString();
  return `${BFF_PREFIX}/${clean}${qs ? `?${qs}` : ""}`;
}

export async function request<T>(
  method: string,
  path: string,
  options: RequestOptions = {},
): Promise<ApiSuccess<T>> {
  let response: Response;

  try {
    response = await fetch(buildUrl(path, options.query), {
      method,
      headers: {
        Accept: "application/json",
        ...(options.body !== undefined ? { "Content-Type": "application/json" } : {}),
      },
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
      credentials: "same-origin",
      cache: "no-store",
      signal: options.signal,
    });
  } catch (error) {
    if ((error as Error)?.name === "AbortError") throw error;
    throw new ApiError(0, { message: "Network error", code: "NETWORK_ERROR" });
  }

  let body: ApiEnvelope<T> | null = null;
  try {
    body = (await response.json()) as ApiEnvelope<T>;
  } catch {
    body = null;
  }

  if (!response.ok || !body || body.success !== true) {
    throw new ApiError(response.status, (body ?? {}) as Partial<ApiFailure>);
  }

  return body;
}

export const api = {
  get: <T>(path: string, query?: Query, signal?: AbortSignal) =>
    request<T>("GET", path, { query, signal }),
  post: <T>(path: string, body?: unknown) => request<T>("POST", path, { body: body ?? {} }),
  put: <T>(path: string, body?: unknown) => request<T>("PUT", path, { body: body ?? {} }),
  patch: <T>(path: string, body?: unknown) => request<T>("PATCH", path, { body: body ?? {} }),
  delete: <T>(path: string) => request<T>("DELETE", path),

  /** GET a paginated list and return items + pagination meta. */
  async list<T>(path: string, query?: Query, signal?: AbortSignal): Promise<Paginated<T>> {
    const res = await request<T[]>("GET", path, { query, signal });
    return { items: res.data, meta: res.meta as PaginationMeta };
  },
};
