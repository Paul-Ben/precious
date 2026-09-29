import Link from "next/link";

export default function NotFound() {
  return (
    <main id="main" className="flex min-h-screen flex-col items-center justify-center gap-4 px-6 text-center">
      <p className="text-sm font-medium uppercase tracking-widest text-accent">404</p>
      <h1 className="font-[family-name:var(--font-display)] text-3xl">This page could not be found</h1>
      <Link href="/" className="text-sm font-medium underline underline-offset-4">
        Back to home
      </Link>
    </main>
  );
}
