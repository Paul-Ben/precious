import Link from "next/link";
import { RoomImage } from "@/features/booking/room-image";
import { SearchForm } from "@/features/booking/search-form";
import type { RoomType } from "@/lib/api/types";
import { APP_NAME } from "@/lib/config";
import { formatNaira } from "@/lib/money";
import { serverApi } from "@/lib/server/api";

/**
 * Home page with live room search. The full premium website design arrives
 * in a later milestone; this page already books real rooms.
 */
export default async function HomePage() {
  const { body } = await serverApi<RoomType[]>("public/room-types");
  const roomTypes = body?.success ? body.data : [];

  return (
    <>
      <section className="relative overflow-hidden">
        <div
          aria-hidden="true"
          className="absolute inset-0 bg-[radial-gradient(ellipse_at_top,color-mix(in_srgb,var(--accent)_22%,transparent),transparent_60%)]"
        />
        <div className="relative mx-auto flex max-w-5xl flex-col items-center gap-6 px-4 pb-12 pt-20 text-center sm:pt-28">
          <p className="text-xs font-medium uppercase tracking-[0.3em] text-accent">Stay · Dine · Unwind</p>
          <h1 className="font-[family-name:var(--font-display)] text-4xl leading-tight tracking-tight sm:text-6xl">{APP_NAME}</h1>
          <p className="max-w-xl text-base text-muted sm:text-lg">Choose your dates to see available rooms and book online.</p>
          <SearchForm className="w-full" />
        </div>
      </section>

      {roomTypes.length > 0 && (
        <section aria-labelledby="rooms" className="mx-auto max-w-6xl px-4 pb-20 sm:px-6">
          <div className="mb-6 flex items-end justify-between">
            <h2 id="rooms" className="font-[family-name:var(--font-display)] text-3xl">Rooms &amp; suites</h2>
            <Link href="/rooms" className="text-sm font-semibold underline-offset-4 hover:underline">
              Check availability →
            </Link>
          </div>
          <ul className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            {roomTypes.slice(0, 6).map((type) => (
              <li key={type.id}>
                <Link href={`/rooms/${type.slug}`} className="group block space-y-3">
                  <div className="aspect-[4/3] overflow-hidden rounded-2xl">
                    <RoomImage url={type.cover_image_url} alt={type.name} className="transition group-hover:scale-[1.02]" />
                  </div>
                  <div className="flex items-baseline justify-between gap-3">
                    <span className="font-[family-name:var(--font-display)] text-2xl">{type.name}</span>
                    <span className="text-sm text-muted">
                      from <strong className="text-foreground">{formatNaira(type.base_rate)}</strong>
                    </span>
                  </div>
                  {type.short_description && <p className="text-sm text-muted">{type.short_description}</p>}
                </Link>
              </li>
            ))}
          </ul>
        </section>
      )}
    </>
  );
}
