import "@testing-library/jest-dom/vitest";
import { cleanup } from "@testing-library/react";
import { afterEach, vi } from "vitest";

// Server-only modules throw when imported outside a Server Component.
vi.mock("server-only", () => ({}));

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});
