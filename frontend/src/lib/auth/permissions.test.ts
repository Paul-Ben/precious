import { describe, expect, it } from "vitest";
import type { User } from "@/lib/api/types";
import { can, canAny, homeFor, safeNext } from "./permissions";

const base: User = {
  id: "u1",
  name: "Ada",
  email: "ada@example.com",
  phone: null,
  type: "staff",
  status: "active",
  must_change_password: false,
  two_factor_enabled: false,
  two_factor_required: false,
  is_super_admin: false,
  roles: ["Waiter", "Bartender"],
  permissions: ["bar.orders.create", "bar.orders.prepare"],
  last_login_at: null,
  created_at: null,
};

describe("permissions", () => {
  it("checks permissions from all roles", () => {
    expect(can(base, "bar.orders.create")).toBe(true);
    expect(can(base, "bar.orders.prepare")).toBe(true);
    expect(can(base, "users.view")).toBe(false);
    expect(canAny(base, ["users.view", "bar.orders.prepare"])).toBe(true);
  });

  it("lets super administrators through everything", () => {
    expect(can({ ...base, is_super_admin: true, permissions: [] }, "anything.at_all")).toBe(true);
  });

  it("denies when there is no user", () => {
    expect(can(null, "users.view")).toBe(false);
  });

  it("routes users to the right home", () => {
    expect(homeFor(base)).toBe("/staff/dashboard");
    expect(homeFor({ ...base, type: "customer" })).toBe("/account");
    expect(homeFor({ ...base, must_change_password: true })).toBe("/change-password");
  });

  it("only allows same-site redirects", () => {
    expect(safeNext("/staff/users", "/x")).toBe("/staff/users");
    expect(safeNext("https://evil.example", "/x")).toBe("/x");
    expect(safeNext("//evil.example", "/x")).toBe("/x");
    expect(safeNext("/\\evil.example", "/x")).toBe("/x");
    expect(safeNext(null, "/x")).toBe("/x");
  });
});
