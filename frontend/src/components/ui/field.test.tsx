import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { Field } from "./field";
import { Input } from "./input";

describe("Field", () => {
  it("links the label, hint and error to the control for screen readers", () => {
    render(
      <Field label="Email" hint="We never share it." error="Enter a valid email." required>
        <Input />
      </Field>,
    );

    const input = screen.getByLabelText(/email/i);
    expect(input).toHaveAttribute("aria-invalid", "true");
    expect(input).toHaveAttribute("aria-required", "true");
    expect(input).toHaveAccessibleDescription("Enter a valid email.");
    expect(screen.getByRole("alert")).toHaveTextContent("Enter a valid email.");
  });

  it("shows the hint when there is no error", () => {
    render(
      <Field label="Phone" hint="Include +234.">
        <Input />
      </Field>,
    );

    expect(screen.getByLabelText("Phone")).toHaveAccessibleDescription("Include +234.");
  });
});
