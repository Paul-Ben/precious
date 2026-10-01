import type { Metadata } from "next";
import { MyShiftsPage } from "@/features/team/my-shifts-page";

export const metadata: Metadata = { title: "My shifts" };

export default function Page() {
  return <MyShiftsPage />;
}
