"use client"

/**
 * Goods Receipt detail page.
 *
 * Sections:
 *  - Header: GRN number, status badge, PO reference, delivery note number
 *  - Line items table: description, qty received, qty accepted, qty rejected, status
 *  - "Assign Committee" section (Store_Manager, status=pending_inspection):
 *      multi-user select for committee members (min 2), submit button
 *  - "Submit Inspection" section (Committee_Member, status=under_inspection):
 *      per-item accept/reject toggle + optional notes, submit button
 *  - Read-only result view when status is accepted / partially_accepted / rejected
 *  - Loading skeleton, error state with retry
 *
 * Validates: Requirements 12.1, 12.2, 22.6
 */

import { use, useState } from "react"
import Link from "next/link"
import { ArrowLeft, RefreshCw, Users, ClipboardCheck } from "lucide-react"
import { motion } from "framer-motion"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Card } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { Separator } from "@/components/ui/separator"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import {
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableHead,
  TableCell,
} from "@/components/ui/table"
import { GRNStatusBadge } from "@/components/goods-receipts/GRNStatusBadge"
import {
  useGoodsReceipt,
  useAssignGRNCommittee,
  useSubmitInspectionResult,
} from "@/hooks/useGoodsReceipts"
import { useUsers } from "@/hooks/useUsers"
import { useAuthStore } from "@/store/authStore"
import type { GoodsReceiptItem } from "@/types/goodsReceipt"

// ─── Loading skeleton ─────────────────────────────────────────────────────────

function DetailSkeleton() {
  return (
    <div className="space-y-6">
      <Skeleton className="h-5 w-32" />
      <div className="flex items-start justify-between gap-4">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-7 w-32" />
      </div>
      <div className="grid gap-4 sm:grid-cols-3">
        {Array.from({ length: 3 }).map((_, i) => (
          <Skeleton key={i} className="h-20 rounded-xl" />
        ))}
      </div>
      <Skeleton className="h-48 rounded-xl" />
    </div>
  )
}

// ─── InfoCard helper ──────────────────────────────────────────────────────────

function InfoCard({ label, value }: { label: string; value: string }) {
  return (
    <Card className="p-4">
      <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
        {label}
      </p>
      <p className="mt-1 text-sm font-semibold leading-snug">{value}</p>
    </Card>
  )
}

// ─── GRN item status badge ────────────────────────────────────────────────────

