"use client"

/**
 * Contract detail page.
 *
 * Features:
 * - Header: contract number (monospace), status badge, title
 * - Parties: supplier info, linked PO or Tender
 * - Scope: scope text, start/end date, payment terms
 * - Value consumption progress bar: ShadCN Progress (amber at ≥80%)
 * - Amendment history: table of amendments (version #, reason, date, by)
 * - Documents: list with download links; "Upload" button for officer/admin
 * - Action buttons (role-based):
 *     "Activate"  — draft status, officer/admin
 *     "Amend"     — draft or active status, officer/admin
 *     "Terminate" — active status, officer/admin
 * - Loading skeleton + error state with retry
 *
 * Validates: Requirements 11.1, 11.5, 22.6
 */

import { use, useRef, useState } from "react"
import Link from "next/link"
import { motion } from "framer-motion"
import {
  ArrowLeft,
  RefreshCw,
  FileText,
  Download,
  Upload,
  CheckCircle2,
  Edit,
  Ban,
  AlertTriangle,
} from "lucide-react"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Card } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { Separator } from "@/components/ui/separator"
import { Progress } from "@/components/ui/progress"
import {
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableHead,
  TableCell,
} from "@/components/ui/table"
import { ContractStatusBadge } from "@/components/contracts/ContractStatusBadge"
import { AmendContractModal } from "@/components/contracts/AmendContractModal"
import { TerminateContractModal } from "@/components/contracts/TerminateContractModal"
import {
  useContract,
  useActivateContract,
  useUploadContractDoc,
} from "@/hooks/useContracts"
import { useAuthStore } from "@/store/authStore"
import { formatCurrency } from "@/lib/utils"

// ─── Constants ────────────────────────────────────────────────────────────────

const OFFICER_ROLES = ["Procurement_Officer", "Tenant_Admin"]

// ─── Loading skeleton ─────────────────────────────────────────────────────────

function DetailSkeleton() {
  return (
    <div className="space-y-6">
      <Skeleton className="h-5 w-32" />
      <div className="flex items-start justify-between gap-4">
        <div className="space-y-2">
          <Skeleton className="h-8 w-64" />
          <Skeleton className="h-5 w-40" />
        </div>
        <Skeleton className="h-9 w-28" />
      </div>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {Array.from({ length: 3 }).map((_, i) => (
          <Skeleton key={i} className="h-20 rounded-xl" />
        ))}
      </div>
      <Skeleton className="h-48 rounded-xl" />
      <Skeleton className="h-40 rounded-xl" />
      <Skeleton className="h-40 rounded-xl" />
    </div>
  )
}

// ─── InfoCard helper ──────────────────────────────────────────────────────────

function InfoCard({
  label,
  value,
  sub,
  children,
}: {
  label: string
  value?: string
  sub?: string
  children?: React.ReactNode
}) {
  return (
    <Card className="p-4">
      <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
        {label}
      </p>
      {value && (
        <p className="mt-1 text-sm font-semibold leading-snug">{value}</p>
      )}
      {sub && <p className="text-xs text-muted-foreground">{sub}</p>}
      {children}
    </Card>
  )
}

// ─── Framer Motion ────────────────────────────────────────────────────────────

