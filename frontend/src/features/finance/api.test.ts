import { describe, expect, it, vi } from "vitest";
import { financeApi, fromKobo, parseAmount, rangeFor, toKobo } from "./api";

describe("money input", () => {
  it("parses what people type", () => {
    expect(parseAmount("12500")).toBe("12500.00");
    expect(parseAmount("₦12,500.5")).toBe("12500.50");
    expect(parseAmount(" 007 ")).toBe("7.00");
    expect(parseAmount("12.345")).toBeNull();
    expect(parseAmount("abc")).toBeNull();
    expect(parseAmount("")).toBeNull();
  });

  it("converts to and from kobo exactly", () => {
    expect(toKobo("18447.00")).toBe(1844700);
    expect(toKobo("-447.5")).toBe(-44750);
    expect(toKobo("0.1")).toBe(10);
    expect(fromKobo(1344700)).toBe("13447.00");
    expect(fromKobo(-44705)).toBe("-447.05");
  });
});

describe("ranges", () => {
  it("resolves presets in hotel days", () => {
    expect(rangeFor("today", "2026-10-05")).toEqual({ from: "2026-10-05", to: "2026-10-05" });
    expect(rangeFor("week", "2026-10-05")).toEqual({ from: "2026-09-29", to: "2026-10-05" });
    expect(rangeFor("month", "2026-10-05")).toEqual({ from: "2026-10-01", to: "2026-10-05" });
    expect(rangeFor("last-month", "2026-03-10")).toEqual({ from: "2026-02-01", to: "2026-02-28" });
  });

  it("builds export links", () => {
    expect(financeApi.exportUrl("expenses", "xlsx", "2026-10-01", "2026-10-05")).toBe(
      "/api/bff/finance/export?type=expenses&format=xlsx&from=2026-10-01&to=2026-10-05",
    );
    expect(financeApi.exportUrl("outstanding", "csv")).toBe("/api/bff/finance/export?type=outstanding&format=csv");
  });
});

describe("expense upload", () => {
  it("sends the receipt and fields as multipart, skipping empty optionals", async () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ success: true, message: "ok", data: {}, meta: {} }), { status: 201 }));
    vi.stubGlobal("fetch", fetchMock);
    const receipt = new File(["x"], "r.pdf", { type: "application/pdf" });
    await financeApi.createExpense(
      { category_id: 3, expense_date: "2026-10-01", description: "Diesel", payee: null, amount: "12500.00", method: "CASH", reference: null },
      receipt,
    );
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe("/api/bff/finance/expenses");
    const form = init.body as FormData;
    expect(Object.fromEntries([...form.entries()].filter(([k]) => k !== "receipt"))).toEqual({
      category_id: "3",
      expense_date: "2026-10-01",
      description: "Diesel",
      amount: "12500.00",
      method: "CASH",
    });
    expect((form.get("receipt") as File).name).toBe("r.pdf");
    vi.unstubAllGlobals();
  });
});
