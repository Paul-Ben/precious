/**
 * Types mirroring the Laravel API contract (see backend docs/api/README.md).
 */

export interface ApiSuccess<T> {
  success: true;
  message: string;
  data: T;
  meta: PaginationMeta | Record<string, unknown>;
}

export interface ApiFailure {
  success: false;
  message: string;
  code: string;
  errors?: Record<string, string[]>;
  [key: string]: unknown;
}

export type ApiEnvelope<T> = ApiSuccess<T> | ApiFailure;

export interface PaginationMeta {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
}

export interface Paginated<T> {
  items: T[];
  meta: PaginationMeta;
}

export type UserType = "customer" | "staff";
export type UserStatus = "active" | "suspended";

export interface User {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  type: UserType;
  status: UserStatus;
  must_change_password: boolean;
  two_factor_enabled: boolean;
  two_factor_required: boolean;
  is_super_admin: boolean;
  roles: string[];
  permissions?: string[];
  last_login_at: string | null;
  created_at: string | null;
}

export interface TwoFactorChallenge {
  challenge_id: string;
  channel: "email";
  destination: string;
  expires_at: string;
}

export type LoginResult =
  | { two_factor_required: true; two_factor: TwoFactorChallenge }
  | { two_factor_required: false; expires_at: string; user: User };

export interface Role {
  id: number;
  name: string;
  description: string | null;
  is_system: boolean;
  users_count?: number;
  permissions?: string[];
  created_at: string | null;
  updated_at: string | null;
}

export interface PermissionGroup {
  group: string;
  permissions: { name: string; description: string }[];
}

export type GatewayMode = "test" | "live";

export interface GatewayField {
  key: string;
  label: string;
  secret: boolean;
  required: boolean;
  help: string;
}

export interface PaymentGateway {
  gateway: string;
  name: string;
  display_name: string;
  is_enabled: boolean;
  is_default: boolean;
  mode: GatewayMode;
  docs_url: string;
  dashboard_url: string;
  webhook_url: string;
  fields: GatewayField[];
  credentials: Record<GatewayMode, Record<string, { set: boolean; preview: string | null }>>;
  missing: Record<GatewayMode, string[]>;
  last_test: {
    tested_at: string;
    mode: GatewayMode;
    succeeded: boolean;
    message: string;
  } | null;
  updated_at: string | null;
}

export interface AuditLog {
  id: string;
  action: string;
  actor: { id: string; name: string; email: string } | null;
  auditable_type: string | null;
  auditable_id: string | null;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  metadata: Record<string, unknown> | null;
  ip_address: string | null;
  user_agent: string | null;
  request_id: string | null;
  created_at: string;
}
