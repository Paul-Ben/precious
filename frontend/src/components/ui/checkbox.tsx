import { forwardRef, type InputHTMLAttributes, type ReactNode } from "react";
import { cn } from "@/lib/utils";

interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, "type"> {
  label: ReactNode;
  description?: ReactNode;
}

export const Checkbox = forwardRef<HTMLInputElement, CheckboxProps>(function Checkbox(
  { label, description, className, ...props },
  ref,
) {
  return (
    <label className={cn("flex cursor-pointer items-start gap-3 rounded-lg p-2 hover:bg-surface-muted", className)}>
      <input
        ref={ref}
        type="checkbox"
        className="mt-0.5 size-4 shrink-0 rounded border-border accent-[var(--brand)] disabled:cursor-not-allowed"
        {...props}
      />
      <span className="text-sm">
        <span className="font-medium text-foreground">{label}</span>
        {description && <span className="block text-xs text-muted">{description}</span>}
      </span>
    </label>
  );
});
