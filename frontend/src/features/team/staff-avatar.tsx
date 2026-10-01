/* eslint-disable @next/next/no-img-element -- private photo streamed through the BFF; next/image cannot add the session cookie */
import { cn, initials } from "@/lib/utils";
import { type StaffMember, teamApi } from "./api";

export function StaffAvatar({ member, size = "md" }: { member: Pick<StaffMember, "id" | "name" | "has_photo" | "photo_version">; size?: "sm" | "md" | "lg" }) {
  const box = { sm: "size-9 text-xs", md: "size-12 text-sm", lg: "size-24 text-2xl" }[size];

  return member.has_photo ? (
    <img src={teamApi.photoUrl(member.id, member.photo_version)} alt="" className={cn("shrink-0 rounded-full object-cover", box)} />
  ) : (
    <span aria-hidden className={cn("inline-flex shrink-0 items-center justify-center rounded-full bg-surface-muted font-semibold text-muted", box)}>
      {initials(member.name)}
    </span>
  );
}
