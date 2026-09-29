import { Badge } from "@/components/ui/badge";
import type { RoomStatus } from "@/lib/api/types";
import { ROOM_STATUS_LABELS, ROOM_STATUS_TONES } from "./api";

export function RoomStatusBadge({ status }: { status: RoomStatus }) {
  return <Badge tone={ROOM_STATUS_TONES[status]}>{ROOM_STATUS_LABELS[status]}</Badge>;
}
