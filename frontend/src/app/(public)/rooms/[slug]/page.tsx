import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Badge } from "@/components/ui/badge";
import { RoomImage } from "@/features/booking/room-image";
import { SearchForm } from "@/features/booking/search-form";
import type { RoomType } from "@/lib/api/types";
import { formatNaira } from "@/lib/money";
import { serverApi } from "@/lib/server/api";

async function load(slug: string): Promise<RoomType | null> {
  const { status, body } = await serverApi<RoomType>(`public/room-types/${encodeURIComponent(slug)}`);
  if (status === 404) return null;
  if (!body?.success) throw new Error("Could not load the room.");
  return body.data;
}

export async function generateMetadata({ params }: PageProps<"/rooms/[slug]">): Promise<Metadata> {
  const { slug } = await params;
  const type = await load(slug).catch(() => null);
  return type ? { title: type.name, description: type.short_description ?? undefined } : { title: "Room" };
}

export default async function RoomTypePage({ params }: PageProps<"/rooms/[slug]">) {
  const { slug } = await params;
  const type = await load(slug);
  if (!type) notFound();

  const images = type.images ?? [];

  return (
    <div className="mx-auto max-w-6xl space-y-8 px-4 py-10 sm:px-6">
      <Link href="/rooms" className="text-sm text-muted hover:text-foreground">
        ← All rooms
      </Link>
      <div className="grid gap-8 lg:grid-cols-[1.4fr_1fr]">
        <div className="space-y-3">
          <div className="aspect-[3/2] overflow-hidden rounded-2xl">
            <RoomImage url={images[0]?.url ?? null} alt={images[0]?.alt ?? type.name} />
          </div>
          {images.length > 1 && (
            <ul className="grid grid-cols-4 gap-3">
              {images.slice(1, 5).map((img) => (
                <li key={img.id} className="aspect-[4/3] overflow-hidden rounded-xl">
                  <RoomImage url={img.url} alt={img.alt ?? type.name} />
                </li>
              ))}
            </ul>
          )}
        </div>
        <div className="space-y-5">
          <h1 className="font-[family-name:var(--font-display)] text-4xl tracking-tight">{type.name}</h1>
          <p className="text-2xl font-bold">
            {formatNaira(type.base_rate)} <span className="text-sm font-normal text-muted">per night</span>
          </p>
          {type.description ? <p className="whitespace-pre-line text-muted">{type.description}</p> : type.short_description && <p className="text-muted">{type.short_description}</p>}
          <dl className="grid grid-cols-2 gap-3 text-sm">
            <div><dt className="text-muted">Guests</dt><dd className="font-medium">Up to {type.max_occupancy} ({type.max_adults} adults)</dd></div>
            {type.bed_type && <div><dt className="text-muted">Bed</dt><dd className="font-medium">{type.bed_type}</dd></div>}
            {type.size_sqm && <div><dt className="text-muted">Size</dt><dd className="font-medium">{type.size_sqm} m²</dd></div>}
          </dl>
          {!!type.amenities?.length && (
            <ul className="flex flex-wrap gap-2" aria-label="Amenities">
              {type.amenities.map((a) => (
                <li key={a.id}><Badge>{a.name}</Badge></li>
              ))}
            </ul>
          )}
        </div>
      </div>
      <section aria-labelledby="check" className="space-y-3">
        <h2 id="check" className="text-lg font-semibold">Check availability</h2>
        <SearchForm />
      </section>
    </div>
  );
}
