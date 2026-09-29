import { BedDouble } from "lucide-react";
import { cn } from "@/lib/utils";

/** Room photo, or a neutral placeholder until real photography is uploaded. */
export function RoomImage({ url, alt, className }: { url: string | null | undefined; alt: string; className?: string }) {
  if (url) {
    // eslint-disable-next-line @next/next/no-img-element -- images come from the API's media disk (R2 / local)
    return <img src={url} alt={alt} loading="lazy" className={cn("h-full w-full object-cover", className)} />;
  }

  return (
    <div
      role="img"
      aria-label={`${alt} (photo coming soon)`}
      className={cn("flex h-full w-full items-center justify-center bg-surface-muted text-muted", className)}
    >
      <BedDouble className="size-8" aria-hidden="true" />
    </div>
  );
}
