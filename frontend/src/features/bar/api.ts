import { api } from "@/lib/api/client";
import type { BarCategory, BarMenuCategory, BarOrder, BarOrderStatus, BarProduct, BarTab, BarTable, BarTableStatus, Payment } from "@/lib/api/types";

export const barKeys = {
  menu: ["bar", "menu"] as const,
  tables: ["bar", "tables"] as const,
  tabs: (f: Record<string, unknown> = {}) => ["bar", "tabs", f] as const,
  tab: (id: string) => ["bar", "tab", id] as const,
  queue: ["bar", "queue"] as const,
  categories: ["bar", "categories"] as const,
  products: ["bar", "products"] as const,
};

export const barApi = {
  menu: async () => (await api.get<BarMenuCategory[]>("bar/menu")).data,
  tables: async (all = false) => (await api.get<BarTable[]>("bar/tables", all ? { all: 1 } : undefined)).data,
  tabs: (f: Record<string, string | number | boolean | undefined>) => api.list<BarTab>("bar/tabs", f),
  tab: async (id: string) => (await api.get<BarTab>(`bar/tabs/${id}`)).data,
  openTab: (body: { table_id?: number | null; customer_name?: string; customer_phone?: string; customer_email?: string }) => api.post<BarTab>("bar/tabs", body),
  updateTab: (id: string, body: { customer_name?: string | null; customer_phone?: string | null; customer_email?: string | null }) => api.patch<BarTab>(`bar/tabs/${id}`, body),
  placeOrder: (tabId: string, items: { product_id: number; quantity: number; notes?: string }[], notes?: string) =>
    api.post<BarOrder>(`bar/tabs/${tabId}/orders`, { items, notes: notes || undefined }),
  discount: (tabId: string, amount: string, reason: string) => api.post<BarTab>(`bar/tabs/${tabId}/discount`, { amount, reason }),
  pay: (tabId: string, body: { method: string; amount: string; external_reference?: string; send_receipt: boolean }) =>
    api.post<Payment>(`bar/tabs/${tabId}/payments`, body),
  chargeToRoom: (tabId: string, room_number: string, surname: string) => api.post<BarTab>(`bar/tabs/${tabId}/charge-to-room`, { room_number, surname }),
  close: (tabId: string) => api.post<BarTab>(`bar/tabs/${tabId}/close`),
  email: (tabId: string, email?: string) => api.post(`bar/tabs/${tabId}/email`, email ? { email } : {}),

  queue: async () => (await api.get<BarOrder[]>("bar/orders/queue")).data,
  advance: (orderId: string, status: BarOrderStatus) => api.post<BarOrder>(`bar/orders/${orderId}/status`, { status }),
  cancelOrder: (orderId: string, reason?: string) => api.post<BarOrder>(`bar/orders/${orderId}/cancel`, reason ? { reason } : {}),

  categories: async () => (await api.get<BarCategory[]>("bar/categories")).data,
  createCategory: (name: string) => api.post<BarCategory>("bar/categories", { name }),
  updateCategory: (id: number, body: Partial<BarCategory>) => api.patch<BarCategory>(`bar/categories/${id}`, body),
  deleteCategory: (id: number) => api.delete(`bar/categories/${id}`),
  products: async () => (await api.get<BarProduct[]>("bar/products")).data,
  createProduct: (body: Partial<BarProduct>) => api.post<BarProduct>("bar/products", body),
  updateProduct: (id: number, body: Partial<BarProduct>) => api.patch<BarProduct>(`bar/products/${id}`, body),
  setAvailability: (id: number, is_available: boolean) => api.patch<BarProduct>(`bar/products/${id}/availability`, { is_available }),
  deleteProduct: (id: number) => api.delete(`bar/products/${id}`),
  createTable: (body: Partial<BarTable>) => api.post<BarTable>("bar/tables", body),
  updateTable: (id: number, body: Partial<BarTable>) => api.patch<BarTable>(`bar/tables/${id}`, body),
  setTableStatus: (id: number, status: BarTableStatus) => api.patch<BarTable>(`bar/tables/${id}/status`, { status }),
  deleteTable: (id: number) => api.delete(`bar/tables/${id}`),

  // Public pay link
  publicTab: async (number: string, token: string) => (await api.get<BarTab>(`public/bar-tabs/${encodeURIComponent(number)}`, { token })).data,
};

export const ORDER_STATUS_LABEL: Record<BarOrderStatus, string> = {
  PLACED: "New",
  ACCEPTED: "Accepted",
  PREPARING: "Preparing",
  READY: "Ready",
  DELIVERED: "Delivered",
  CANCELLED: "Cancelled",
};

export const ORDER_STATUS_TONE: Record<BarOrderStatus, "neutral" | "success" | "warning" | "danger" | "info" | "brand"> = {
  PLACED: "warning",
  ACCEPTED: "info",
  PREPARING: "info",
  READY: "success",
  DELIVERED: "neutral",
  CANCELLED: "danger",
};

/** "3 min" since a timestamp. */
export function minutesSince(iso: string | null, now: number): string {
  if (!iso) return "";
  const m = Math.max(0, Math.floor((now - Date.parse(iso)) / 60000));
  return m < 1 ? "just now" : `${m} min`;
}
