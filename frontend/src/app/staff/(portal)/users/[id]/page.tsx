import type { Metadata } from "next";
import { UserDetail } from "@/features/users/user-detail";

export const metadata: Metadata = { title: "User" };

export default async function Page({ params }: PageProps<"/staff/users/[id]">) {
  const { id } = await params;
  return <UserDetail id={id} />;
}
