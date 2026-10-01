import type { Metadata } from "next";
import { AttendancePage } from "@/features/team/attendance-page";

export const metadata: Metadata = { title: "Attendance" };

export default function Page() {
  return <AttendancePage />;
}
