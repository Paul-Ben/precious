import type { Metadata } from "next";
import { TabPrint } from "@/features/bar/tab-print";

export const metadata: Metadata = { title: "Print bill" };

export default async function Page({ params }: PageProps<"/staff/bar/tabs/[id]/print">) {
  const { id } = await params;
  return <TabPrint id={id} />;
}
