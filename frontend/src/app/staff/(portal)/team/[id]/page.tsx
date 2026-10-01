import type { Metadata } from "next";
import { StaffRecord } from "@/features/team/staff-record";

export const metadata: Metadata = { title: "Staff record" };

export default async function Page({ params }: PageProps<"/staff/team/[id]">) {
  const { id } = await params;
  return <StaffRecord id={id} />;
}
