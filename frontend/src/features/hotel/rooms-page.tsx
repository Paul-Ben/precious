"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ImagePlus, Pencil, Plus, Trash2 } from "lucide-react";
import { useRef, useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardHeader } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { ConfirmDialog, Dialog } from "@/components/ui/dialog";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/page-header";
import { Select } from "@/components/ui/select";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { RoomImage } from "@/features/booking/room-image";
import { RequirePermission } from "@/features/staff/require-permission";
import { useSession } from "@/features/staff/session-context";
import { errorMessage, isApiError } from "@/lib/api/errors";
import type { Room, RoomBlock, RoomStatus, RoomType } from "@/lib/api/types";
import { addDays, formatStayDate, todayInHotel } from "@/lib/dates";
import { formatNaira } from "@/lib/money";
import { cn } from "@/lib/utils";
import { hotelApi, hotelKeys, ROOM_STATUS_LABELS } from "./api";
import { RoomStatusBadge } from "./room-status-badge";

export function RoomsPage() {
  return (
    <RequirePermission permission="rooms.view">
      <Rooms />
    </RequirePermission>
  );
}

function Rooms() {
  const [tab, setTab] = useState<"rooms" | "types">("rooms");

  return (
    <>
      <PageHeader title="Rooms" description="Room types are what guests book; rooms are the physical rooms assigned to them." />
      <div role="tablist" aria-label="Rooms sections" className="mb-4 inline-flex rounded-lg bg-surface-muted p-1">
        {(["rooms", "types"] as const).map((t) => (
          <button
            key={t}
            type="button"
            role="tab"
            aria-selected={tab === t}
            onClick={() => setTab(t)}
            className={cn("rounded-md px-4 py-2 text-sm font-medium", tab === t ? "bg-surface shadow-sm" : "text-muted hover:text-foreground")}
          >
            {t === "rooms" ? "Rooms" : "Room types & prices"}
          </button>
        ))}
      </div>
      {tab === "rooms" ? <RoomsList /> : <RoomTypesList />}
    </>
  );
}

// ------------------------------------------------------------------ Rooms

