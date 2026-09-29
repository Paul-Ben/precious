import {
  BedDouble,
  CalendarCheck,
  ClipboardList,
  CreditCard,
  LayoutDashboard,
  type LucideIcon,
  ScrollText,
  ShieldCheck,
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
    items: [{ label: "Dashboard", href: "/staff/dashboard", icon: LayoutDashboard, permissions: [] }],
  },
  {
    title: "Operations",
    items: [
      { label: "Reservations", href: "/staff/reservations", icon: CalendarCheck, permissions: ["reservations.view"], soon: true },
      { label: "Rooms", href: "/staff/rooms", icon: BedDouble, permissions: ["rooms.view"], soon: true },
      { label: "Bar", href: "/staff/bar", icon: Wine, permissions: ["bar.orders.view"], soon: true },
      { label: "Payments", href: "/staff/payments", icon: CreditCard, permissions: ["payments.view"], soon: true },
    ],
  },
  {
    title: "Administration",
    items: [
      { label: "Users", href: "/staff/users", icon: Users, permissions: ["users.view"] },
      { label: "Roles & permissions", href: "/staff/roles", icon: ShieldCheck, permissions: ["roles.view"] },
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
