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
  fees: GatewayFees;
  fee_defaults: GatewayFees | null;
  last_test: {
    tested_at: string;
    mode: GatewayMode;
    succeeded: boolean;
    message: string;
  } | null;
  updated_at: string | null;
}

export interface GatewayFees {
  percent: string;
  flat: string | null;
  flat_waived_below: string | null;
  cap: string | null;
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

// ---------------------------------------------------------------- Hotel (M2)

/** Money is always a decimal string from the API, e.g. "150000.00". */
export type Money = string;

export interface Amenity {
  id: number;
  name: string;
  icon: string | null;
  sort_order: number;
}

export interface RoomTypeImage {
  id: number;
  url: string;
  alt: string | null;
  sort_order: number;
}

export interface RoomType {
  id: number;
  name: string;
  slug: string;
  short_description: string | null;
  description: string | null;
  base_rate: Money;
  currency: string;
  max_adults: number;
  max_children: number;
  max_occupancy: number;
  bed_type: string | null;
  size_sqm: number | null;
  is_active: boolean;
  sort_order: number;
  rooms_count?: number;
  amenities?: Amenity[];
  images?: RoomTypeImage[];
  cover_image_url: string | null;
}

export interface Quote {
  nights: number;
  lines: { room_type_id: number; room_type: string; quantity: number; nightly_rate: Money; nights: number; subtotal: Money }[];
  subtotal: Money;
  service_charge_percent: string;
  service_charge: Money;
  vat_percent: string;
  vat: Money;
  total: Money;
  deposit_percent: string;
  deposit: Money;
  balance_after_deposit: Money;
  currency: string;
  available?: boolean;
  shortages?: { room_type_id: number; available: number }[];
}

export interface AvailabilityResult {
  check_in: string;
  check_out: string;
  nights: number;
  adults: number;
  children: number;
  room_types: (RoomType & { available_count: number; fits_party_in_one_room: boolean; quote: Quote })[];
}

export type RoomStatus =
  | "AVAILABLE"
  | "RESERVED"
  | "OCCUPIED"
  | "DIRTY"
  | "CLEANING"
  | "MAINTENANCE"
  | "OUT_OF_SERVICE"
  | "BLOCKED";

export interface RoomBlock {
  id: number;
  room_id: number;
  starts_on: string;
  ends_on: string;
  reason: "MAINTENANCE" | "OUT_OF_SERVICE" | "BLOCKED";
  notes: string | null;
  created_by?: string | null;
  created_at: string | null;
}

export interface Room {
  id: number;
  number: string;
  floor: string | null;
  status: RoomStatus;
  is_active: boolean;
  notes: string | null;
  maintenance_notes: string | null;
  room_type?: { id: number; name: string; base_rate: Money };
  blocks?: RoomBlock[];
  updated_at: string | null;
}

export interface BoardRoom extends Room {
  today: {
    reservation_id: string;
    number: string;
    status: ReservationStatus;
    guest: string | null;
    check_in: string;
    check_out: string;
    arriving_today: boolean;
    departing_today: boolean;
  }[];
}

export interface Guest {
  id: string;
  first_name: string;
  last_name: string;
  full_name: string;
  email: string | null;
  phone: string | null;
  nationality: string | null;
  date_of_birth: string | null;
  address: string | null;
  company: string | null;
  is_vip: boolean;
  notes: string | null;
  has_account: boolean;
  reservations_count?: number;
  created_at: string | null;
}

export type GuestDocumentType = "NATIONAL_ID" | "PASSPORT" | "DRIVERS_LICENCE" | "VOTERS_CARD" | "OTHER";

export interface GuestDocument {
  id: string;
  type: GuestDocumentType;
  number_masked: string | null;
  expires_on: string | null;
  original_name: string;
  mime_type: string;
  size_bytes: number;
  verified_at: string | null;
  uploaded_by?: string | null;
  created_at: string | null;
}

export type ReservationStatus =
  | "DRAFT"
  | "PENDING_PAYMENT"
  | "CONFIRMED"
  | "PARTIALLY_PAID"
  | "CHECK_IN_PENDING"
  | "CHECKED_IN"
  | "CHECKED_OUT"
  | "CANCELLED"
  | "EXPIRED"
  | "NO_SHOW";

export type PaymentStatus = "UNPAID" | "DEPOSIT_PAID" | "PARTIALLY_PAID" | "PAID" | "REFUNDED";
export type ReservationSource = "WEBSITE" | "FRONT_DESK" | "PHONE" | "WALK_IN";

export interface Reservation {
  id: string;
  number: string;
  status: ReservationStatus;
  payment_status: PaymentStatus;
  source: ReservationSource;
  check_in: string;
  check_out: string;
  nights: number;
  adults: number;
  children: number;
  currency: string;
  subtotal: Money;
  service_charge_total: Money;
  tax_total: Money;
  total: Money;
  /** Extras added during the stay; grand_total = total + charges_total. */
  charges_total: Money;
  grand_total: Money;
  deposit_percent: string;
  deposit_amount: Money;
  amount_paid: Money;
  balance: Money;
  pricing: Quote;
  free_cancellation_hours: number;
  expires_at: string | null;
  confirmed_at: string | null;
  checked_in_at?: string | null;
  checked_out_at?: string | null;
  balance_at_checkout?: Money | null;
  cancellation: { cancelled_at: string; reason: string | null; refund_eligible: boolean | null } | null;
  special_requests: string | null;
  internal_notes?: string | null;
  guest?: { id: string; full_name: string; email: string | null; phone: string | null };
  guests?: { id: string; full_name: string; is_primary: boolean }[];
  rooms?: {
    id: number;
    room_id: number;
    room_number: string | null;
    room_type: { id: number; name: string | null };
    adults: number;
    children: number;
    nightly_rate: Money;
    nights: number;
    subtotal: Money;
    is_active: boolean;
  }[];
  booked_by?: { id: string; name: string } | null;
  /** Guests see successful payments only; staff see every attempt. */
  payments?: Payment[];
  charges?: Charge[];
  stays?: Stay[];
  folio_number?: string | null;
  created_at: string | null;
}

export interface HotelPolicies {
  deposit_percent: string;
  hold_minutes: number;
  staff_hold_max_minutes?: number;
  vat_percent: string;
  vat_on_accommodation: boolean;
  service_charge_percent?: string;
  accommodation_service_charge_percent?: string;
  check_in_time: string;
  check_out_time: string;
  late_checkout_half_rate_until?: string;
  free_cancellation_hours: number;
  no_show_time?: string;
  max_nights: number;
  max_rooms_per_booking: number;
  booking_window_days: number;
  pass_gateway_fees_to_customer?: boolean;
  require_id_at_check_in?: boolean;
  refund_second_approval_above?: string;
}

export interface Property {
  id: number;
  name: string;
  slug: string;
  legal_name: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
  city: string | null;
  state: string | null;
  country: string;
  timezone: string;
  currency: string;
  description: string | null;
  policies?: HotelPolicies;
  departments?: { id: number; name: string; code: string; is_active: boolean }[];
}

export interface FrontDeskSummary {
  date: string;
  arrivals: number;
  arrivals_checked_in: number;
  departures: number;
  departures_checked_out: number;
  in_house: number;
  pending_payment: number;
  available_tonight: number;
  total_rooms: number;
  rooms_by_status: Partial<Record<RoomStatus, number>>;
}

// -------------------------------------------------------------- Payments (M2b)

export type TransactionStatus = "PENDING" | "SUCCESSFUL" | "FAILED" | "ABANDONED";
export type PaymentMethod = "GATEWAY" | "CASH" | "POS" | "BANK_TRANSFER";
export type PaymentPurpose = "DEPOSIT" | "BALANCE" | "FULL" | "PART";
export type RefundStatus = "REQUESTED" | "APPROVED" | "REJECTED" | "COMPLETED";
export type RefundMethod = "GATEWAY_DASHBOARD" | "CASH" | "BANK_TRANSFER";

export interface Payment {
  id: string;
  reference: string;
  method: PaymentMethod;
  method_label: string;
  gateway: string | null;
  gateway_mode: "test" | "live" | null;
  channel: string | null;
  purpose: PaymentPurpose;
  status: TransactionStatus;
  currency: string;
  amount: Money;
  customer_fee: Money;
  charged_amount: Money;
  refunded_amount: Money;
  paid_at: string | null;
  created_at: string | null;
  receipt_number?: string | null;
  // Staff only
  gateway_fee?: Money | null;
  gateway_transaction_id?: string | null;
  external_reference?: string | null;
  note?: string | null;
  payer_email?: string | null;
  failure_reason?: string | null;
  needs_attention?: boolean;
  attention_reason?: string | null;
  verify_attempts?: number;
  last_verified_at?: string | null;
  recorded_by?: { id: string; name: string } | null;
  refundable?: Money;
  refunds?: { id: string; number: string; amount: Money; status: RefundStatus }[];
  payable?: { type: "reservation"; id: string; number: string } | null;
  guest?: { id: string; full_name: string } | null;
}

export interface PaymentOption {
  option: "deposit" | "balance";
  purpose: PaymentPurpose;
  label: string;
  amount: Money;
  by_gateway: { gateway: string; fee: Money; total: Money }[];
}

export interface PaymentOptions {
  payable: boolean;
  reason: string | null;
  fees_passed_to_customer: boolean;
  options: PaymentOption[];
  gateways: { gateway: string; name: string; is_default: boolean; test_mode: boolean }[];
}

export interface Checkout {
  reference: string;
  gateway: string;
  amount: Money;
  customer_fee: Money;
  charged_amount: Money;
  authorization_url: string;
}

export interface PaymentVerification {
  reference: string;
  status: TransactionStatus;
  amount: Money;
  customer_fee: Money;
  charged_amount: Money;
  failure_reason: string | null;
  receipt_number: string | null;
  reservation: { number: string; status: ReservationStatus; payment_status: PaymentStatus; balance: Money } | null;
}

export interface Receipt {
  number: string;
  issued_at: string;
  emailed_at: string | null;
  payment_id: string;
  hotel: { name: string; legal_name: string | null; address: string; phone: string | null; email: string | null };
  received_from: string | null;
  guest_email: string | null;
  for: { type: "reservation"; number: string; check_in: string; check_out: string; nights: number };
  payment: {
    reference: string;
    method: PaymentMethod;
    method_label: string;
    gateway: string | null;
    channel: string | null;
    external_reference: string | null;
    purpose: PaymentPurpose;
    paid_at: string;
  };
  currency: string;
  amount: Money;
  processing_fee: Money;
  total_charged: Money;
  reservation_total: Money;
  total_paid_to_date: Money;
  balance_after: Money;
  received_by: string | null;
}

export interface Refund {
  id: string;
  number: string;
  amount: Money;
  reason: string;
  status: RefundStatus;
  requires_second_approval: boolean;
  method: RefundMethod | null;
  external_reference: string | null;
  decision_note: string | null;
  requested_by?: { id: string; name: string } | null;
  approved_by?: { id: string; name: string } | null;
  rejected_by?: { id: string; name: string } | null;
  completed_by?: { id: string; name: string } | null;
  approved_at: string | null;
  rejected_at: string | null;
  completed_at: string | null;
  created_at: string | null;
  payment: {
    id: string;
    reference: string;
    method: PaymentMethod;
    method_label: string;
    gateway: string | null;
    amount: Money;
    payable_number: string | null;
    payable_id: string;
  } | null;
}

export interface CashUp {
  date: string;
  by_method: { method: PaymentMethod; label: string; count: number; amount: Money; customer_fees: Money }[];
  received: Money;
  refunded: Money;
  refunds_count: number;
  net: Money;
  cash_in_hand: Money;
  needs_attention: number;
}

// ------------------------------------------------------- Stays & folio (M2c)

export type ChargeCategory = "SERVICE" | "EXTRA_NIGHT" | "LATE_CHECKOUT" | "ADJUSTMENT" | "OTHER";

export interface Charge {
  id: string;
  category: ChargeCategory;
  category_label: string;
  description: string;
  quantity: string;
  unit_price: Money;
  subtotal: Money;
  service_charge: Money;
  vat: Money;
  total: Money;
  status: "ACTIVE" | "VOIDED";
  stay_id: string | null;
  service_id: number | null;
  reason: string | null;
  created_at: string | null;
  created_by?: string | null;
  voided_at: string | null;
  voided_by?: string | null;
  void_reason: string | null;
}

export interface Stay {
  id: string;
  status: "OPEN" | "CLOSED";
  close_reason: "CHECKED_OUT" | "MOVED" | null;
  room?: { id: number; number: string } | null;
  reservation_room_id: number;
  checked_in_at: string | null;
  checked_in_by?: string | null;
  closed_at: string | null;
  id_type: string | null;
  id_number_masked: string | null;
  notes: string | null;
  reservation?: { id: string; number: string; check_in: string; check_out: string; balance: Money };
  guest?: { id: string; full_name: string; phone: string | null } | null;
}

export interface HotelService {
  id: number;
  name: string;
  category: string;
  description: string | null;
  price: Money;
  charges_vat: boolean;
  charges_service_charge: boolean;
  is_active: boolean;
  sort_order: number;
}

export interface CheckInOptions {
  can_check_in: boolean;
  blockers: string[];
  id_required: boolean;
  id_on_file: boolean;
  rooms: {
    reservation_room_id: number;
    room_type: { id: number; name: string | null };
    adults: number;
    children: number;
    current: { id: number; number: string; status: RoomStatus; ready: boolean } | null;
    alternatives: { id: number; number: string; room_type: string | null; same_type: boolean }[];
  }[];
}

export interface BillSummary {
  accommodation: {
    subtotal: Money;
    service_charge: Money;
    vat: Money;
    total: Money;
    nights: number;
    rooms: { room_number: string | null; room_type: string | null; check_in: string; check_out: string; nightly_rate: Money }[];
  };
  charges: { id: string; category: ChargeCategory; description: string; quantity: string; unit_price: Money; subtotal: Money; service_charge: Money; vat: Money; total: Money; date: string | null }[];
  charges_total: Money;
  grand_total: Money;
  payments: { date: string | null; method: string; receipt_number: string | null; amount: Money; refunded: Money }[];
  paid: Money;
  balance: Money;
}

export interface CheckOutPreview {
  status: ReservationStatus;
  can_check_out: boolean;
  overstay: boolean;
  early_departure: boolean;
  check_out_time: string;
  late_fee: { already_charged: boolean; percent: number; total: Money; rooms: { room_number: string | null; nightly_rate: Money; total: Money }[] };
  bill: BillSummary;
  balance_now: Money;
  balance_with_late_fee: Money;
  can_override_balance: boolean;
}

export interface FolioStatement extends BillSummary {
  number: string;
  reservation_id: string;
  issued_at: string;
  emailed_at: string | null;
  hotel: { name: string; legal_name: string | null; address: string; phone: string | null; email: string | null };
  guest: { name: string | null; email: string | null; phone: string | null };
  reservation: { number: string; check_in: string; check_out: string; checked_in_at: string | null; checked_out_at: string };
}
