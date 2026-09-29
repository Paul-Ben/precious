import type { FieldValues, Path, UseFormSetError } from "react-hook-form";
import { isApiError } from "@/lib/api/errors";

/**
 * Copies Laravel validation errors onto react-hook-form fields.
 * Returns true when at least one field error was applied.
 */
export function applyServerErrors<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
  fields: readonly Path<T>[],
): boolean {
  if (!isApiError(error) || !error.isValidation) return false;

  let applied = false;
  for (const field of fields) {
    const message = error.field(field);
    if (message) {
      setError(field, { type: "server", message }, { shouldFocus: !applied });
      applied = true;
    }
  }
  return applied;
}
