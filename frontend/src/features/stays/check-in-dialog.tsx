"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { hotelKeys } from "@/features/hotel/api";
import { errorMessage, isApiError } from "@/lib/api/errors";
import type { CheckInOptions, Reservation } from "@/lib/api/types";
import { ID_TYPES, stayApi, stayKeys } from "./api";

export function CheckInDialog({ r, onClose }: { r: Reservation; onClose: () => void }) {
  const options = useQuery({ queryKey: stayKeys.checkIn(r.id), queryFn: () => stayApi.checkInOptions(r.id) });

  return (
    <Dialog open onClose={onClose} title={`Check in ${r.guest?.full_name ?? r.number}`} description={`${r.number} · ${r.nights} nights`}>
      {options.isPending ? (
        <LoadingState />
      ) : options.isError ? (
        <ErrorState error={options.error} onRetry={() => options.refetch()} />
      ) : (
        <Form key={JSON.stringify(options.data.rooms.map((x) => x.current?.id))} r={r} o={options.data} onClose={onClose} />
      )}
    </Dialog>
  );
}

function Form({ r, o, onClose }: { r: Reservation; o: CheckInOptions; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [rooms, setRooms] = useState<Record<number, number>>(() =>
    Object.fromEntries(o.rooms.map((l) => [l.reservation_room_id, l.current?.ready ? l.current.id : (l.alternatives[0]?.id ?? l.current?.id ?? 0)])),
  );
  const [id, setId] = useState({ type: "NATIONAL_ID", number: "" });
  const [notes, setNotes] = useState("");
  const needsId = o.id_required && !o.id_on_file;

  const checkIn = useMutation({
    mutationFn: () =>
      stayApi.checkIn(r.id, {
        rooms: Object.entries(rooms).map(([line, room]) => ({ reservation_room_id: Number(line), room_id: room })),
        ...(id.number.trim() ? { id_type: id.type, id_number: id.number.trim() } : {}),
        notes: notes.trim() || undefined,
      }),
    onSuccess: async (res) => {
      queryClient.setQueryData(hotelKeys.reservation(r.id), res.data);
      await queryClient.invalidateQueries({ queryKey: ["hotel"] });
      await queryClient.invalidateQueries({ queryKey: ["stays"] });
      onClose();
    },
  });
  const err = isApiError(checkIn.error) ? checkIn.error : null;

  if (!o.can_check_in) {
    return (
      <div className="space-y-3">
        {o.blockers.map((b) => <Alert key={b} tone="warning">{b}</Alert>)}
        <div className="flex justify-end"><Button variant="ghost" onClick={onClose}>Close</Button></div>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {checkIn.isError && !err?.isValidation && <Alert tone="danger">{errorMessage(checkIn.error)}</Alert>}

      {o.rooms.map((line) => {
        const options = [
          ...(line.current ? [{ id: line.current.id, label: `${line.current.number} (booked${line.current.ready ? "" : ` - ${line.current.status.toLowerCase()}`})`, disabled: !line.current.ready }] : []),
          ...line.alternatives.map((a) => ({ id: a.id, label: `${a.number}${a.same_type ? "" : ` - ${a.room_type}`}`, disabled: false })),
        ];
        return (
          <Field key={line.reservation_room_id} label={`${line.room_type.name} · ${line.adults} adult${line.adults === 1 ? "" : "s"}${line.children ? `, ${line.children} child` : ""}`}>
            <Select value={rooms[line.reservation_room_id]} onChange={(e) => setRooms((x) => ({ ...x, [line.reservation_room_id]: Number(e.target.value) }))}>
              {options.map((opt) => (<option key={opt.id} value={opt.id} disabled={opt.disabled}>Room {opt.label}</option>))}
            </Select>
          </Field>
        );
      })}

      {o.id_on_file ? (
        <Alert tone="success">ID document on file for this guest.</Alert>
      ) : (
        <div className="grid gap-3 sm:grid-cols-[180px_1fr]">
          <Field label="ID type" error={err?.field("id_type")}>
            <Select value={id.type} onChange={(e) => setId((x) => ({ ...x, type: e.target.value }))}>
              {ID_TYPES.map((t) => (<option key={t.value} value={t.value}>{t.label}</option>))}
            </Select>
          </Field>
          <Field label="ID number" required={needsId} error={err?.field("id_number")} hint="Stored encrypted; only the last 4 characters are shown later.">
            <Input value={id.number} maxLength={50} onChange={(e) => setId((x) => ({ ...x, number: e.target.value }))} />
          </Field>
        </div>
      )}

      <Field label="Notes (optional)">
        <Input value={notes} maxLength={500} onChange={(e) => setNotes(e.target.value)} placeholder="e.g. 2 key cards issued" />
      </Field>

      <div className="flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose}>Cancel</Button>
        <Button loading={checkIn.isPending} disabled={needsId && !id.number.trim()} onClick={() => checkIn.mutate()}>Check in</Button>
      </div>
    </div>
  );
}
