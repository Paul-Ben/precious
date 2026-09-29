"use client";

import { Checkbox } from "@/components/ui/checkbox";
import type { PermissionGroup } from "@/lib/api/types";
import { groupLabel } from "./api";

/**
 * Grouped permission checklist. `grantable` limits which boxes can be
 * toggled (users may only grant what they hold - also enforced by the API).
 */
export function PermissionMatrix({
  groups,
  value,
  onChange,
  disabled = false,
  grantable,
}: {
  groups: PermissionGroup[];
  value: string[];
  onChange: (next: string[]) => void;
  disabled?: boolean;
  grantable?: (permission: string) => boolean;
}) {
  const selected = new Set(value);

  const toggle = (name: string, on: boolean) => {
    const next = new Set(selected);
    if (on) next.add(name);
    else next.delete(name);
    onChange([...next].sort());
  };

  const toggleGroup = (group: PermissionGroup, on: boolean) => {
    const next = new Set(selected);
    for (const p of group.permissions) {
      if (grantable && !grantable(p.name)) continue;
      if (on) next.add(p.name);
      else next.delete(p.name);
    }
    onChange([...next].sort());
  };

  return (
    <div className="grid gap-4 md:grid-cols-2">
      {groups.map((group) => {
        const count = group.permissions.filter((p) => selected.has(p.name)).length;
        const all = count === group.permissions.length;

        return (
          <fieldset key={group.group} className="rounded-lg border border-border">
            <legend className="sr-only">{groupLabel(group.group)}</legend>
            <div className="flex items-center justify-between border-b border-border px-3 py-2">
              <span className="text-sm font-semibold">{groupLabel(group.group)}</span>
              <div className="flex items-center gap-3">
                <span className="text-xs text-muted">
                  {count}/{group.permissions.length}
                </span>
                {!disabled && (
                  <button
                    type="button"
                    className="text-xs font-medium underline underline-offset-4"
                    onClick={() => toggleGroup(group, !all)}
                  >
                    {all ? "Clear" : "Select all"}
                  </button>
                )}
              </div>
            </div>
            <div className="p-1">
              {group.permissions.map((p) => (
                <Checkbox
                  key={p.name}
                  label={p.description}
                  description={<code className="font-mono">{p.name}</code>}
                  checked={selected.has(p.name)}
                  disabled={disabled || (grantable ? !grantable(p.name) : false)}
                  onChange={(e) => toggle(p.name, e.target.checked)}
                />
              ))}
            </div>
          </fieldset>
        );
      })}
    </div>
  );
}
