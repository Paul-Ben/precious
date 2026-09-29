"use client";

export default function GlobalError({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <main id="main" className="flex min-h-screen flex-col items-center justify-center gap-4 px-6 text-center">
      <h1 className="text-2xl font-semibold">Something went wrong</h1>
      <p className="max-w-md text-sm text-muted">An unexpected error occurred. Please try again.</p>
      <button type="button" onClick={reset} className="h-11 rounded-lg bg-brand px-4 text-sm font-medium text-brand-foreground">
        Try again
      </button>
    </main>
  );
}
