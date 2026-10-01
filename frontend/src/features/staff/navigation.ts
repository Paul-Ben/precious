import {
  BarChart3,
  BedDouble,
  Building2,
  CalendarCheck,
  CalendarDays,
  ClipboardList,
  Clock,
  ConciergeBell,
  CreditCard,
  GlassWater,
  HandCoins,
  IdCard,
  Landmark,
  LayoutDashboard,
  Lock,
  type LucideIcon,
  Martini,
  Receipt,
  ScrollText,
  ShieldCheck,
  Shirt,
  UserCheck,
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
      { label: "My shifts", href: "/staff/my-shifts", icon: Clock, permissions: [] },
    ],
  },
  {
    title: "Operations",
    items: [
      { label: "Reservations", href: "/staff/reservations", icon: CalendarCheck, permissions: ["reservations.view"] },
      { label: "Rooms", href: "/staff/rooms", icon: BedDouble, permissions: ["rooms.view"] },
      { label: "Guests", href: "/staff/guests", icon: UserRound, permissions: ["guests.view"] },
      { label: "Bar", href: "/staff/bar", icon: Wine, permissions: ["bar.orders.create"] },
      { label: "Bar queue", href: "/staff/bar/queue", icon: GlassWater, permissions: ["bar.orders.prepare"] },
      { label: "Payments", href: "/staff/payments", icon: CreditCard, permissions: ["payments.view"] },
      { label: "Reports", href: "/staff/reports", icon: BarChart3, permissions: ["reports.view"] },
    ],
  },
  {
    title: "Finance",
    items: [
      { label: "Finance overview", href: "/staff/finance", icon: Landmark, permissions: ["finance.view", "finance.reports"] },
      { label: "Expenses", href: "/staff/finance/expenses", icon: Receipt, permissions: ["finance.expenses", "finance.view", "finance.expenses.approve"] },
      { label: "Daily closing", href: "/staff/finance/closing", icon: Lock, permissions: ["finance.close_day", "finance.view"] },
      { label: "Outstanding bills", href: "/staff/finance/outstanding", icon: HandCoins, permissions: ["finance.view", "finance.reports"] },
    ],
  },
  {
    title: "People",
    items: [
      { label: "Staff", href: "/staff/team", icon: IdCard, permissions: ["staff.view"] },
      { label: "Rota", href: "/staff/rota", icon: CalendarDays, permissions: ["staff.schedule", "staff.view"] },
      { label: "Attendance", href: "/staff/attendance", icon: UserCheck, permissions: ["staff.view", "staff.schedule"] },
    ],
  },
  {
    title: "Administration",
    items: [
      { label: "Users", href: "/staff/users", icon: Users, permissions: ["users.view"] },
      { label: "Roles & permissions", href: "/staff/roles", icon: ShieldCheck, permissions: ["roles.view"] },
      { label: "Property & policies", href: "/staff/settings/property", icon: Building2, permissions: ["settings.view", "settings.update"] },
      { label: "Hotel services", href: "/staff/settings/services", icon: Shirt, permissions: ["services.view", "services.manage"] },
      { label: "Bar setup", href: "/staff/bar/setup", icon: Martini, permissions: ["bar.products.manage", "bar.tables.manage"] },
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
