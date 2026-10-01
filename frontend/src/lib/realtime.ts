"use client";

import Echo from "laravel-echo";
import Pusher from "pusher-js";
import { bffUrl } from "@/lib/api/client";

/**
 * Laravel Reverb (websocket) client for staff screens.
 *
 * - Private-channel auth goes through the BFF (`/api/bff/broadcasting/auth`),
 *   so the session cookie authorises it and the API token never reaches JS.
 * - When NEXT_PUBLIC_REVERB_APP_KEY is not set, realtime is off and screens
 *   fall back to polling.
 */

type EchoClient = Echo<"reverb">;

let client: EchoClient | null = null;

export function realtimeEnabled(): boolean {
  return typeof window !== "undefined" && Boolean(process.env.NEXT_PUBLIC_REVERB_APP_KEY);
}

export function getEcho(): EchoClient | null {
  if (!realtimeEnabled()) return null;
  if (client) return client;

  const scheme = process.env.NEXT_PUBLIC_REVERB_SCHEME ?? "https";
  const port = Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? (scheme === "https" ? 443 : 80));

  client = new Echo({
    broadcaster: "reverb",
    Pusher,
    key: process.env.NEXT_PUBLIC_REVERB_APP_KEY!,
    wsHost: process.env.NEXT_PUBLIC_REVERB_HOST ?? window.location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === "https",
    enabledTransports: ["ws", "wss"],
    authEndpoint: bffUrl("broadcasting/auth"),
  });

  return client;
}

/** Subscribe to connection state changes. Returns an unsubscribe function. */
export function onConnectionChange(listener: (connected: boolean) => void): () => void {
  const echo = getEcho();
  if (!echo) return () => {};

  const connection = echo.connector.pusher.connection;
  const handler = ({ current }: { current: string }) => listener(current === "connected");
  connection.bind("state_change", handler);
  listener(connection.state === "connected");

  return () => connection.unbind("state_change", handler);
}
