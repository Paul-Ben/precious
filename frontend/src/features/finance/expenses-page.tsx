"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { FileText, Paperclip, Plus, Search, Tags } from "lucide-react";
import { useDeferredValue, useRef, useState } from "react";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/page-header";
import { Pagination } from "@/components/ui/pagination";
import { Select } from "@/components/ui/select";
import { EmptyState, ErrorState, LoadingState } from "@/components/ui/states";
import { RequirePermission } from "@/features/staff/require-permission";
import { useSession } from "@/features/staff/session-context";
import { errorMessage, isApiError } from "@/lib/api/errors";
import { formatStayDate, todayInHotel } from "@/lib/dates";
import { formatNaira } from "@/lib/money";
import { cn, formatDateTime } from "@/lib/utils";
import {
  EXPENSE_METHODS,
  EXPENSE_STATUS,
  type Expense,
  type ExpenseFilters,
  type ExpenseInput,
  type ExpenseMethod,
  type ExpenseStatus,
  financeApi,
  financeKeys,
  parseAmount,
  toKobo,
} from "./api";
import { RangePicker, type RangeState, initialRange } from "./range-picker";

export function ExpensesPage() {
  return (
    <RequirePermission permission={["finance.expenses", "finance.view", "finance.expenses.approve"]}>
      <Inner />
    </RequirePermission>
  );
}

