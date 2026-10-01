"use client";

import { useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { getEcho, onConnectionChange } from "@/lib/realtime";
import { barKeys } from "./api";

export interface BarOrderEvent {
  id: string;
  number: string;
  status: string;
  tab_id: string;
  property_id: number;
}

/**
 * Live bar updates over Reverb. Every order change refreshes the queue, the
 * affected bill and the table map.
 *
 * Returns `connected`; screens poll slowly while it is true and fall back to
 * fast polling when the socket is down or realtime is not configured.
 */
export function useBarRealtime(onEvent?: (event: BarOrderEvent) => void): { connected: boolean } {
  const queryClient = useQueryClient();
  const [connected, setConnected] = useState(false);

  useEffect(() => {
    const echo = getEcho();
    if (!echo) return;

    const stop = onConnectionChange(setConnected);
    echo.private("bar").listen(".order.changed", (event: BarOrderEvent) => {
      void queryClient.invalidateQueries({ queryKey: barKeys.queue });
      void queryClient.invalidateQueries({ queryKey: barKeys.tab(event.tab_id) });
      void queryClient.invalidateQueries({ queryKey: barKeys.tables });
      void queryClient.invalidateQueries({ queryKey: ["bar", "tabs"] });
      onEvent?.(event);
    });

    return () => {
      stop();
      echo.leave("bar");
    };
    // onEvent is intentionally read once; callers pass a stable callback.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [queryClient]);

  return { connected };
}

/** Polling interval: slow while the socket is live (safety net), fast otherwise. */
export function pollEvery(connected: boolean, fallbackMs: number): number {
  return connected ? Math.max(fallbackMs * 6, 30_000) : fallbackMs;
}
