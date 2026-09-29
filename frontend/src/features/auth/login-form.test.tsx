import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { jsonResponse, renderWithQuery } from "@/test/utils";
import { LoginForm } from "./login-form";

const replace = vi.fn();
const refresh = vi.fn();
let search = new URLSearchParams();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace, refresh, push: vi.fn() }),
  useSearchParams: () => search,
}));

const staffUser = {
  id: "u1",
  name: "Ada Admin",
  email: "ada@example.com",
  phone: null,
  type: "staff",
  status: "active",
  must_change_password: false,
  two_factor_enabled: true,
  two_factor_required: true,
  is_super_admin: false,
  roles: ["Administrator"],
  permissions: [],
  last_login_at: null,
  created_at: null,
};

async function fillCredentials() {
  const user = userEvent.setup();
  await user.type(screen.getByLabelText(/email address/i), "ada@example.com");
  await user.type(screen.getByLabelText(/^password/i), "Secret-Passw0rd!");
  await user.click(screen.getByRole("button", { name: /sign in/i }));
  return user;
}

describe("LoginForm", () => {
  beforeEach(() => {
    replace.mockReset();
    refresh.mockReset();
    search = new URLSearchParams();
  });

  it("shows client-side validation errors without calling the API", async () => {
    const fetchMock = vi.spyOn(globalThis, "fetch");
    renderWithQuery(<LoginForm portal="customer" />);

    await userEvent.setup().click(screen.getByRole("button", { name: /sign in/i }));

    expect(await screen.findByText("Enter your email address.")).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("shows server credential errors on the field", async () => {
    vi.spyOn(globalThis, "fetch").mockResolvedValue(
      jsonResponse(
        {
          success: false,
          message: "Validation failed.",
          code: "VALIDATION_FAILED",
          errors: { email: ["These credentials do not match our records."] },
        },
        422,
      ),
    );
    renderWithQuery(<LoginForm portal="customer" />);

    await fillCredentials();

    expect(await screen.findByText("These credentials do not match our records.")).toBeInTheDocument();
    expect(screen.getByLabelText(/email address/i)).toHaveAttribute("aria-invalid", "true");
  });

  it("walks an administrator through the emailed code step", async () => {
    const fetchMock = vi
      .spyOn(globalThis, "fetch")
      .mockResolvedValueOnce(
        jsonResponse({
          success: true,
          message: "Enter the verification code we emailed you.",
          data: {
            two_factor_required: true,
            two_factor: { challenge_id: "c1", channel: "email", destination: "ad**@example.com", expires_at: "" },
          },
          meta: {},
        }),
      )
      .mockResolvedValueOnce(
        jsonResponse({ success: true, message: "Signed in.", data: { two_factor_required: false, user: staffUser }, meta: {} }),
      );

    renderWithQuery(<LoginForm portal="staff" />);
    const user = await fillCredentials();

    expect(await screen.findByText("ad**@example.com")).toBeInTheDocument();
    expect(fetchMock.mock.calls[0]![0]).toBe("/api/bff/auth/staff/login");

    await user.type(screen.getByLabelText(/verification code/i), "123456");
    await user.click(screen.getByRole("button", { name: /verify and sign in/i }));

    await waitFor(() => expect(replace).toHaveBeenCalledWith("/staff/dashboard"));
    expect(fetchMock.mock.calls[1]![0]).toBe("/api/bff/auth/two-factor/verify");
    expect(JSON.parse(fetchMock.mock.calls[1]![1]!.body as string)).toEqual({ challenge_id: "c1", code: "123456" });
  });

  it("reports remaining attempts for a wrong code", async () => {
    vi.spyOn(globalThis, "fetch")
      .mockResolvedValueOnce(
        jsonResponse({
          success: true,
          message: "",
          data: { two_factor_required: true, two_factor: { challenge_id: "c1", channel: "email", destination: "x", expires_at: "" } },
          meta: {},
        }),
      )
      .mockResolvedValueOnce(
        jsonResponse(
          { success: false, message: "The verification code is incorrect.", code: "TWO_FACTOR_INVALID", remaining_attempts: 3 },
          422,
        ),
      );

    renderWithQuery(<LoginForm portal="staff" />);
    const user = await fillCredentials();
    await user.type(await screen.findByLabelText(/verification code/i), "000000");
    await user.click(screen.getByRole("button", { name: /verify and sign in/i }));

    expect(await screen.findByText("Incorrect code. 3 attempt(s) left.")).toBeInTheDocument();
    expect(replace).not.toHaveBeenCalled();
  });

  it("sends users with a temporary password to change it first", async () => {
    search = new URLSearchParams("next=/staff/users");
    vi.spyOn(globalThis, "fetch").mockResolvedValue(
      jsonResponse({
        success: true,
        message: "",
        data: { two_factor_required: false, user: { ...staffUser, two_factor_required: false, must_change_password: true } },
        meta: {},
      }),
    );

    renderWithQuery(<LoginForm portal="staff" />);
    await fillCredentials();

    await waitFor(() => expect(replace).toHaveBeenCalledWith("/change-password"));
  });

  it("honours a safe ?next= redirect", async () => {
    search = new URLSearchParams("next=/staff/users");
    vi.spyOn(globalThis, "fetch").mockResolvedValue(
      jsonResponse({
        success: true,
        message: "",
        data: { two_factor_required: false, user: { ...staffUser, two_factor_required: false } },
        meta: {},
      }),
    );

    renderWithQuery(<LoginForm portal="staff" />);
    await fillCredentials();

    await waitFor(() => expect(replace).toHaveBeenCalledWith("/staff/users"));
  });
});
