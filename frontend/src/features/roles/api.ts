import { api } from "@/lib/api/client";
import type { PermissionGroup, Role } from "@/lib/api/types";

export const roleKeys = {
  all: ["roles"] as const,
  detail: (id: string | number) => ["roles", "detail", String(id)] as const,
  permissions: ["permissions"] as const,
};

export const rolesApi = {
  list: async () => (await api.get<Role[]>("roles")).data,
  get: async (id: string | number) => (await api.get<Role>(`roles/${id}`)).data,
  permissions: async () => (await api.get<PermissionGroup[]>("permissions")).data,
  create: (body: { name: string; description?: string | null; permissions: string[] }) => api.post<Role>("roles", body),
  update: (id: number, body: { name?: string; description?: string | null }) => api.patch<Role>(`roles/${id}`, body),
  syncPermissions: (id: number, permissions: string[]) => api.put<Role>(`roles/${id}/permissions`, { permissions }),
  remove: (id: number) => api.delete<null>(`roles/${id}`),
};

/** "bar.orders.charge_to_room" -> "Charge to room" style labels for groups. */
export function groupLabel(group: string): string {
  return group.charAt(0).toUpperCase() + group.slice(1).replace(/_/g, " ");
}
