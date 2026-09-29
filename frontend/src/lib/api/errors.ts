import type { ApiFailure } from "./types";

/**
 * Error thrown by the API client for any non-2xx response or network failure.
 * `code` mirrors the backend's machine-readable error code.
 */
export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly errors: Record<string, string[]>;
  readonly body: Partial<ApiFailure>;

  constructor(status: number, body: Partial<ApiFailure>) {
    super(body.message || "Something went wrong.");
    this.name = "ApiError";
    this.status = status;
    this.code = body.code || (status === 0 ? "NETWORK_ERROR" : `HTTP_${status}`);
    this.errors = body.errors ?? {};
    this.body = body;
  }

  get isValidation(): boolean {
    return this.status === 422 && Object.keys(this.errors).length > 0;
  }

  get isUnauthenticated(): boolean {
    return this.status === 401;
  }

  get isForbidden(): boolean {
    return this.status === 403;
  }

  get isNetwork(): boolean {
    return this.status === 0 || this.code === "UPSTREAM_UNAVAILABLE";
  }

  /** First validation message for a field, if any. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0];
  }
}

export function isApiError(error: unknown): error is ApiError {
  return error instanceof ApiError;
}

/** Human-friendly message for any thrown value. */
export function errorMessage(error: unknown): string {
  if (isApiError(error)) {
    if (error.isNetwork) {
      return "We couldn't reach the server. Check your connection and try again.";
    }
    return error.message;
  }
  return "Something went wrong. Please try again.";
}
