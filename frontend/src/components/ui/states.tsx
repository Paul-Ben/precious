import { Inbox, Lock, RefreshCw, WifiOff, XCircle } from "lucide-react";
import type { ReactNode } from "react";
import { errorMessage, isApiError } from "@/lib/api/errors";
import { Button } from "./button";
import { Spinner } from "./spinner";

function StateBox({ icon, title, children, action }: { icon: ReactNode; title: ReactNode; children?: ReactNode; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 px-6 py-12 text-center">
      <div className="rounded-full bg-surface-muted p-3 text-muted">{icon}</div>
      <div className="space-y-1">
        <p className="font-medium text-foreground">{title}</p>
        {children && <div className="max-w-md text-sm text-muted">{children}</div>}
      </div>
      {action}
    </div>
  );
}

export function LoadingState({ label = "Loading…" }: { label?: string }) {
  return (
    <div className="flex justify-center px-6 py-12">
      <Spinner label={label} />
    </div>
  );
}

export function EmptyState({ title, children, action }: { title: ReactNode; children?: ReactNode; action?: ReactNode }) {
  return <StateBox icon={<Inbox className="size-6" aria-hidden="true" />} title={title} action={action}>{children}</StateBox>;
}

export function UnauthorizedState({ children }: { children?: ReactNode }) {
  return (
    <StateBox icon={<Lock className="size-6" aria-hidden="true" />} title="You don't have access to this">
      {children ?? "Ask an administrator if you need this permission."}
    </StateBox>
  );
}

/**
 * Renders the right state for any API error: forbidden, network failure or
 * generic error, with a retry button where it makes sense.
 */
export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  if (isApiError(error) && error.isForbidden) {
    return <UnauthorizedState />;
  }

  const network = isApiError(error) && error.isNetwork;
  const retry = onRetry && (
    <Button variant="outline" size="sm" onClick={onRetry}>
      <RefreshCw className="size-4" aria-hidden="true" /> Try again
    </Button>
  );

  return (
    <StateBox
      icon={network ? <WifiOff className="size-6" aria-hidden="true" /> : <XCircle className="size-6" aria-hidden="true" />}
      title={network ? "Connection problem" : "Something went wrong"}
      action={retry}
    >
      {errorMessage(error)}
    </StateBox>
  );
}
