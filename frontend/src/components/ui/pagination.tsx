import type { PaginationMeta } from "@/lib/api/types";
import { Button } from "./button";

export function Pagination({ meta, onPage }: { meta: PaginationMeta; onPage: (page: number) => void }) {
  if (meta.last_page <= 1) return null;

  return (
    <nav className="flex items-center justify-between gap-3 border-t border-border px-5 py-3 text-sm" aria-label="Pagination">
      <p className="text-muted">
        Page {meta.current_page} of {meta.last_page} · {meta.total} total
      </p>
      <div className="flex gap-2">
        <Button variant="outline" size="sm" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>
          Previous
        </Button>
        <Button
          variant="outline"
          size="sm"
          disabled={meta.current_page >= meta.last_page}
          onClick={() => onPage(meta.current_page + 1)}
        >
          Next
        </Button>
      </div>
    </nav>
  );
}
