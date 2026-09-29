import { api } from "@/lib/api/client";
import type { Charge, CheckInOptions, CheckOutPreview, FolioStatement, HotelService, Reservation, Stay } from "@/lib/api/types";

export const stayKeys = {
  inHouse: ["stays", "in-house"] as const,
  checkIn: (id: string) => ["stays", "check-in", id] as const,
  checkOut: (id: string) => ["stays", "check-out", id] as const,
  services: ["stays", "services"] as const,
  folio: (number: string) => ["stays", "folio", number] as const,
};

export interface AddChargeBody {
  service_id?: number;
  category?: "OTHER" | "ADJUSTMENT";
  description?: string;
  quantity?: string;
  unit_price?: string;
  amount?: string;
  charges_vat?: boolean;
  charges_service_charge?: boolean;
  stay_id?: string | null;
  reason?: string;
}

export const stayApi = {
  inHouse: async () => (await api.get<Stay[]>("stays")).data,
  checkInOptions: async (id: string) => (await api.get<CheckInOptions>(`reservations/${id}/check-in`)).data,
  checkIn: (id: string, body: { rooms?: { reservation_room_id: number; room_id: number }[]; id_type?: string; id_number?: string; notes?: string }) =>
    api.post<Reservation>(`reservations/${id}/check-in`, body),
  extend: (id: string, check_out: string) => api.post<Reservation>(`reservations/${id}/extend`, { check_out }),
  move: (stayId: string, room_id: number, reason: string) => api.post<Reservation>(`stays/${stayId}/move`, { room_id, reason }),
  checkOutPreview: async (id: string) => (await api.get<CheckOutPreview>(`reservations/${id}/check-out`)).data,
  checkOut: (id: string, body: { apply_late_fee?: boolean; waive_reason?: string; override_balance?: boolean; override_reason?: string }) =>
    api.post<{ reservation: Reservation; statement_number: string }>(`reservations/${id}/check-out`, body),

  addCharge: (reservationId: string, body: AddChargeBody) => api.post<Charge>(`reservations/${reservationId}/charges`, body),
  voidCharge: (id: string, reason: string) => api.post<Charge>(`charges/${id}/void`, { reason }),

  services: async (activeOnly = false) => {
    const res = await api.get<HotelService[]>("services", activeOnly ? { active: 1 } : undefined);
    return { items: res.data, categories: ((res.meta as { categories?: string[] })?.categories ?? []) as string[] };
  },
  createService: (body: Partial<HotelService>) => api.post<HotelService>("services", body),
  updateService: (id: number, body: Partial<HotelService>) => api.patch<HotelService>(`services/${id}`, body),
  deleteService: (id: number) => api.delete(`services/${id}`),

  folio: async (number: string) => (await api.get<FolioStatement>(`folios/${number}`)).data,
  emailFolio: (number: string, email?: string) => api.post<FolioStatement>(`folios/${number}/email`, email ? { email } : {}),
};

export const ID_TYPES = [
  { value: "NATIONAL_ID", label: "National ID (NIN)" },
  { value: "PASSPORT", label: "Passport" },
  { value: "DRIVERS_LICENCE", label: "Driver's licence" },
  { value: "VOTERS_CARD", label: "Voter's card" },
  { value: "OTHER", label: "Other" },
];
