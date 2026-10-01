"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Trash2 } from "lucide-react";
import { useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { ErrorState, LoadingState } from "@/components/ui/states";
import { errorMessage } from "@/lib/api/errors";
import { type ShiftTemplate, teamApi, teamKeys } from "./api";

/** P24: the standard shifts offered when adding to the rota. */
export function TemplatesDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const templates = useQuery({ queryKey: teamKeys.templates, queryFn: teamApi.templates, enabled: open });

  return (
    <Dialog open={open} onClose={onClose} title="Standard shifts" description="Offered when adding a shift. Changing one does not move shifts already on the rota.">
      <div className="space-y-3">
        {templates.isPending ? (
          <LoadingState />
        ) : templates.isError ? (
          <ErrorState error={templates.error} onRetry={() => templates.refetch()} />
        ) : (
          <>
            {templates.data?.map((t) => <Row key={t.id} template={t} />)}
            <Row />
          </>
        )}
      </div>
    </Dialog>
  );
}

function Row({ template }: { template?: ShiftTemplate }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({ name: template?.name ?? "", start_time: template?.start_time ?? "", end_time: template?.end_time ?? "" });
  const dirty = !template || form.name !== template.name || form.start_time !== template.start_time || form.end_time !== template.end_time;
  // Rota chips show template names, so refresh the shifts too.
  const refresh = () =>
    Promise.all([queryClient.invalidateQueries({ queryKey: teamKeys.templates }), queryClient.invalidateQueries({ queryKey: ["team", "shifts"] })]);

  const save = useMutation({
    mutationFn: () => (template ? teamApi.updateTemplate(template.id, form) : teamApi.createTemplate(form)),
    onSuccess: async () => {
      if (!template) setForm({ name: "", start_time: "", end_time: "" });
      await refresh();
    },
  });
  const remove = useMutation({ mutationFn: () => teamApi.deleteTemplate(template!.id), onSuccess: refresh });
  const overnight = form.start_time && form.end_time && form.end_time <= form.start_time;

  return (
    <form
      className="space-y-1"
      onSubmit={(e) => {
        e.preventDefault();
        save.mutate();
      }}
    >
      <div className="grid grid-cols-[1fr_1fr_auto] items-center gap-2 sm:grid-cols-[1fr_7rem_7rem_auto]">
        <Input className="col-span-3 sm:col-span-1" aria-label="Name" placeholder={template ? "Name" : "New shift name"} value={form.name} maxLength={60} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} />
        <Input aria-label="Starts" type="time" value={form.start_time} onChange={(e) => setForm((f) => ({ ...f, start_time: e.target.value }))} />
        <Input aria-label="Ends" type="time" value={form.end_time} onChange={(e) => setForm((f) => ({ ...f, end_time: e.target.value }))} />
        <div className="flex gap-1">
          <Button type="submit" size="sm" variant={template ? "outline" : "primary"} loading={save.isPending} disabled={!dirty || !form.name.trim() || !form.start_time || !form.end_time}>
            {template ? "Save" : "Add"}
          </Button>
          {template && (
            <Button size="sm" variant="ghost" aria-label={`Remove ${template.name}`} loading={remove.isPending} onClick={() => remove.mutate()}>
              <Trash2 className="size-4" aria-hidden />
            </Button>
          )}
        </div>
      </div>
      {overnight && <p className="text-xs text-muted">Ends the next day.</p>}
      {(save.isError || remove.isError) && <Alert tone="danger">{errorMessage(remove.isError ? remove.error : save.error)}</Alert>}
    </form>
  );
}
