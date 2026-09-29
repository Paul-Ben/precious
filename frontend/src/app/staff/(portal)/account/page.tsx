import type { Metadata } from "next";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { PageHeader } from "@/components/ui/page-header";
import { ChangePasswordForm } from "@/features/auth/change-password-form";

export const metadata: Metadata = { title: "My account" };

export default function StaffAccountPage() {
  return (
    <>
      <PageHeader title="My account" />
      <Card className="max-w-xl">
        <CardHeader title="Change password" description="Changing your password signs out your other devices." />
        <CardBody>
          <ChangePasswordForm />
        </CardBody>
      </Card>
    </>
  );
}
