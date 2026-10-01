"use client";

import { useEffect, useState } from "react";

/** Current time, refreshed every `everyMs` (keeps render pure; shifts start and end while a page is open). */
export function useNow(everyMs = 30_000): number {
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    const id = setInterval(() => setNow(Date.now()), everyMs);
    return () => clearInterval(id);
  }, [everyMs]);

  return now;
}