function Inner() {
  const { can, canAny } = useSession();
  const [range, setRange] = useState<RangeState>(() => initialRange("month"));
  const [filters, setFilters] = useState<ExpenseFilters>({ status: "", category_id: "", method: "", search: "", page: 1 });
  const search = useDeferredValue(filters.search);
  const query: ExpenseFilters = { ...filters, search, from: range.from, to: range.to };
  const list = useQuery({ queryKey: financeKeys.expenses(query), queryFn: ({ signal }) => financeApi.expenses(query, signal), placeholderData: keepPreviousData });
  // GET expense-categories needs finance.view or finance.expenses (not finance.expenses.approve).
  const categories = useQuery({ queryKey: financeKeys.categories, queryFn: financeApi.categories, enabled: canAny(["finance.view", "finance.expenses", "finance.expenses.approve"]) });
  const [creating, setCreating] = useState(false);
  // Snapshot of the clicked row: the dialog stays open (and refreshes itself) even if the
  // expense drops out of the filtered list after an approve/void or a page change.
  const [opened, setOpened] = useState<Expense | null>(null);
  const [managing, setManaging] = useState(false);
  const update = (patch: Partial<ExpenseFilters>) => setFilters((f) => ({ ...f, page: 1, ...patch }));
  const meta = list.data?.meta;

  return (
    <>
      <PageHeader
        title="Expenses"
        description={
          meta
            ? `Expenses above ${formatNaira(meta.approval_limit)} wait for a manager's approval. Approved expenses can only be voided, not edited.`
            : "Money paid out by the hotel and bar."
        }
        actions={
          <>
            {can("finance.expenses.approve") && (
              <Button variant="outline" onClick={() => setManaging(true)}>
                <Tags className="size-4" aria-hidden /> Categories
              </Button>
            )}
            {can("finance.expenses") && (
              <Button onClick={() => setCreating(true)}>
                <Plus className="size-4" aria-hidden /> Record expense
              </Button>
            )}
          </>
        }
      />

      <div className="mb-4">
        <RangePicker value={range} onChange={(r) => { setRange(r); update({}); }} />
      </div>

      {meta && meta.pending_count > 0 && filters.status !== "PENDING" && (
        <Alert tone="warning" className="mb-4">
          {meta.pending_count} expense{meta.pending_count === 1 ? "" : "s"} waiting for approval.{" "}
          <button type="button" className="font-medium underline" onClick={() => update({ status: "PENDING" })}>Show them</button>
        </Alert>
      )}

      <Card>
        <div className="grid gap-3 border-b border-border p-4 sm:grid-cols-[1fr_auto_auto_auto]">
          <label className="relative">
            <span className="sr-only">Search expenses</span>
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden />
            <Input className="pl-9" placeholder="Description, payee, number or reference" value={filters.search} onChange={(e) => update({ search: e.target.value })} />
          </label>
          <Select aria-label="Status" value={filters.status} onChange={(e) => update({ status: e.target.value as ExpenseStatus | "" })}>
            <option value="">Any status</option>
            {(Object.keys(EXPENSE_STATUS) as ExpenseStatus[]).map((s) => (
              <option key={s} value={s}>{EXPENSE_STATUS[s].label}</option>
            ))}
          </Select>
          <Select aria-label="Category" value={filters.category_id} onChange={(e) => update({ category_id: e.target.value ? Number(e.target.value) : "" })}>
            <option value="">All categories</option>
            {categories.data?.map((c) => (
              <option key={c.id} value={c.id}>{c.name}</option>
            ))}
          </Select>
          <Select aria-label="Paid by" value={filters.method} onChange={(e) => update({ method: e.target.value as ExpenseMethod | "" })}>
            <option value="">Any method</option>
            {EXPENSE_METHODS.map((m) => (
              <option key={m.value} value={m.value}>{m.label}</option>
            ))}
          </Select>
        </div>

        {list.isPending ? (
          <LoadingState />
        ) : list.isError ? (
          <ErrorState error={list.error} onRetry={() => list.refetch()} />
        ) : list.data.items.length === 0 ? (
          <EmptyState title="No expenses">Nothing matches this period and filter.</EmptyState>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[760px] text-left text-sm">
                <thead className="border-b border-border text-xs uppercase tracking-wider text-muted">
                  <tr>
                    <th scope="col" className="px-5 py-2 font-medium">Date</th>
                    <th scope="col" className="px-3 py-2 font-medium">Expense</th>
                    <th scope="col" className="px-3 py-2 font-medium">Category</th>
                    <th scope="col" className="px-3 py-2 font-medium">Paid by</th>
                    <th scope="col" className="px-3 py-2 text-right font-medium">Amount</th>
                    <th scope="col" className="px-5 py-2 font-medium">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {list.data.items.map((e) => (
                    <tr key={e.id} className="cursor-pointer hover:bg-surface-muted/60" onClick={() => setOpened(e)}>
                      <td className="px-5 py-2 whitespace-nowrap">{formatStayDate(e.expense_date)}</td>
                      <td className="px-3 py-2">
                        <button
                          type="button"
                          className="text-left font-medium hover:underline"
                          onClick={(ev) => {
                            ev.stopPropagation();
                            setOpened(e);
                          }}
                        >
                          {e.description}
                        </button>
                        <span className="block text-xs text-muted">
                          {e.number}
                          {e.payee && ` · ${e.payee}`}
                          {e.has_receipt && <Paperclip className="ml-1 inline size-3" aria-label="Receipt attached" />}
                        </span>
                      </td>
                      <td className="px-3 py-2">{e.category.name}</td>
                      <td className="px-3 py-2">{e.method_label}</td>
                      <td className={cn("px-3 py-2 text-right font-medium tabular-nums", (e.status === "VOID" || e.status === "REJECTED") && "text-muted line-through")}>
                        {formatNaira(e.amount)}
                      </td>
                      <td className="px-5 py-2">
                        <Badge tone={EXPENSE_STATUS[e.status].tone}>{EXPENSE_STATUS[e.status].label}</Badge>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-3 text-sm">
              <span>
                Approved in this view: <strong className="tabular-nums">{formatNaira(meta!.approved_total)}</strong> ({meta!.approved_count})
              </span>
            </div>
            <Pagination meta={list.data.meta} onPage={(page) => setFilters((f) => ({ ...f, page }))} />
          </>
        )}
      </Card>

      {creating && <ExpenseForm approvalLimit={meta?.approval_limit} onClose={() => setCreating(false)} />}
      {opened && <ExpenseDetail key={opened.id} initial={opened} approvalLimit={meta?.approval_limit} onClose={() => setOpened(null)} />}
      {managing && <CategoriesDialog onClose={() => setManaging(false)} />}
    </>
  );
}

// ---------------------------------------------------------------- record / edit

function ExpenseForm({ expense, approvalLimit, onClose }: { expense?: Expense; approvalLimit?: string; onClose: () => void }) {
  const queryClient = useQueryClient();
  const categories = useQuery({ queryKey: financeKeys.categories, queryFn: financeApi.categories });
  const today = todayInHotel();
  const [form, setForm] = useState({
    category_id: expense ? String(expense.category.id) : "",
    expense_date: expense?.expense_date ?? today,
    description: expense?.description ?? "",
    payee: expense?.payee ?? "",
    amount: expense?.amount ?? "",
    method: (expense?.method ?? "CASH") as ExpenseMethod,
    reference: expense?.reference ?? "",
  });
  const [receipt, setReceipt] = useState<File | null>(null);
  const [amountError, setAmountError] = useState<string | null>(null);
  const set = (k: keyof typeof form) => (v: string) => setForm((f) => ({ ...f, [k]: v }));

  const save = useMutation({
    mutationFn: () => {
      const amount = parseAmount(form.amount);
      if (!amount || toKobo(amount) <= 0) {
        setAmountError("Enter an amount, e.g. 12500 or 12,500.50.");
        throw new Error("Invalid amount");
      }
      setAmountError(null);
      const body: ExpenseInput = {
        category_id: Number(form.category_id),
        expense_date: form.expense_date,
        description: form.description.trim(),
        payee: form.payee.trim() || null,
        amount,
        method: form.method,
        reference: form.reference.trim() || null,
      };
      if (!expense) return financeApi.createExpense(body, receipt);
      // Send only what changed: the backend re-validates every field it receives, so an
      // unchanged category that has since been hidden would otherwise block the edit.
      const before: ExpenseInput = {
        category_id: expense.category.id,
        expense_date: expense.expense_date,
        description: expense.description,
        payee: expense.payee,
        amount: parseAmount(expense.amount) ?? expense.amount,
        method: expense.method,
        reference: expense.reference,
      };
      const changed = Object.fromEntries(Object.entries(body).filter(([k, v]) => before[k as keyof ExpenseInput] !== v)) as Partial<ExpenseInput>;
      return financeApi.updateExpense(expense.id, changed);
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ["finance"] });
      onClose();
    },
  });
  const fieldError = (name: string) => (isApiError(save.error) ? save.error.errors?.[name]?.[0] : undefined);
  const parsedAmount = parseAmount(form.amount);
  const needsApproval = !!approvalLimit && parsedAmount !== null && toKobo(parsedAmount) > toKobo(approvalLimit);
  const activeCategories = (categories.data ?? []).filter((c) => c.is_active || String(c.id) === form.category_id);

  return (
    <Dialog open onClose={onClose} title={expense ? `Edit ${expense.number}` : "Record expense"} description="Cash expenses on a closed day can't be added or changed.">
      <form
        className="grid gap-4 sm:grid-cols-2"
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
      >
        <Field label="Category" required error={fieldError("category_id")}>
          <Select value={form.category_id} onChange={(e) => set("category_id")(e.target.value)}>
            <option value="" disabled>Choose…</option>
            {activeCategories.map((c) => (
              <option key={c.id} value={c.id}>{c.name}</option>
            ))}
          </Select>
        </Field>
        <Field label="Date" required error={fieldError("expense_date")}>
          <Input type="date" value={form.expense_date} max={today} onChange={(e) => set("expense_date")(e.target.value)} />
        </Field>
        <Field label="Description" required error={fieldError("description")} className="sm:col-span-2">
          <Input value={form.description} minLength={3} maxLength={255} placeholder="e.g. Diesel for generator (200 litres)" onChange={(e) => set("description")(e.target.value)} />
        </Field>
        <Field
          label="Amount (₦)"
          required
          error={amountError ?? fieldError("amount")}
          hint={needsApproval ? `Above ${formatNaira(approvalLimit!)}: it will wait for approval by someone else.` : undefined}
        >
          <Input inputMode="decimal" value={form.amount} placeholder="0.00" onChange={(e) => set("amount")(e.target.value)} />
        </Field>
        <Field label="Paid by" required error={fieldError("method")}>
          <Select value={form.method} onChange={(e) => set("method")(e.target.value)}>
            {EXPENSE_METHODS.map((m) => (
              <option key={m.value} value={m.value}>{m.label}</option>
            ))}
          </Select>
        </Field>
        <Field label="Supplier / payee" error={fieldError("payee")}>
          <Input value={form.payee} maxLength={120} onChange={(e) => set("payee")(e.target.value)} />
        </Field>
        <Field label="Reference" hint="Invoice, transfer or cheque number." error={fieldError("reference")}>
          <Input value={form.reference} maxLength={120} onChange={(e) => set("reference")(e.target.value)} />
        </Field>
        {!expense && (
          <Field label="Receipt (photo or PDF, optional)" error={fieldError("receipt")} className="sm:col-span-2">
            <Input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={(e) => setReceipt(e.target.files?.[0] ?? null)} />
          </Field>
        )}
        {save.isError && !amountError && !(isApiError(save.error) && save.error.isValidation) && (
          <Alert tone="danger" className="sm:col-span-2">{errorMessage(save.error)}</Alert>
        )}
        <div className="flex justify-end gap-2 sm:col-span-2">
          <Button variant="ghost" onClick={onClose}>Cancel</Button>
          <Button type="submit" loading={save.isPending} disabled={!form.category_id || form.description.trim().length < 3 || !form.amount}>
            {expense ? "Save" : "Record"}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

// ---------------------------------------------------------------- detail

function ExpenseDetail({ initial, approvalLimit, onClose }: { initial: Expense; approvalLimit?: string; onClose: () => void }) {
  const { can, user } = useSession();
  const queryClient = useQueryClient();
  const detail = useQuery({
    queryKey: financeKeys.expense(initial.id),
    queryFn: ({ signal }) => financeApi.expense(initial.id, signal),
    initialData: initial,
  });
  const e = detail.data;
  const [editing, setEditing] = useState(false);
  const [action, setAction] = useState<"reject" | "void" | null>(null);
  const [reason, setReason] = useState("");
  const fileRef = useRef<HTMLInputElement>(null);
  const refresh = (res?: { data: Expense }) => {
    if (res) queryClient.setQueryData(financeKeys.expense(res.data.id), res.data);
    return queryClient.invalidateQueries({ queryKey: ["finance"] });
  };

  const approve = useMutation({ mutationFn: () => financeApi.approve(e.id), onSuccess: refresh });
  const decide = useMutation({
    mutationFn: () => (action === "reject" ? financeApi.reject(e.id, reason.trim()) : financeApi.void(e.id, reason.trim())),
    onSuccess: async (res) => {
      setAction(null);
      setReason("");
      await refresh(res);
    },
  });
  const attach = useMutation({ mutationFn: (file: File) => financeApi.attachReceipt(e.id, file), onSuccess: refresh });
  const error = approve.error ?? decide.error ?? attach.error;

  if (editing) return <ExpenseForm expense={e} approvalLimit={approvalLimit} onClose={() => setEditing(false)} />;

  const isOwn = e.recorded_by?.id === user.id;
  const canApprove = e.status === "PENDING" && can("finance.expenses.approve") && !isOwn;

  return (
    <Dialog open onClose={onClose} title={e.description} description={`${e.number} · ${formatStayDate(e.expense_date, true)}`}>
      <div className="space-y-4">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-2xl font-bold tabular-nums">{formatNaira(e.amount)}</span>
          <Badge tone={EXPENSE_STATUS[e.status].tone}>{EXPENSE_STATUS[e.status].label}</Badge>
        </div>
        <dl className="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1.5 text-sm">
          <dt className="text-muted">Category</dt>
          <dd>{e.category.name}</dd>
          <dt className="text-muted">Paid by</dt>
          <dd>{e.method_label}{e.reference ? ` · ${e.reference}` : ""}</dd>
          <dt className="text-muted">Payee</dt>
          <dd>{e.payee ?? "—"}</dd>
          <dt className="text-muted">Recorded</dt>
          <dd>{e.recorded_by?.name ?? "—"} · {formatDateTime(e.created_at)}</dd>
          {e.decided_by && (
            <>
              <dt className="text-muted">{e.status === "REJECTED" ? "Rejected" : "Approved"}</dt>
              <dd>{e.decided_by} · {formatDateTime(e.decided_at)}{e.rejection_reason ? ` — ${e.rejection_reason}` : ""}</dd>
            </>
          )}
          {e.status === "VOID" && (
            <>
              <dt className="text-muted">Voided</dt>
              <dd>{e.voided_by} · {formatDateTime(e.voided_at)} — {e.void_reason}</dd>
            </>
          )}
          <dt className="text-muted">Receipt</dt>
          <dd>
            {e.has_receipt ? (
              <a href={financeApi.receiptUrl(e.id)} target="_blank" rel="noopener" className="inline-flex items-center gap-1 font-medium underline">
                <FileText className="size-4" aria-hidden /> {e.receipt_name ?? "View"}
              </a>
            ) : (
              "None"
            )}
            {can("finance.expenses") && (e.status === "PENDING" || e.status === "APPROVED") && (
              <>
                <input
                  ref={fileRef}
                  type="file"
                  accept="image/jpeg,image/png,image/webp,application/pdf"
                  className="sr-only"
                  aria-label="Receipt file"
                  onChange={(ev) => {
                    const f = ev.target.files?.[0];
                    ev.target.value = "";
                    if (f) attach.mutate(f);
                  }}
                />
                <button type="button" className="ml-2 text-xs underline" onClick={() => fileRef.current?.click()} disabled={attach.isPending}>
                  {attach.isPending ? "Uploading…" : e.has_receipt ? "Replace" : "Attach"}
                </button>
              </>
            )}
          </dd>
        </dl>

        {e.status === "PENDING" && isOwn && can("finance.expenses.approve") && (
          <p className="text-xs text-muted">You recorded this expense, so someone else must approve it.</p>
        )}
        {error && <Alert tone="danger">{errorMessage(error)}</Alert>}

        {action ? (
          <div className="space-y-3 rounded-lg border border-border p-3">
            <Field label={action === "reject" ? "Why is it rejected?" : "Why void it?"} required>
              <Input value={reason} minLength={3} maxLength={255} autoFocus onChange={(ev) => setReason(ev.target.value)} />
            </Field>
            <div className="flex justify-end gap-2">
              <Button
                variant="ghost"
                onClick={() => {
                  setAction(null);
                  setReason("");
                  decide.reset();
                }}
              >
                Back
              </Button>
              <Button variant="danger" loading={decide.isPending} disabled={reason.trim().length < 3} onClick={() => decide.mutate()}>
                {action === "reject" ? "Reject" : "Void expense"}
              </Button>
            </div>
          </div>
        ) : (
          <div className="flex flex-wrap justify-end gap-2">
            {can("finance.expenses") && (e.status === "PENDING" || e.status === "APPROVED") && (
              <Button variant="ghost" className="text-danger" onClick={() => setAction("void")}>Void</Button>
            )}
            {e.status === "PENDING" && can("finance.expenses") && <Button variant="outline" onClick={() => setEditing(true)}>Edit</Button>}
            {canApprove && (
              <>
                <Button variant="outline" onClick={() => setAction("reject")}>Reject</Button>
                <Button loading={approve.isPending} onClick={() => approve.mutate()}>Approve</Button>
              </>
            )}
          </div>
        )}
      </div>
    </Dialog>
  );
}

// ---------------------------------------------------------------- categories

function CategoriesDialog({ onClose }: { onClose: () => void }) {
  const queryClient = useQueryClient();
  const categories = useQuery({ queryKey: financeKeys.categories, queryFn: financeApi.categories });
  const [name, setName] = useState("");
  const refresh = () => queryClient.invalidateQueries({ queryKey: financeKeys.categories });
  const add = useMutation({
    mutationFn: () => financeApi.createCategory(name.trim()),
    onSuccess: async () => {
      setName("");
      await refresh();
    },
  });
  const toggle = useMutation({ mutationFn: (c: { id: number; is_active: boolean }) => financeApi.updateCategory(c.id, { is_active: !c.is_active }), onSuccess: refresh });

  return (
    <Dialog open onClose={onClose} title="Expense categories" description="Hidden categories stay on past expenses but can't be chosen for new ones.">
      <div className="space-y-3">
        <ul className="divide-y divide-border rounded-lg border border-border">
          {categories.data?.map((c) => (
            <li key={c.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
              <span className={cn(!c.is_active && "text-muted line-through")}>{c.name}</span>
              <Button size="sm" variant="ghost" onClick={() => toggle.mutate(c)} loading={toggle.isPending && toggle.variables?.id === c.id}>
                {c.is_active ? "Hide" : "Show"}
              </Button>
            </li>
          ))}
        </ul>
        <form
          className="flex gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            add.mutate();
          }}
        >
          <Input aria-label="New category" placeholder="New category" value={name} maxLength={80} onChange={(e) => setName(e.target.value)} />
          <Button type="submit" loading={add.isPending} disabled={!name.trim()}>Add</Button>
        </form>
        {(add.isError || toggle.isError) && <Alert tone="danger">{errorMessage(add.error ?? toggle.error)}</Alert>}
      </div>
    </Dialog>
  );
}
