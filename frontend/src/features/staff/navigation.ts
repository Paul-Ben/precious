import {
  BedDouble,
  Building2,
  CalendarCheck,
  ClipboardList,
  ConciergeBell,
  CreditCard,
  LayoutDashboard,
  type LucideIcon,
  ScrollText,
  ShieldCheck,
  Shirt,
  UserRound,
  Users,
  Wine,
} from "lucide-react";

export interface NavItem {
  label: string;
  href: string;
  icon: LucideIcon;
  /** Shown when the user has ANY of these permissions. Empty = everyone. */
  permissions: string[];
  /** Module not built yet - rendered disabled with a "Soon" tag. */
  soon?: boolean;
}

export interface NavSection {
  title: string;
  items: NavItem[];
}

export const staffNavigation: NavSection[] = [
  {
    title: "Overview",
    items: [
      { label: "Dashboard", href: "/staff/dashboard", icon: LayoutDashboard, permissions: [] },
      { label: "Front desk", href: "/staff/front-desk", icon: ConciergeBell, permissions: ["reservations.view", "rooms.view"] },
    ],
  },
  {
    title: "Operations",
    items: [
      { label: "Reservations", href: "/staff/reservations", icon: CalendarCheck, permissions: ["reservations.view"] },
      { label: "Rooms", href: "/staff/rooms", icon: BedDouble, permissions: ["rooms.view"] },
      { label: "Guests", href: "/staff/guests", icon: UserRound, permissions: ["guests.view"] },
      { label: "Bar", href: "/staff/bar", icon: Wine, permissions: ["bar.orders.view"], soon: true },
      { label: "Payments", href: "/staff/payments", icon: CreditCard, permissions: ["payments.view"] },
    ],
  },
  {
    title: "Administration",
    items: [
      { label: "Users", href: "/staff/users", icon: Users, permissions: ["users.view"] },
      { label: "Roles & permissions", href: "/staff/roles", icon: ShieldCheck, permissions: ["roles.view"] },
      { label: "Property & policies", href: "/staff/settings/property", icon: Building2, permissions: ["settings.view", "settings.update"] },
      { label: "Hotel services", href: "/staff/settings/services", icon: Shirt, permissions: ["services.view", "services.manage"] },
      {
        label: "Payment gateways",
        href: "/staff/settings/payment-gateways",
        icon: ClipboardList,
        permissions: ["settings.payment_gateways.manage"],
      },
      { label: "Audit log", href: "/staff/audit-logs", icon: ScrollText, permissions: ["audit.view"] },
    ],
  },
];
