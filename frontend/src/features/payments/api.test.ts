import { describe, expect, it } from "vitest";
import { referenceFromQuery } from "./api";

describe("referenceFromQuery", () => {
  it("reads Paystack's callback parameters", () => {
    expect(referenceFromQuery(new URLSearchParams("trxref=PAY-01J&reference=PAY-01J"))).toBe("PAY-01J");
    expect(referenceFromQuery(new URLSearchParams("trxref=PAY-02"))).toBe("PAY-02");
  });

  it("reads Flutterwave's callback parameters", () => {
    expect(referenceFromQuery(new URLSearchParams("status=successful&tx_ref=PAY-03&transaction_id=288200108"))).toBe("PAY-03");
  });

  it("returns null when no reference is present", () => {
    expect(referenceFromQuery(new URLSearchParams("status=cancelled"))).toBeNull();
  });
});
