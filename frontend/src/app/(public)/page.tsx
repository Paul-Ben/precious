import Link from "next/link";
import { APP_NAME } from "@/lib/config";

/**
 * Placeholder home page. The full public website (rooms, gallery, booking)
 * is built in a later milestone, after the reservation engine exists.
 */
export default function HomePage() {
  return (
    <section className="relative overflow-hidden">
      <div
        aria-hidden="true"
        className="absolute inset-0 bg-[radial-gradient(ellipse_at_top,color-mix(in_srgb,var(--accent)_22%,transparent),transparent_60%)]"
      />
      <div className="relative mx-auto flex max-w-4xl flex-col items-center gap-6 px-4 py-24 text-center sm:py-32">
        <p className="text-xs font-medium uppercase tracking-[0.3em] text-accent">Hotel · Bar · Lagos</p>
        <h1 className="font-[family-name:var(--font-display)] text-4xl leading-tight tracking-tight sm:text-6xl">
          {APP_NAME}
        </h1>
        <p className="max-w-xl text-base text-muted sm:text-lg">
          Our new website is on its way. Soon you&apos;ll be able to browse rooms, book your stay and pay online here.
        </p>
        <div className="flex flex-wrap justify-center gap-3">
          <Link href="/register" className="rounded-lg bg-brand px-5 py-3 text-sm font-medium text-brand-foreground">
            Create an account
          </Link>
          <Link href="/login" className="rounded-lg border border-border bg-surface px-5 py-3 text-sm font-medium">
            Sign in
          </Link>
        </div>
      </div>
    </section>
  );
}
