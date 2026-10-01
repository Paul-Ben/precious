import { Download } from "lucide-react";
import { type ExportType, financeApi } from "./api";

/** CSV + Excel links for one report (P34). */
export function Downloads({ type, label, from, to }: { type: ExportType; label: string; from?: string; to?: string }) {
  return (
    <div className="flex flex-wrap items-center gap-2 text-sm print:hidden">
      <span className="text-muted">{label}</span>
      {(["csv", "xlsx"] as const).map((format) => (
        <a
          key={format}
          href={financeApi.exportUrl(type, format, from, to)}
          download
          className="inline-flex items-center gap-1 rounded-md border border-border px-2.5 py-1.5 font-medium hover:bg-surface-muted"
        >
          <Download className="size-3.5" aria-hidden /> {format === "csv" ? "CSV" : "Excel"}
        </a>
      ))}
    </div>
  );
}
