"use client";

import { ArrowRight, CheckCircle2, Clock } from "lucide-react";
import Link from "next/link";
import { Badge } from "@/components/ui/badge";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { PageHeader } from "@/components/ui/page-header";
import { staffNavigation } from "./navigation";
import { useSession } from "./session-context";

export function StaffDashboard() {
  const { user, canAny } = useSession();
  const shortcuts = staffNavigation
    .flatMap((s) => s.items)
    .filter((i) => i.href !== "/staff/dashboard" && (i.permissions.length === 0 || canAny(i.permissions)));

  return (
    <>
      <PageHeader
        title={`Good day, ${user.name.split(" ")[0]}`}
        description="Operational dashboards (occupancy, revenue, bar orders) arrive with their modules."
      />

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader title="Your modules" description="Only what your roles allow is shown." />
          <CardBody>
            {shortcuts.length === 0 ? (
              <p className="text-sm text-muted">No modules are available to your roles yet.</p>
            ) : (
              <ul className="grid gap-3 sm:grid-cols-2">
                {shortcuts.map((item) => {
                  const Icon = item.icon;
                  const content = (
                    <>
                      <Icon className="size-5 text-accent" aria-hidden="true" />
                      <span className="flex-1 font-medium">{item.label}</span>
                      {item.soon ? (
                        <Badge>
                          <Clock className="size-3" aria-hidden="true" /> Soon
                        </Badge>
                      ) : (
                        <ArrowRight className="size-4 text-muted" aria-hidden="true" />
                      )}
                    </>
                  );
                  return (
                    <li key={item.href}>
                      {item.soon ? (
                        <div className="flex items-center gap-3 rounded-lg border border-dashed border-border p-4 text-muted">
                          {content}
                        </div>
                      ) : (
                        <Link
                          href={item.href}
                          className="flex items-center gap-3 rounded-lg border border-border p-4 transition hover:border-accent hover:bg-surface-muted"
                        >
                          {content}
                        </Link>
                      )}
                    </li>
                  );
                })}
              </ul>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Your access" />
          <CardBody className="space-y-4 text-sm">
            <div>
              <p className="mb-2 text-muted">Roles</p>
              <div className="flex flex-wrap gap-2">
                {user.roles.map((role) => (
                  <Badge key={role} tone="brand">
                    {role}
                  </Badge>
                ))}
              </div>
            </div>
            <div className="flex items-center gap-2">
              <CheckCircle2 className="size-4 text-success" aria-hidden="true" />
              {user.two_factor_required ? "Two-factor sign-in is on" : "Password sign-in"}
            </div>
            <p className="text-muted">{user.is_super_admin ? "Unrestricted access" : `${user.permissions?.length ?? 0} permissions`}</p>
          </CardBody>
        </Card>
      </div>
    </>
  );
}