function RoomsList() {
  const { can } = useSession();
  const queryClient = useQueryClient();
  const [typeFilter, setTypeFilter] = useState("");
  const [creating, setCreating] = useState(false);
  const [blocking, setBlocking] = useState<Room | null>(null);
  const [error, setError] = useState<string | null>(null);

  const types = useQuery({ queryKey: hotelKeys.roomTypes, queryFn: hotelApi.roomTypes });
  const rooms = useQuery({
    queryKey: hotelKeys.rooms({ room_type_id: typeFilter, include_inactive: true }),
    queryFn: () => hotelApi.rooms({ room_type_id: typeFilter || undefined, include_inactive: true }),
  });

  const refresh = () => queryClient.invalidateQueries({ queryKey: ["hotel"] });
  const setStatus = useMutation({
    mutationFn: ({ id, status }: { id: number; status: RoomStatus }) => hotelApi.setRoomStatus(id, status),
    onSuccess: refresh,
    onError: (e) => setError(errorMessage(e)),
  });
  const toggleActive = useMutation({
    mutationFn: (room: Room) => hotelApi.updateRoom(room.id, { is_active: !room.is_active }),
    onSuccess: refresh,
    onError: (e) => setError(errorMessage(e)),
  });

  return (
    <Card>
      <CardHeader
        title="Rooms"
        actions={
          <>
            <Select aria-label="Room type" className="h-10 w-48" value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)}>
              <option value="">All types</option>
              {types.data?.map((t) => (
                <option key={t.id} value={t.id}>{t.name}</option>
              ))}
            </Select>
            {can("rooms.create") && (
              <Button onClick={() => setCreating(true)} disabled={!types.data?.length}>
                <Plus className="size-4" aria-hidden="true" /> Add room
              </Button>
            )}
          </>
        }
      />
      {error && <Alert tone="danger" className="m-4">{error}</Alert>}
      {rooms.isPending ? (
        <LoadingState />
      ) : rooms.isError ? (
        <ErrorState error={rooms.error} onRetry={() => rooms.refetch()} />
      ) : rooms.data.length === 0 ? (
        <EmptyState title="No rooms yet">{types.data?.length ? "Add your first room." : "Create a room type first, then add its rooms."}</EmptyState>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[720px] text-left text-sm">
            <thead className="border-b border-border text-xs uppercase tracking-wider text-muted">
              <tr>
                <th className="px-5 py-3 font-medium">Room</th>
                <th className="px-3 py-3 font-medium">Type</th>
                <th className="px-3 py-3 font-medium">Status</th>
                <th className="px-5 py-3 text-right font-medium">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {rooms.data.map((room) => (
                <tr key={room.id} className={room.is_active ? undefined : "opacity-60"}>
                  <td className="px-5 py-3">
                    <span className="text-base font-bold">{room.number}</span>
                    {room.floor && <span className="ml-2 text-xs text-muted">Floor {room.floor}</span>}
                    {!room.is_active && <Badge className="ml-2">Inactive</Badge>}
                  </td>
                  <td className="px-3 py-3">{room.room_type?.name}</td>
                  <td className="px-3 py-3">
                    {can("rooms.manage_status") ? (
                      <Select
                        aria-label={`Status of room ${room.number}`}
                        className="h-9 w-44"
                        value={room.status}
                        onChange={(e) => { setError(null); setStatus.mutate({ id: room.id, status: e.target.value as RoomStatus }); }}
                      >
                        {Object.entries(ROOM_STATUS_LABELS).map(([v, l]) => (
                          <option key={v} value={v}>{l}</option>
                        ))}
                      </Select>
                    ) : (
                      <RoomStatusBadge status={room.status} />
                    )}
                  </td>
                  <td className="space-x-2 px-5 py-3 text-right">
                    {can("rooms.manage_status") && (
                      <Button variant="outline" size="sm" onClick={() => setBlocking(room)}>Block dates</Button>
                    )}
                    {can("rooms.update") && (
                      <Button variant="ghost" size="sm" onClick={() => toggleActive.mutate(room)}>
                        {room.is_active ? "Deactivate" : "Activate"}
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {creating && types.data && <CreateRoomDialog types={types.data} onClose={() => setCreating(false)} />}
      {blocking && <BlockDialog room={blocking} onClose={() => setBlocking(null)} />}
    </Card>
  );
}

function CreateRoomDialog({ types, onClose }: { types: RoomType[]; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({ room_type_id: types[0]!.id, number: "", floor: "" });
  const create = useMutation({
    mutationFn: () => hotelApi.createRoom({ ...form, floor: form.floor || null }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ["hotel"] });
      onClose();
    },
  });
  const err = isApiError(create.error) ? create.error : null;

  return (
    <Dialog
      open
      onClose={onClose}
      title="Add room"
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Cancel</Button>
          <Button loading={create.isPending} disabled={!form.number.trim()} onClick={() => create.mutate()}>Add room</Button>
        </>
      }
    >
      <div className="space-y-4">
        {create.isError && !err?.isValidation && <Alert tone="danger">{errorMessage(create.error)}</Alert>}
        <Field label="Room type" required>
          <Select value={form.room_type_id} onChange={(e) => setForm((f) => ({ ...f, room_type_id: Number(e.target.value) }))}>
            {types.map((t) => (<option key={t.id} value={t.id}>{t.name}</option>))}
          </Select>
        </Field>
        <Field label="Room number" required error={err?.field("number")}>
          <Input value={form.number} onChange={(e) => setForm((f) => ({ ...f, number: e.target.value }))} maxLength={20} />
        </Field>
        <Field label="Floor">
          <Input value={form.floor} onChange={(e) => setForm((f) => ({ ...f, floor: e.target.value }))} maxLength={20} />
        </Field>
      </div>
    </Dialog>
  );
}

function BlockDialog({ room, onClose }: { room: Room; onClose: () => void }) {
  const queryClient = useQueryClient();
  const today = todayInHotel();
  const [form, setForm] = useState({ starts_on: today, ends_on: addDays(today, 1), reason: "MAINTENANCE" as RoomBlock["reason"], notes: "" });
  const detail = useQuery({ queryKey: ["hotel", "room", room.id], queryFn: () => hotelApi.room(room.id) });
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["hotel"] });
  const block = useMutation({ mutationFn: () => hotelApi.blockRoom(room.id, form), onSuccess: refresh });
  const unblock = useMutation({ mutationFn: (id: number) => hotelApi.unblockRoom(room.id, id), onSuccess: refresh });
  const conflicts = isApiError(block.error) ? (block.error.body.reservations as string[] | undefined) : undefined;

  return (
    <Dialog open onClose={onClose} title={`Block room ${room.number}`} description="Blocked dates can't be booked. The end date is the first night the room is sellable again.">
      <div className="space-y-4">
        {block.isError && (
          <Alert tone="danger">
            {errorMessage(block.error)}
            {conflicts?.length ? ` (${conflicts.join(", ")})` : ""}
          </Alert>
        )}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="From">
            <Input type="date" value={form.starts_on} onChange={(e) => setForm((f) => ({ ...f, starts_on: e.target.value }))} />
          </Field>
          <Field label="Until (not included)">
            <Input type="date" value={form.ends_on} min={addDays(form.starts_on, 1)} onChange={(e) => setForm((f) => ({ ...f, ends_on: e.target.value }))} />
          </Field>
          <Field label="Reason">
            <Select value={form.reason} onChange={(e) => setForm((f) => ({ ...f, reason: e.target.value as RoomBlock["reason"] }))}>
              <option value="MAINTENANCE">Maintenance</option>
              <option value="OUT_OF_SERVICE">Out of service</option>
              <option value="BLOCKED">Blocked (other)</option>
            </Select>
          </Field>
          <Field label="Notes">
            <Input value={form.notes} onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))} />
          </Field>
        </div>
        <Button loading={block.isPending} onClick={() => block.mutate()}>Block these dates</Button>

        <div className="border-t border-border pt-4">
          <p className="mb-2 text-sm font-semibold">Upcoming blocks</p>
          {detail.data?.blocks?.filter((b) => b.ends_on > today).length ? (
            <ul className="space-y-2 text-sm">
              {detail.data.blocks.filter((b) => b.ends_on > today).map((b) => (
                <li key={b.id} className="flex items-center justify-between gap-2">
                  <span>
                    {formatStayDate(b.starts_on)} → {formatStayDate(b.ends_on)} · {b.reason.replace(/_/g, " ").toLowerCase()}
                    {b.notes ? ` · ${b.notes}` : ""}
                  </span>
                  <Button variant="ghost" size="sm" onClick={() => unblock.mutate(b.id)} aria-label="Remove block">
                    <Trash2 className="size-4" aria-hidden="true" />
                  </Button>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-muted">None.</p>
          )}
        </div>
      </div>
    </Dialog>
  );
}

// -------------------------------------------------------------- Room types

function RoomTypesList() {
  const { can } = useSession();
  const types = useQuery({ queryKey: hotelKeys.roomTypes, queryFn: hotelApi.roomTypes });
  const [editing, setEditing] = useState<RoomType | "new" | null>(null);

  return (
    <Card>
      <CardHeader
        title="Room types & prices"
        description="Nightly rates here are what new bookings are charged. Existing bookings keep their price."
        actions={can("rooms.create") && <Button onClick={() => setEditing("new")}><Plus className="size-4" aria-hidden="true" /> New room type</Button>}
      />
      {types.isPending ? (
        <LoadingState />
      ) : types.isError ? (
        <ErrorState error={types.error} onRetry={() => types.refetch()} />
      ) : types.data.length === 0 ? (
        <EmptyState title="No room types yet">Create Standard, Deluxe, Suite… then add rooms to each.</EmptyState>
      ) : (
        <ul className="grid gap-4 p-4 md:grid-cols-2 xl:grid-cols-3">
          {types.data.map((t) => (
            <li key={t.id} className={cn("overflow-hidden rounded-xl border border-border", !t.is_active && "opacity-60")}>
              <div className="h-36"><RoomImage url={t.cover_image_url} alt={t.name} /></div>
              <div className="space-y-1 p-4 text-sm">
                <div className="flex items-center justify-between gap-2">
                  <p className="text-base font-semibold">{t.name}</p>
                  {!t.is_active && <Badge>Hidden</Badge>}
                </div>
                <p><strong>{formatNaira(t.base_rate)}</strong> / night · {t.rooms_count ?? 0} rooms · sleeps {t.max_occupancy}</p>
                <p className="text-xs text-muted">{t.amenities?.map((a) => a.name).join(" · ")}</p>
                {can("rooms.update") && (
                  <Button variant="outline" size="sm" className="mt-2" onClick={() => setEditing(t)}>
                    <Pencil className="size-4" aria-hidden="true" /> Edit
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
      {editing && <RoomTypeDialog type={editing === "new" ? null : editing} onClose={() => setEditing(null)} />}
    </Card>
  );
}

function RoomTypeDialog({ type, onClose }: { type: RoomType | null; onClose: () => void }) {
  const queryClient = useQueryClient();
  const amenities = useQuery({ queryKey: hotelKeys.amenities, queryFn: hotelApi.amenities });
  const [current, setCurrent] = useState<RoomType | null>(type);
  const [form, setForm] = useState({
    name: type?.name ?? "",
    base_rate: type?.base_rate ?? "",
    max_adults: type?.max_adults ?? 2,
    max_children: type?.max_children ?? 0,
    max_occupancy: type?.max_occupancy ?? 2,
    bed_type: type?.bed_type ?? "",
    size_sqm: type?.size_sqm ? String(type.size_sqm) : "",
    short_description: type?.short_description ?? "",
    description: type?.description ?? "",
    is_active: type?.is_active ?? true,
    amenity_ids: type?.amenities?.map((a) => a.id) ?? [],
  });
  const [confirmDelete, setConfirmDelete] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  const refresh = () => queryClient.invalidateQueries({ queryKey: ["hotel"] });
  const payload = () => ({ ...form, size_sqm: form.size_sqm ? Number(form.size_sqm) : null, bed_type: form.bed_type || null });
  const save = useMutation({
    mutationFn: () => (current ? hotelApi.updateRoomType(current.id, payload()) : hotelApi.createRoomType(payload())),
    onSuccess: async (res) => {
      setCurrent(res.data);
      await refresh();
    },
  });
  const upload = useMutation({
    mutationFn: (file: File) => hotelApi.uploadRoomTypeImage(current!.id, file),
    onSuccess: async (res) => { setCurrent(res.data); await refresh(); },
  });
  const removeImage = useMutation({
    mutationFn: (imageId: number) => hotelApi.deleteRoomTypeImage(current!.id, imageId),
    onSuccess: async (res) => { setCurrent(res.data); await refresh(); },
  });
  const remove = useMutation({
    mutationFn: () => hotelApi.deleteRoomType(current!.id),
    onSuccess: async () => { await refresh(); onClose(); },
    onError: () => setConfirmDelete(false),
  });

  const err = isApiError(save.error) ? save.error : null;
  const set = <K extends keyof typeof form>(k: K, v: (typeof form)[K]) => setForm((f) => ({ ...f, [k]: v }));

  return (
    <Dialog
      open
      onClose={onClose}
      title={current ? `Edit ${current.name}` : "New room type"}
      className="max-w-2xl"
      footer={
        <>
          {current && (
            <Button variant="ghost" className="mr-auto text-danger" onClick={() => setConfirmDelete(true)}>Delete</Button>
          )}
          <Button variant="ghost" onClick={onClose}>Close</Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>{current ? "Save changes" : "Create"}</Button>
        </>
      }
    >
      <div className="space-y-4">
        {save.isSuccess && <Alert tone="success">{save.data.message}</Alert>}
        {(save.isError && !err?.isValidation) && <Alert tone="danger">{errorMessage(save.error)}</Alert>}
        {remove.isError && <Alert tone="danger">{errorMessage(remove.error)}</Alert>}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Name" required error={err?.field("name")}>
            <Input value={form.name} onChange={(e) => set("name", e.target.value)} />
          </Field>
          <Field label="Nightly rate (₦)" required error={err?.field("base_rate")} hint="Applies to new bookings only.">
            <Input inputMode="decimal" value={form.base_rate} onChange={(e) => set("base_rate", e.target.value.replace(/[^\d.]/g, ""))} />
          </Field>
          <Field label="Max adults" error={err?.field("max_adults")}>
            <Input type="number" min={1} max={20} value={form.max_adults} onChange={(e) => set("max_adults", Number(e.target.value))} />
          </Field>
          <Field label="Max guests in total" error={err?.field("max_occupancy")}>
            <Input type="number" min={1} max={20} value={form.max_occupancy} onChange={(e) => set("max_occupancy", Number(e.target.value))} />
          </Field>
          <Field label="Bed">
            <Input value={form.bed_type} onChange={(e) => set("bed_type", e.target.value)} placeholder="King" />
          </Field>
          <Field label="Size (m²)">
            <Input inputMode="numeric" value={form.size_sqm} onChange={(e) => set("size_sqm", e.target.value.replace(/\D/g, ""))} />
          </Field>
          <Field label="Short description" className="sm:col-span-2">
            <Input value={form.short_description} onChange={(e) => set("short_description", e.target.value)} maxLength={255} />
          </Field>
          <Field label="Full description" className="sm:col-span-2">
            <textarea rows={3} className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm" value={form.description} onChange={(e) => set("description", e.target.value)} />
          </Field>
        </div>
        <Checkbox label="Show on the website and allow bookings" checked={form.is_active} onChange={(e) => set("is_active", e.target.checked)} />
        <fieldset>
          <legend className="mb-2 text-sm font-semibold">Amenities</legend>
          <div className="grid gap-1 sm:grid-cols-3">
            {amenities.data?.map((a) => (
              <Checkbox
                key={a.id}
                label={a.name}
                checked={form.amenity_ids.includes(a.id)}
                onChange={(e) => set("amenity_ids", e.target.checked ? [...form.amenity_ids, a.id] : form.amenity_ids.filter((x) => x !== a.id))}
              />
            ))}
          </div>
        </fieldset>
        <section className="border-t border-border pt-4">
          <div className="mb-2 flex items-center justify-between">
            <h3 className="text-sm font-semibold">Photos</h3>
            {current && (
              <>
                <input
                  ref={fileRef}
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  className="sr-only"
                  aria-label="Upload photo"
                  onChange={(e) => { const f = e.target.files?.[0]; if (f) upload.mutate(f); e.target.value = ""; }}
                />
                <Button variant="outline" size="sm" loading={upload.isPending} onClick={() => fileRef.current?.click()}>
                  <ImagePlus className="size-4" aria-hidden="true" /> Upload photo
                </Button>
              </>
            )}
          </div>
          {!current ? (
            <p className="text-sm text-muted">Create the room type first, then add photos.</p>
          ) : (
            <>
              {upload.isError && <Alert tone="danger" className="mb-2">{errorMessage(upload.error)}</Alert>}
              <p className="mb-2 text-xs text-muted">JPG, PNG or WebP, at least 600×400, up to 5 MB. The first photo is the cover.</p>
              {current.images?.length ? (
                <ul className="grid grid-cols-3 gap-2">
                  {current.images.map((img) => (
                    <li key={img.id} className="group relative aspect-[4/3] overflow-hidden rounded-lg">
                      <RoomImage url={img.url} alt={img.alt ?? current.name} />
                      <button
                        type="button"
                        onClick={() => removeImage.mutate(img.id)}
                        aria-label="Remove photo"
                        className="absolute right-1 top-1 rounded-md bg-black/60 p-1.5 text-white"
                      >
                        <Trash2 className="size-4" aria-hidden="true" />
                      </button>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-sm text-muted">No photos yet.</p>
              )}
            </>
          )}
        </section>
      </div>
      <ConfirmDialog
        open={confirmDelete}
        onClose={() => setConfirmDelete(false)}
        onConfirm={() => remove.mutate()}
        loading={remove.isPending}
        title={`Delete ${current?.name}?`}
        confirmLabel="Delete"
      >
        Only room types without rooms can be deleted. To stop selling it, untick “Show on the website” instead.
      </ConfirmDialog>
    </Dialog>
  );
}
