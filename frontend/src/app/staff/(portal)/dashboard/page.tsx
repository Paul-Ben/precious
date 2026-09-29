import type { Metadata } from "next";
import { StaffDashboard } from "@/features/staff/dashboard";

export const metadata: Metadata = { title: "Dashboard" };

export default function DashboardPage() {
  return <StaffDashboard />;
}
