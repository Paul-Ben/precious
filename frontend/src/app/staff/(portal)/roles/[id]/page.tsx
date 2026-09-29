import type { Metadata } from "next";
import { RoleDetail } from "@/features/roles/role-detail";

export const metadata: Metadata = { title: "Role" };

export default async function Page({ params }: PageProps<"/staff/roles/[id]">) {
  const { id } = await params;
  return <RoleDetail id={id} />;
}
