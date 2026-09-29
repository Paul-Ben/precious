import { api } from "@/lib/api/client";
import type { Role, User } from "@/lib/api/types";

export interface UserFilters {
  search?: string;
  type?: "" | "staff" | "customer";
  status?: "" | "active" | "suspended";
  role?: string;
  page?: number;
}

export const userKeys = {
  all: ["users"] as const,
  list: (filters: UserFilters) => ["users", "list", filters] as const,
  detail: (id: string) => ["users", "detail", id] as const,
};

export const usersApi = {
  list: (filters: UserFilters, signal?: AbortSignal) =>
    api.list<User>("users", { ...filters, per_page: 20 }, signal),
  get: async (id: string) => (await api.get<User>(`users/${id}`)).data,
  createStaff: (body: { name: string; email: string; phone?: string | null; roles: string[]; two_factor_enabled?: boolean }) =>
    api.post<User>("users", body),
  update: (id: string, body: Partial<Pick<User, "name" | "email" | "phone" | "two_factor_enabled">>) =>
    api.patch<User>(`users/${id}`, body),
  syncRoles: (id: string, roles: string[]) => api.put<User>(`users/${id}/roles`, { roles }),
  suspend: (id: string, reason?: string) => api.post<User>(`users/${id}/suspend`, { reason }),
  activate: (id: string) => api.post<User>(`users/${id}/activate`),
  temporaryPassword: (id: string) => api.post<null>(`users/${id}/temporary-password`),
};

export const rolesApi = {
  list: async () => (await api.get<Role[]>("roles")).data,
};
