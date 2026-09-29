import { describe, expect, it } from "vitest";
import { changePasswordSchema, otpSchema, passwordSchema, registerSchema } from "./auth";

describe("password policy", () => {
  it.each([
    ["short", "Ab1!"],
    ["no upper case", "lowercase1!x"],
    ["no lower case", "UPPERCASE1!X"],
    ["no number", "NoNumbers!!x"],
    ["no symbol", "NoSymbols123"],
  ])("rejects a password with %s", (_, value) => {
    expect(passwordSchema.safeParse(value).success).toBe(false);
  });

  it("accepts a strong password", () => {
    expect(passwordSchema.safeParse("Str0ng-Password!").success).toBe(true);
  });
});

describe("registration", () => {
  const valid = {
    name: "Ada Obi",
    email: "ada@example.com",
    phone: "+2348012345678",
    password: "Str0ng-Password!",
    password_confirmation: "Str0ng-Password!",
  };

  it("accepts valid input and an empty phone", () => {
    expect(registerSchema.safeParse(valid).success).toBe(true);
    expect(registerSchema.safeParse({ ...valid, phone: "" }).success).toBe(true);
  });

  it("requires matching passwords", () => {
    const result = registerSchema.safeParse({ ...valid, password_confirmation: "Different-Pass1!" });
    expect(result.success).toBe(false);
    expect(result.error?.issues[0]?.path).toEqual(["password_confirmation"]);
  });

  it("rejects invalid phone numbers", () => {
    expect(registerSchema.safeParse({ ...valid, phone: "080-abc" }).success).toBe(false);
  });
});

describe("other schemas", () => {
  it("requires a 6-digit OTP", () => {
    expect(otpSchema.safeParse({ code: "123456" }).success).toBe(true);
    expect(otpSchema.safeParse({ code: "12345" }).success).toBe(false);
    expect(otpSchema.safeParse({ code: "12a456" }).success).toBe(false);
  });

  it("requires the new password to differ from the current one", () => {
    const result = changePasswordSchema.safeParse({
      current_password: "Str0ng-Password!",
      password: "Str0ng-Password!",
      password_confirmation: "Str0ng-Password!",
    });
    expect(result.success).toBe(false);
  });
});
