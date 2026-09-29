import { describe, expect, it, vi } from "vitest";
import { jsonResponse } from "@/test/utils";
import { api } from "./client";
import { ApiError, errorMessage } from "./errors";

describe("api client", () => {
  it("calls the BFF and unwraps the success envelope", async () => {
    const fetchMock = vi.spyOn(globalThis, "fetch").mockResolvedValue(
      jsonResponse({ success: true, message: "OK", data: { id: 1 }, meta: {} }),
    );

    const res = await api.get<{ id: number }>("users", { search: "ada", empty: "", missing: undefined });

    expect(res.data).toEqual({ id: 1 });
    expect(fetchMock).toHaveBeenCalledWith("/api/bff/users?search=ada", expect.objectContaining({ method: "GET" }));
  });

  it("returns items and pagination meta for lists", async () => {
    vi.spyOn(globalThis, "fetch").mockResolvedValue(
      jsonResponse({ success: true, message: "OK", data: [{ id: 1 }], meta: { current_page: 1, per_page: 20, total: 1, last_page: 1 } }),
    );

    const res = await api.list<{ id: number }>("users");

    expect(res.items).toHaveLength(1);
    expect(res.meta.total).toBe(1);
  });

  it("throws ApiError with validation details", async () => {
    vi.spyOn(globalThis, "fetch").mockResolvedValue(
      jsonResponse(
        { success: false, message: "Validation failed.", code: "VALIDATION_FAILED", errors: { email: ["Taken."] } },
        422,
      ),
    );

    const error = await api.post("auth/register", {}).catch((e: unknown) => e);

    expect(error).toBeInstanceOf(ApiError);
    const apiError = error as ApiError;
    expect(apiError.isValidation).toBe(true);
    expect(apiError.field("email")).toBe("Taken.");
    expect(apiError.code).toBe("VALIDATION_FAILED");
  });

  it("maps network failures to a friendly message", async () => {
    vi.spyOn(globalThis, "fetch").mockRejectedValue(new TypeError("Failed to fetch"));

    const error = await api.get("auth/me").catch((e: unknown) => e);

    expect((error as ApiError).isNetwork).toBe(true);
    expect(errorMessage(error)).toMatch(/couldn't reach the server/i);
  });

  it("identifies forbidden responses", async () => {
    vi.spyOn(globalThis, "fetch").mockResolvedValue(
      jsonResponse({ success: false, message: "No.", code: "FORBIDDEN" }, 403),
    );

    const error = (await api.get("users").catch((e: unknown) => e)) as ApiError;

    expect(error.isForbidden).toBe(true);
    expect(error.status).toBe(403);
  });
});
