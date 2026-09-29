/**
 * Display-only helpers. All money maths happens in the Laravel API; the
 * frontend only formats the decimal strings it receives (spec §43, §75).
 */

export function formatNaira(amount: string | null | undefined, options: { kobo?: boolean } = {}): string {
  if (amount === null || amount === undefined || amount === "") return "—";

  const match = /^(-)?(\d+)(?:\.(\d{1,2}))?$/.exec(String(amount).trim());
  if (!match) return String(amount);

  const [, sign = "", whole, fraction = "00"] = match;
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  const kobo = fraction.padEnd(2, "0");
  const showKobo = options.kobo ?? kobo !== "00";

  return `${sign}₦${grouped}${showKobo ? `.${kobo}` : ""}`;
}

/** "7.5" -> "7.5%" */
export function formatPercent(value: string | number | null | undefined): string {
  if (value === null || value === undefined) return "—";
  return `${String(value).replace(/\.0+$/, "").replace(/(\.\d*?)0+$/, "$1")}%`;
}
