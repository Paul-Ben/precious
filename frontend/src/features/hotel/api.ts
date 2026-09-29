import { api } from "@/lib/api/client";
import type {
  Amenity,
  BoardRoom,
  FrontDeskSummary,
  Guest,
  GuestDocument,
  HotelPolicies,
  Property,
  Quote,
  Reservation,
  Room,
  RoomBlock,
  RoomStatus,
  RoomType,
} from "@/lib/api/types";

type Q = Record<string, string | number | boolean | null | undefined>;

export const hotelKeys = {
  property: ["hotel", "property"] as const,
  amenities: ["hotel", "amenities"] as const,
  roomTypes: ["hotel", "room-types"] as const,
  rooms: (f: Q = {}) => ["hotel", "rooms", f] as const,
  board: ["hotel", "board"] as const,
  summary: (date?: string) => ["hotel", "summary", date ?? "today"] as const,
  guests: (f: Q = {}) => ["hotel", "guests", f] as const,
  guest: (id: string) => ["hotel", "guest", id] as const,
  guestDocuments: (id: string) => ["hotel", "guest", id, "documents"] as const,
  guestHistory: (id: string) => ["hotel", "guest", id, "history"] as const,
  reservations: (f: Q = {}) => ["hotel", "reservations", f] as const,
  reservation: (id: string) => ["hotel", "reservation", id] as const,
  deskAvailability: (a: string, b: string) => ["hotel", "desk-availability", a, b] as const,
};

export interface DeskAvailability {
  check_in: string;
  check_out: string;
  nights: number;
  room_types: {
    id: number;
    name: string;
    base_rate: string;
    max_adults: number;
    max_occupancy: number;
    available_count: number;
    available_rooms: { id: number; number: string }[];
    quote: Quote;
  }[];
}

export const hotelApi = {
  // Property
  property: async () => (await api.get<Property>("property")).data,
  updateProperty: (body: { property?: Partial<Property>; policies?: Partial<HotelPolicies> }) => api.patch<Property>("property", body),

  // Amenities
  amenities: async () => (await api.get<Amenity[]>("amenities")).data,
  createAmenity: (body: { name: string; icon?: string }) => api.post<Amenity>("amenities", body),

  // Room types
  roomTypes: async () => (await api.get<RoomType[]>("room-types")).data,
  createRoomType: (body: Record<string, unknown>) => api.post<RoomType>("room-types", body),
  updateRoomType: (id: number, body: Record<string, unknown>) => api.patch<RoomType>(`room-types/${id}`, body),
  deleteRoomType: (id: number) => api.delete<null>(`room-types/${id}`),
  uploadRoomTypeImage: (id: number, file: File, alt?: string) => {
    const form = new FormData();
    form.append("image", file);
    if (alt) form.append("alt", alt);
    return api.upload<RoomType>(`room-types/${id}/images`, form);
  },
  deleteRoomTypeImage: (id: number, imageId: number) => api.delete<RoomType>(`room-types/${id}/images/${imageId}`),

  // Rooms
  rooms: async (f: Q = {}) => (await api.get<Room[]>("rooms", f)).data,
  board: async () => (await api.get<BoardRoom[]>("rooms/board")).data,
  room: async (id: number) => (await api.get<Room>(`rooms/${id}`)).data,
  createRoom: (body: { room_type_id: number; number: string; floor?: string | null; notes?: string | null }) => api.post<Room>("rooms", body),
  updateRoom: (id: number, body: Record<string, unknown>) => api.patch<Room>(`rooms/${id}`, body),
  deleteRoom: (id: number) => api.delete<null>(`rooms/${id}`),
  setRoomStatus: (id: number, status: RoomStatus, notes?: string) => api.patch<Room>(`rooms/${id}/status`, { status, notes }),
  blockRoom: (id: number, body: { starts_on: string; ends_on: string; reason: RoomBlock["reason"]; notes?: string }) =>
    api.post<RoomBlock>(`rooms/${id}/blocks`, body),
  unblockRoom: (id: number, blockId: number) => api.delete<null>(`rooms/${id}/blocks/${blockId}`),

  // Front desk
  summary: async (date?: string) => (await api.get<FrontDeskSummary>("front-desk/summary", { date })).data,

  // Guests
  guests: (f: Q = {}) => api.list<Guest>("guests", f),
  guest: async (id: string) => (await api.get<Guest>(`guests/${id}`)).data,
  createGuest: (body: Partial<Guest>) => api.post<Guest>("guests", body),
  updateGuest: (id: string, body: Partial<Guest>) => api.patch<Guest>(`guests/${id}`, body),
  guestHistory: (id: string) => api.list<Reservation>(`guests/${id}/reservations`),
  guestDocuments: async (id: string) => (await api.get<GuestDocument[]>(`guests/${id}/documents`)).data,
  uploadGuestDocument: (id: string, body: { type: string; number?: string; expires_on?: string; file: File }) => {
    const form = new FormData();
    form.append("type", body.type);
    if (body.number) form.append("number", body.number);
    if (body.expires_on) form.append("expires_on", body.expires_on);
    form.append("file", body.file);
    return api.upload<GuestDocument>(`guests/${id}/documents`, form);
  },
  verifyGuestDocument: (id: string, docId: string) => api.post<GuestDocument>(`guests/${id}/documents/${docId}/verify`),
  deleteGuestDocument: (id: string, docId: string) => api.delete<null>(`guests/${id}/documents/${docId}`),

  // Reservations
  reservations: (f: Q = {}, signal?: AbortSignal) => api.list<Reservation>("reservations", f, signal),
  reservation: async (id: string) => (await api.get<Reservation>(`reservations/${id}`)).data,
  deskAvailability: async (checkIn: string, checkOut: string) =>
    (await api.get<DeskAvailability>("reservations/availability", { check_in: checkIn, check_out: checkOut })).data,
  createReservation: (body: Record<string, unknown>) => api.post<Reservation>("reservations", body),
  updateReservation: (id: string, body: { special_requests?: string | null; internal_notes?: string | null }) =>
    api.patch<Reservation>(`reservations/${id}`, body),
  cancelReservation: (id: string, reason: string) => api.post<Reservation>(`reservations/${id}/cancel`, { reason }),
};

export const ROOM_STATUS_LABELS: Record<RoomStatus, string> = {
  AVAILABLE: "Available",
  RESERVED: "Reserved",
  OCCUPIED: "Occupied",
  DIRTY: "Dirty",
  CLEANING: "Cleaning",
  MAINTENANCE: "Maintenance",
  OUT_OF_SERVICE: "Out of service",
  BLOCKED: "Blocked",
};

export const ROOM_STATUS_TONES: Record<RoomStatus, "success" | "info" | "brand" | "warning" | "danger" | "neutral"> = {
  AVAILABLE: "success",
  RESERVED: "info",
  OCCUPIED: "brand",
  DIRTY: "warning",
  CLEANING: "warning",
  MAINTENANCE: "danger",
  OUT_OF_SERVICE: "danger",
  BLOCKED: "neutral",
};

export const DOCUMENT_TYPE_LABELS: Record<string, string> = {
  NATIONAL_ID: "National ID (NIN)",
  PASSPORT: "International passport",
  DRIVERS_LICENCE: "Driver's licence",
  VOTERS_CARD: "Voter's card",
  OTHER: "Other",
};