const fadeIn = {
  hidden: { opacity: 0, y: 8 },
  visible: {
    opacity: 1,
    y: 0,
    transition: { duration: 0.25, ease: "easeOut" as const },
  },
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function ContractDetailPage({
  params,
}: {
  params: Promise<{ id: string }>
}) {
  const { id } = use(params)
  const role = useAuthStore((s) => s.role)

  const { data, isLoading, isError, refetch } = useContract(id)
  const activateContract = useActivateContract()
  const uploadDoc = useUploadContractDoc()

  const [amendOpen, setAmendOpen] = useState(false)
  const [terminateOpen, setTerminateOpen] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)

  const fileInputRef = useRef<HTMLInputElement>(null)

  const contract = data?.data

  // ── Permissions ──────────────────────────────────────────────────────────

  const isOfficer = role !== null && OFFICER_ROLES.includes(role)

  const canActivate = isOfficer && contract?.status === "draft"
  const canAmend =
    isOfficer &&
    (contract?.status === "draft" || contract?.status === "active")
  const canTerminate = isOfficer && contract?.status === "active"
  const canUpload = isOfficer

  // ── Actions ───────────────────────────────────────────────────────────────

  async function handleActivate() {
    if (!contract) return
    setActionError(null)
    try {
      await activateContract.mutateAsync(contract.id)
    } catch {
      setActionError("Failed to activate contract. Please try again.")
    }
  }

  function handleUploadClick() {
    fileInputRef.current?.click()
  }

  async function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0]
    if (!file || !contract) return
    setActionError(null)
    try {
      await uploadDoc.mutateAsync({
        contractId: contract.id,
        file,
        documentType: "other",
      })
    } catch {
      setActionError("Failed to upload document. Please try again.")
    } finally {
      // Reset input so the same file can be re-uploaded if needed
      if (fileInputRef.current) fileInputRef.current.value = ""
    }
  }

  // ── Loading / error ───────────────────────────────────────────────────────

  if (isLoading) return <DetailSkeleton />

  if (isError || !contract) {
    return (
      <div className="flex flex-col items-center gap-4 py-16">
        <p className="text-sm text-muted-foreground">
          Failed to load contract.
        </p>
        <Button variant="outline" size="sm" onClick={() => refetch()}>
          <RefreshCw className="size-3.5" aria-hidden="true" />
          Retry
        </Button>
      </div>
    )
  }

  const consumedNum = parseFloat(contract.consumed_value ?? "0")
  const totalNum = parseFloat(contract.total_value ?? "0")
  const pct = contract.consumption_percentage ?? 0
  const isHighConsumption = pct >= 80

  const amendments = contract.amendments ?? []
  const documents = contract.documents ?? []

  const anyActionPending =
    activateContract.isPending || uploadDoc.isPending

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
        href="/contracts"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground transition-colors"
      >
        <ArrowLeft className="size-4" aria-hidden="true" />
        Back to Contracts
      </Link>

      {/* Header */}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="space-y-1">
          <div className="flex flex-wrap items-center gap-3">
            <h1 className="text-2xl font-semibold font-mono tracking-tight">
              {contract.contract_number}
            </h1>
            <ContractStatusBadge status={contract.status} />
          </div>
          <p className="text-lg font-medium text-foreground">{contract.title}</p>
          <p className="text-sm text-muted-foreground">
            Created by {contract.creator?.name ?? "—"}
          </p>
        </div>

        {/* Total value */}
        <div className="text-right">
          <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
            Total Value
          </p>
          <p className="text-xl font-semibold tabular-nums">
            {formatCurrency(contract.total_value, contract.currency)}
          </p>
          <p className="text-xs text-muted-foreground">{contract.currency}</p>
        </div>
      </div>

      {/* Action buttons */}
      <div className="flex flex-wrap items-center gap-2">
        {canActivate && (
          <Button
            onClick={handleActivate}
            disabled={anyActionPending}
            aria-label="Activate contract"
            className="bg-green-600 hover:bg-green-700 text-white"
          >
            <CheckCircle2 className="size-4" aria-hidden="true" />
            {activateContract.isPending ? "Activating…" : "Activate"}
          </Button>
        )}
        {canAmend && (
          <Button
            variant="outline"
            onClick={() => setAmendOpen(true)}
            disabled={anyActionPending}
            aria-label="Amend contract"
          >
            <Edit className="size-4" aria-hidden="true" />
            Amend
          </Button>
        )}
        {canTerminate && (
          <Button
            variant="outline"
            onClick={() => setTerminateOpen(true)}
            disabled={anyActionPending}
            aria-label="Terminate contract"
            className="text-destructive hover:text-destructive border-destructive/30 hover:bg-destructive/5"
          >
            <Ban className="size-4" aria-hidden="true" />
            Terminate
          </Button>
        )}
      </div>

      {actionError && (
        <Alert variant="destructive" role="alert">
          <AlertDescription>{actionError}</AlertDescription>
        </Alert>
      )}

      {/* Parties section */}
      <section aria-labelledby="contract-parties-heading">
        <h2 id="contract-parties-heading" className="mb-3 text-base font-semibold">
          Parties
        </h2>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <InfoCard
            label="Supplier"
            value={contract.supplier?.organization_name ?? "—"}
            sub={contract.supplier?.contact_email}
          />
          {contract.purchase_order ? (
            <InfoCard label="Linked Purchase Order">
              <Link
                href={`/purchase-orders/${contract.purchase_order.id}`}
                className="mt-1 inline-flex items-center gap-1.5 text-sm font-semibold text-primary underline-offset-2 hover:underline font-mono"
              >
                {contract.purchase_order.po_number}
              </Link>
            </InfoCard>
          ) : (
            <InfoCard label="Linked Purchase Order" value="—" />
          )}
          {contract.tender ? (
            <InfoCard label="Linked Tender">
              <Link
                href={`/tenders/${contract.tender.id}`}
                className="mt-1 inline-block text-sm font-semibold text-primary underline-offset-2 hover:underline truncate max-w-full"
              >
                {contract.tender.title}
              </Link>
            </InfoCard>
          ) : (
            <InfoCard label="Linked Tender" value="—" />
          )}
        </div>
      </section>

      <Separator />

      {/* Scope section */}
      <section aria-labelledby="contract-scope-heading">
        <h2 id="contract-scope-heading" className="mb-3 text-base font-semibold">
          Scope &amp; Terms
        </h2>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <InfoCard
            label="Start Date"
            value={new Date(contract.start_date).toLocaleDateString()}
          />
          <InfoCard
            label="End Date"
            value={new Date(contract.end_date).toLocaleDateString()}
          />
          <InfoCard
            label="Payment Terms"
            value={contract.payment_terms ?? "—"}
          />
          <InfoCard
            label="Currency"
            value={contract.currency}
          />
        </div>
        <Card className="mt-4 p-4">
          <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
            Scope of Work
          </p>
          <p className="mt-2 text-sm leading-relaxed whitespace-pre-wrap">
            {contract.scope}
          </p>
        </Card>
      </section>

      <Separator />

      {/* Value consumption progress bar */}
      <section aria-labelledby="contract-consumption-heading">
        <h2
          id="contract-consumption-heading"
          className="mb-3 text-base font-semibold"
        >
          Value Consumption
        </h2>
        <Card className="p-4 space-y-3">
          {isHighConsumption && (
            <Alert
              className="border-amber-200 bg-amber-50 dark:bg-amber-950/30"
              role="status"
            >
              <AlertTriangle
                className="size-4 text-amber-600 dark:text-amber-400"
                aria-hidden="true"
              />
              <AlertDescription className="text-amber-700 dark:text-amber-300">
                Contract value consumption has reached{" "}
                <strong>{pct.toFixed(1)}%</strong>. Consider reviewing the
                contract or initiating renewal.
              </AlertDescription>
            </Alert>
          )}
          <div className="flex items-center justify-between text-sm">
            <span className="text-muted-foreground">
              Consumed:{" "}
              <span className="font-medium text-foreground">
                {formatCurrency(consumedNum.toFixed(2), contract.currency)}
              </span>
            </span>
            <span
              className={
                isHighConsumption
                  ? "font-semibold text-amber-600 dark:text-amber-400"
                  : "font-semibold text-foreground"
              }
              aria-label={`${pct.toFixed(1)}% consumed`}
            >
              {pct.toFixed(1)}%
            </span>
            <span className="text-muted-foreground">
              Total:{" "}
              <span className="font-medium text-foreground">
                {formatCurrency(totalNum.toFixed(2), contract.currency)}
              </span>
            </span>
          </div>
          <Progress
            value={Math.min(pct, 100)}
            className={
              isHighConsumption
                ? "[&>div]:bg-amber-500"
                : "[&>div]:bg-primary"
            }
            aria-label={`Value consumption: ${pct.toFixed(1)}%`}
          />
        </Card>
      </section>

      <Separator />

      {/* Amendment history */}
      <section aria-labelledby="contract-amendments-heading">
        <h2
          id="contract-amendments-heading"
          className="mb-3 text-base font-semibold"
        >
          Amendment History
        </h2>
        <div className="rounded-xl border border-border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Version</TableHead>
                <TableHead>Reason</TableHead>
                <TableHead>Date</TableHead>
                <TableHead>By</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {amendments.length === 0 ? (
                <TableRow>
                  <TableCell
                    colSpan={4}
                    className="py-8 text-center text-sm text-muted-foreground"
                  >
                    No amendments recorded.
                  </TableCell>
                </TableRow>
              ) : (
                amendments.map((amend) => (
                  <TableRow key={amend.id}>
                    <TableCell className="font-mono text-sm font-medium">
                      v{amend.amendment_number}
                    </TableCell>
                    <TableCell className="text-sm max-w-[300px]">
                      <p className="line-clamp-2">{amend.reason}</p>
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground whitespace-nowrap">
                      {new Date(amend.created_at).toLocaleDateString()}
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      {amend.amended_by?.name ?? "—"}
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </div>
      </section>

      <Separator />

      {/* Documents */}
      <section aria-labelledby="contract-docs-heading">
        <div className="mb-3 flex items-center justify-between">
          <h2 id="contract-docs-heading" className="text-base font-semibold">
            Documents
          </h2>
          {canUpload && (
            <>
              <Button
                variant="outline"
                size="sm"
                onClick={handleUploadClick}
                disabled={uploadDoc.isPending}
                aria-label="Upload contract document"
              >
                <Upload className="size-4" aria-hidden="true" />
                {uploadDoc.isPending ? "Uploading…" : "Upload"}
              </Button>
              {/* Hidden file input */}
              <input
                ref={fileInputRef}
                type="file"
                accept=".pdf,.docx,.xlsx,.png,.jpg,.jpeg"
                className="sr-only"
                aria-hidden="true"
                tabIndex={-1}
                onChange={handleFileChange}
              />
            </>
          )}
        </div>

        {documents.length === 0 ? (
          <Card className="p-6 text-center text-sm text-muted-foreground">
            No documents attached to this contract.
            {canUpload && (
              <button
                className="ml-1 text-primary underline-offset-2 hover:underline"
                onClick={handleUploadClick}
              >
                Upload one now.
              </button>
            )}
          </Card>
        ) : (
          <div className="rounded-xl border border-border divide-y divide-border">
            {documents.map((doc) => (
              <div
                key={doc.id}
                className="flex items-center justify-between gap-4 px-4 py-3"
              >
                <div className="flex items-center gap-3 min-w-0">
                  <FileText
                    className="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                  />
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium">
                      {doc.file_name}
                    </p>
                    {doc.type_label && (
                      <p className="text-xs text-muted-foreground capitalize">
                        {doc.type_label}
                      </p>
                    )}
                  </div>
                </div>
                {doc.file_path && (
                  <a
                    href={doc.file_path}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex shrink-0 items-center gap-1.5 rounded-md px-2 py-1 text-xs text-primary transition-colors hover:bg-muted"
                    aria-label={`Download ${doc.file_name}`}
                  >
                    <Download className="size-3.5" aria-hidden="true" />
                    Download
                  </a>
                )}
              </div>
            ))}
          </div>
        )}
      </section>

      {/* Amend modal */}
      {contract && (
        <AmendContractModal
          contract={contract}
          open={amendOpen}
          onOpenChange={setAmendOpen}
          onSuccess={() => refetch()}
        />
      )}

      {/* Terminate modal */}
      {contract && (
        <TerminateContractModal
          contractId={contract.id}
          contractTitle={contract.title}
          open={terminateOpen}
          onOpenChange={setTerminateOpen}
          onSuccess={() => refetch()}
        />
      )}
    </motion.div>
  )
}
