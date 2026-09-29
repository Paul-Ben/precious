import { api } from "@/lib/api/client";
import type { AvailabilityResult, Property, Quote, Reservation, RoomType } from "@/lib/api/types";

export interface StaySearch {
  check_in: string;
  check_out: string;
  adults: number;
  children: number;
}

export interface BasketLine {
  room_type_id: number;
  quantity: number;
}

export interface GuestDetails {
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
}

export const bookingKeys = {
  property: ["public", "property"] as const,
  roomTypes: ["public", "room-types"] as const,
  availability: (s: StaySearch) => ["public", "availability", s] as const,
  quote: (s: StaySearch, lines: BasketLine[]) => ["public", "quote", s, lines] as const,
  lookup: (number: string) => ["public", "reservation", number] as const,
  myReservations: ["me", "reservations"] as const,
};

export const bookingApi = {
  property: async () => (await api.get<Property>("public/property")).data,
  roomTypes: async () => (await api.get<RoomType[]>("public/room-types")).data,
  availability: async (s: StaySearch, signal?: AbortSignal) =>
    (await api.get<AvailabilityResult>("public/availability", { ...s }, signal)).data,
  quote: async (s: StaySearch, rooms: BasketLine[]) => (await api.post<Quote>("public/quote", { ...s, rooms })).data,
  book: (body: StaySearch & { rooms: BasketLine[]; guest: GuestDetails; special_requests?: string; accept_terms: boolean }) =>
    api.post<{ reservation: Reservation; lookup_token: string }>("public/reservations", body),
  lookup: async (number: string, token: string) =>
    (await api.get<Reservation>(`public/reservations/${encodeURIComponent(number)}`, { token })).data,
  myReservations: () => api.list<Reservation>("me/reservations"),
  cancelMine: (id: string, reason: string) => api.post<Reservation>(`me/reservations/${id}/cancel`, { reason }),
};

/** The guest lookup token is kept for this browser tab only. */
export const bookingTokenStore = {
  key: (number: string) => `booking-token:${number}`,
  save(number: string, token: string) {
    try {
      sessionStorage.setItem(this.key(number), token);
    } catch {
      /* storage unavailable (private mode) */
    }
  },
  read(number: string): string | null {
    try {
      return sessionStorage.getItem(this.key(number));
    } catch {
      return null;
    }
  },
};