function ItemStatusBadge({ status }: { status: string }) {
  const map: Record<string, { label: string; className: string }> = {
    pending:            { label: "Pending",            className: "bg-muted text-muted-foreground"         },
    accepted:           { label: "Accepted",           className: "bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300" },
    rejected:           { label: "Rejected",           className: "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300"         },
    partially_accepted: { label: "Partial",            className: "bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300" },
  }
  const cfg = map[status] ?? { label: status, className: "bg-muted text-muted-foreground" }
  return (
    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${cfg.className}`}>
      {cfg.label}
    </span>
  )
}

// ─── Framer Motion ────────────────────────────────────────────────────────────

const fadeIn = {
  hidden: { opacity: 0, y: 8 },
  visible: { opacity: 1, y: 0, transition: { duration: 0.25, ease: "easeOut" as const } },
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function GoodsReceiptDetailPage({
  params,
}: {
  params: Promise<{ id: string }>
}) {
  const { id } = use(params)
  const role = useAuthStore((s) => s.role)
  const currentUser = useAuthStore((s) => s.user)

  const { data, isLoading, isError, refetch } = useGoodsReceipt(id)
  const assignCommittee = useAssignGRNCommittee()
  const submitInspection = useSubmitInspectionResult()

  // Fetch committee candidate users
  const { data: usersData } = useUsers({ role: "Committee_Member", per_page: 200 })
  const committeeOptions = usersData?.data ?? []

  // ── Assign Committee state ─────────────────────────────────────────────────
  const [selectedCommitteeIds, setSelectedCommitteeIds] = useState<string[]>([])
  const [assignError, setAssignError] = useState<string | null>(null)

  // ── Inspection state ───────────────────────────────────────────────────────
  type ItemResult = { accepted: boolean; notes: string }
  const [itemResults, setItemResults] = useState<Record<string, ItemResult>>({})
  const [inspectionError, setInspectionError] = useState<string | null>(null)

  const grn = data?.data

  const isStoreManager = role === "Store_Manager" || role === "Tenant_Admin"
  const isCommitteeMember = role === "Committee_Member" || role === "Tenant_Admin"

  const canAssignCommittee =
    isStoreManager && grn?.status === "pending_inspection"

  const canSubmitInspection =
    isCommitteeMember && grn?.status === "under_inspection"

  const isReadOnly =
    grn?.status === "accepted" ||
    grn?.status === "partially_accepted" ||
    grn?.status === "rejected"

  // ── Toggle committee member selection ─────────────────────────────────────
  function toggleCommitteeMember(userId: string) {
    setSelectedCommitteeIds((prev) =>
      prev.includes(userId)
        ? prev.filter((id) => id !== userId)
        : [...prev, userId],
    )
  }

  // ── Handle assign committee submit ────────────────────────────────────────
  async function handleAssignCommittee(e: React.FormEvent) {
    e.preventDefault()
    setAssignError(null)
    if (selectedCommitteeIds.length < 2) {
      setAssignError("You must select at least 2 committee members.")
      return
    }
    try {
      await assignCommittee.mutateAsync({
        id,
        payload: { committee_user_ids: selectedCommitteeIds },
      })
      setSelectedCommitteeIds([])
    } catch (err) {
      setAssignError(
        err instanceof Error ? err.message : "Failed to assign committee. Please try again.",
      )
    }
  }

  // ── Initialise item results when GRN loads ────────────────────────────────
  function initItemResults(items: GoodsReceiptItem[]) {
    if (Object.keys(itemResults).length > 0) return
    const init: Record<string, ItemResult> = {}
    for (const item of items) {
      init[item.id] = { accepted: true, notes: "" }
    }
    setItemResults(init)
  }

  if (grn?.items && Object.keys(itemResults).length === 0) {
    initItemResults(grn.items)
  }

  // ── Handle inspection submit ───────────────────────────────────────────────
  async function handleSubmitInspection(e: React.FormEvent) {
    e.preventDefault()
    setInspectionError(null)
    if (!currentUser) {
      setInspectionError("Unable to determine current user. Please log in again.")
      return
    }

    const results = Object.entries(itemResults).map(([grn_item_id, r]) => ({
      grn_item_id,
      accepted: r.accepted,
      notes: r.notes || undefined,
    }))

    try {
      await submitInspection.mutateAsync({
        id,
        payload: {
          inspector_id: currentUser.id,
          results,
        },
      })
    } catch (err) {
      setInspectionError(
        err instanceof Error ? err.message : "Failed to submit inspection results. Please try again.",
      )
    }
  }

  // ── Loading / error states ─────────────────────────────────────────────────

  if (isLoading) return <DetailSkeleton />

  if (isError || !grn) {
    return (
      <div className="flex flex-col items-center gap-4 py-16">
        <p className="text-sm text-muted-foreground">Failed to load goods receipt.</p>
        <Button variant="outline" size="sm" onClick={() => refetch()}>
          <RefreshCw className="size-3.5" aria-hidden="true" />
          Retry
        </Button>
      </div>
    )
  }

  // ── Render ────────────────────────────────────────────────────────────────

  return (
    <motion.div
      className="space-y-6"
      initial="hidden"
      animate="visible"
      variants={fadeIn}
    >
      {/* Back link */}
      <Link
        href="/goods-receipts"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground transition-colors"
      >
        <ArrowLeft className="size-4" aria-hidden="true" />
        Back to Goods Receipts
      </Link>

      {/* Header */}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="space-y-1">
          <div className="flex flex-wrap items-center gap-3">
            <h1 className="text-2xl font-semibold font-mono tracking-tight">
              {grn.grn_number}
            </h1>
            <GRNStatusBadge status={grn.status} />
          </div>
          <p className="text-sm text-muted-foreground">
            PO:{" "}
            <span className="font-mono">
              {grn.purchase_order?.po_number ?? grn.purchase_order_id}
            </span>
            {grn.purchase_order?.supplier?.organization_name && (
              <> · {grn.purchase_order.supplier.organization_name}</>
            )}
          </p>
        </div>
        <Badge variant="outline" className="text-muted-foreground">
          DN: {grn.delivery_note_number}
        </Badge>
      </div>

      {/* Info cards */}
      <div className="grid gap-4 sm:grid-cols-3">
        <InfoCard label="Received At" value={new Date(grn.received_at).toLocaleString()} />
        <InfoCard label="Delivery Note" value={grn.delivery_note_number} />
        <InfoCard
          label="Committee Members"
          value={
            grn.committee_members && grn.committee_members.length > 0
              ? grn.committee_members.map((m) => m.name).join(", ")
              : "Not yet assigned"
          }
        />
      </div>

      <Separator />

      {/* Line items table */}
      <section aria-labelledby="grn-items-heading">
        <h2 id="grn-items-heading" className="mb-3 text-base font-semibold">
          Line Items
        </h2>
        <div className="rounded-xl border border-border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Description</TableHead>
                <TableHead className="text-right">Qty Received</TableHead>
                <TableHead className="text-right">Qty Accepted</TableHead>
                <TableHead className="text-right">Qty Rejected</TableHead>
                <TableHead>UoM</TableHead>
                <TableHead>Status</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {grn.items.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={6} className="py-8 text-center text-sm text-muted-foreground">
                    No line items.
                  </TableCell>
                </TableRow>
              ) : (
                grn.items.map((item) => (
                  <TableRow key={item.id}>
                    <TableCell className="text-sm">{item.description}</TableCell>
                    <TableCell className="text-right tabular-nums text-sm">
                      {parseFloat(item.received_quantity).toLocaleString()}
                    </TableCell>
                    <TableCell className="text-right tabular-nums text-sm">
                      {parseFloat(item.accepted_quantity).toLocaleString()}
                    </TableCell>
                    <TableCell className="text-right tabular-nums text-sm">
                      {parseFloat(item.rejected_quantity).toLocaleString()}
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      {item.unit_of_measure}
                    </TableCell>
                    <TableCell>
                      <ItemStatusBadge status={item.status} />
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </div>
      </section>

      {/* ── Assign Committee section ── */}
      {canAssignCommittee && (
        <>
          <Separator />
          <section aria-labelledby="assign-committee-heading">
            <div className="flex items-center gap-2 mb-3">
              <Users className="size-5 text-muted-foreground" aria-hidden="true" />
              <h2 id="assign-committee-heading" className="text-base font-semibold">
                Assign Inspection Committee
              </h2>
            </div>
            <p className="mb-4 text-sm text-muted-foreground">
              Select at least 2 committee members to inspect this goods receipt.
            </p>

            {assignError && (
              <Alert variant="destructive" role="alert" className="mb-4">
                <AlertDescription>{assignError}</AlertDescription>
              </Alert>
            )}

            <form onSubmit={handleAssignCommittee} noValidate className="space-y-4">
              <div className="space-y-2" role="group" aria-label="Select committee members">
                {committeeOptions.length === 0 ? (
                  <p className="text-sm text-muted-foreground">No Committee_Member users found.</p>
                ) : (
                  <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    {committeeOptions.map((user) => {
                      const checked = selectedCommitteeIds.includes(user.id)
                      return (
                        <label
                          key={user.id}
                          className={`flex cursor-pointer items-center gap-2 rounded-lg border p-3 text-sm transition-colors ${
                            checked
                              ? "border-primary bg-primary/5"
                              : "border-border hover:bg-muted"
                          }`}
                        >
                          <input
                            type="checkbox"
                            checked={checked}
                            onChange={() => toggleCommitteeMember(user.id)}
                            className="h-4 w-4 rounded border-border"
                            aria-label={`Select ${user.name}`}
                          />
                          <div className="flex-1 min-w-0">
                            <p className="font-medium truncate">{user.name}</p>
                            <p className="text-xs text-muted-foreground truncate">
                              {user.email}
                            </p>
                          </div>
                        </label>
                      )
                    })}
                  </div>
                )}
              </div>

              <div className="flex items-center gap-3">
                <Button
                  type="submit"
                  disabled={assignCommittee.isPending || selectedCommitteeIds.length < 2}
                  aria-label="Assign selected committee members"
                >
                  {assignCommittee.isPending ? "Assigning…" : "Assign Committee"}
                </Button>
                {selectedCommitteeIds.length > 0 && (
                  <span className="text-sm text-muted-foreground">
                    {selectedCommitteeIds.length} member
                    {selectedCommitteeIds.length !== 1 ? "s" : ""} selected
                  </span>
                )}
              </div>
            </form>
          </section>
        </>
      )}

      {/* ── Submit Inspection section ── */}
      {canSubmitInspection && grn.items.length > 0 && (
        <>
          <Separator />
          <section aria-labelledby="inspection-heading">
            <div className="flex items-center gap-2 mb-3">
              <ClipboardCheck className="size-5 text-muted-foreground" aria-hidden="true" />
              <h2 id="inspection-heading" className="text-base font-semibold">
                Submit Inspection Results
              </h2>
            </div>
            <p className="mb-4 text-sm text-muted-foreground">
              Accept or reject each line item and optionally add notes.
            </p>

            {inspectionError && (
              <Alert variant="destructive" role="alert" className="mb-4">
                <AlertDescription>{inspectionError}</AlertDescription>
              </Alert>
            )}

            <form onSubmit={handleSubmitInspection} noValidate className="space-y-4">
              <div className="space-y-3">
                {grn.items.map((item) => {
                  const result = itemResults[item.id] ?? { accepted: true, notes: "" }
                  return (
                    <Card key={item.id} className="p-4 space-y-3">
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                          <p className="text-sm font-medium">{item.description}</p>
                          <p className="text-xs text-muted-foreground">
                            Received:{" "}
                            {parseFloat(item.received_quantity).toLocaleString()}{" "}
                            {item.unit_of_measure}
                          </p>
                        </div>
                        {/* Accept / Reject toggle */}
                        <div
                          className="flex items-center gap-2 rounded-lg border border-border p-1"
                          role="group"
                          aria-label={`Decision for ${item.description}`}
                        >
                          <button
                            type="button"
                            onClick={() =>
                              setItemResults((prev) => ({
                                ...prev,
                                [item.id]: { ...result, accepted: true },
                              }))
                            }
                            className={`rounded-md px-3 py-1 text-sm font-medium transition-colors ${
                              result.accepted
                                ? "bg-green-600 text-white"
                                : "text-muted-foreground hover:bg-muted"
                            }`}
                            aria-pressed={result.accepted}
                          >
                            Accept
                          </button>
                          <button
                            type="button"
                            onClick={() =>
                              setItemResults((prev) => ({
                                ...prev,
                                [item.id]: { ...result, accepted: false },
                              }))
                            }
                            className={`rounded-md px-3 py-1 text-sm font-medium transition-colors ${
                              !result.accepted
                                ? "bg-destructive text-destructive-foreground"
                                : "text-muted-foreground hover:bg-muted"
                            }`}
                            aria-pressed={!result.accepted}
                          >
                            Reject
                          </button>
                        </div>
                      </div>
                      {/* Notes */}
                      <div className="space-y-1">
                        <Label htmlFor={`notes-${item.id}`} className="text-xs text-muted-foreground">
                          Notes (optional)
                        </Label>
                        <Textarea
                          id={`notes-${item.id}`}
                          rows={2}
                          placeholder="Add any notes about this item…"
                          value={result.notes}
                          onChange={(e) =>
                            setItemResults((prev) => ({
                              ...prev,
                              [item.id]: { ...result, notes: e.target.value },
                            }))
                          }
                          className="text-sm"
                        />
                      </div>
                    </Card>
                  )
                })}
              </div>

              <Button type="submit" disabled={submitInspection.isPending}>
                {submitInspection.isPending ? "Submitting…" : "Submit Inspection Results"}
              </Button>
            </form>
          </section>
        </>
      )}

      {/* ── Read-only inspection result view ── */}
      {isReadOnly && grn.items.length > 0 && (
        <>
          <Separator />
          <section aria-labelledby="result-heading">
            <h2 id="result-heading" className="mb-3 text-base font-semibold">
              Inspection Result
            </h2>
            <div className="space-y-2">
              {grn.items.map((item) => (
                <Card key={item.id} className="flex flex-wrap items-center justify-between gap-4 p-4">
                  <div>
                    <p className="text-sm font-medium">{item.description}</p>
                    {item.rejection_reason && (
                      <p className="mt-0.5 text-xs text-muted-foreground">
                        Rejection reason: {item.rejection_reason}
                      </p>
                    )}
                  </div>
                  <div className="flex items-center gap-4 text-sm">
                    <span className="text-muted-foreground">
                      Accepted:{" "}
                      <span className="font-medium text-foreground">
                        {parseFloat(item.accepted_quantity).toLocaleString()}
                      </span>
                    </span>
                    <span className="text-muted-foreground">
                      Rejected:{" "}
                      <span className="font-medium text-foreground">
                        {parseFloat(item.rejected_quantity).toLocaleString()}
                      </span>
                    </span>
                    <ItemStatusBadge status={item.status} />
                  </div>
                </Card>
              ))}
            </div>
          </section>
        </>
      )}
    </motion.div>
  )
}
