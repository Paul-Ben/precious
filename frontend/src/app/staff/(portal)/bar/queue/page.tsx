import type { Metadata } from "next";
import { QueuePage } from "@/features/bar/queue-page";

export const metadata: Metadata = { title: "Bar queue" };

export default function Page() {
  return <QueuePage />;
}
