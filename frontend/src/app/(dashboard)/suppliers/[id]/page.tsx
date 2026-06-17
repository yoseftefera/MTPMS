"use client"

/**
 * Supplier detail page.
 *
 * Features:
 * - Profile section: org name, contact info, category, status badge
 * - Documents tab: compliance documents list with version history, upload button
 * - Performance metrics tab: on-time delivery + quality acceptance rate gauges,
 *   paginated performance records table
 * - Transaction history tab: related POs and contracts with links
 * - Role-gated action buttons: Approve, Reject, Blacklist (Procurement_Officer / Tenant_Admin)
 *
 * Validates: Requirements 7.3, 7.4, 7.6, 7.7, 7.10, 22.5, 22.6
 */

import { use, useState } from "react"
import {
  ArrowLeft,
  RefreshCw,
  FileText,
  Download,
  Upload,
  CheckCircle,
  XCircle,
  Ban,
  ShieldOff,
  Building2,
  Mail,
  Phone,
  Tag,
} from "lucide-react"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Card } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { Separator } from "@/components/ui/separator"
import { Progress } from "@/components/ui/progress"
import { Alert, AlertDescription } from "@/components/ui/alert"
import {
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableHead,
  TableCell,
} from "@/components/ui/table"
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs"
import { SupplierStatusBadge } from "@/components/suppliers/SupplierStatusBadge"
import { BlacklistSupplierDialog } from "@/components/suppliers/BlacklistSupplierDialog"
import { RejectSupplierDialog } from "@/components/suppliers/RejectSupplierDialog"
import { UploadDocumentDialog } from "@/components/suppliers/UploadDocumentDialog"
import {
  useSupplier,
  useApproveSupplier,
  useSupplierPerformance,
} from "@/hooks/useSuppliers"
import { useAuthStore } from "@/store/authStore"
import { DOCUMENT_TYPE_LABELS } from "@/lib/validations/suppliers"
import type { SupplierDocument, PurchaseOrder, Contract } from "@/types/models.types"

// ─── Role guard ───────────────────────────────────────────────────────────────

const PRIVILEGED_ROLES = ["Procurement_Officer", "Tenant_Admin"]

// ─── Helpers ──────────────────────────────────────────────────────────────────

function formatDate(iso: string) {
  return new Intl.DateTimeFormat("en-US", {
    month: "short",
    day: "numeric",
    year: "numeric",
  }).format(new Date(iso))
}

function MetricGauge({
  label,
  value,
  color = "primary",
}: {
  label: string
  value: string | number
  color?: "primary" | "green" | "blue"
}) {
  const numVal = typeof value === "string" ? parseFloat(value) : value
  const displayVal = isNaN(numVal) ? 0 : numVal

  const progressClass =
    color === "green"
      ? "[&>div]:bg-green-500"
      : color === "blue"
        ? "[&>div]:bg-blue-500"
        : ""

  return (
    <div className="space-y-1.5">
      <div className="flex items-center justify-between text-sm">
        <span className="font-medium">{label}</span>
        <span className="tabular-nums text-muted-foreground">{displayVal.toFixed(1)}%</span>
      </div>
      <Progress
        value={displayVal}
        max={100}
        label={label}
        className={progressClass}
      />
    </div>
  )
}

// ─── Documents tab ────────────────────────────────────────────────────────────

function DocumentsTab({
  supplierId,
  documents,
  canUpload,
}: {
  supplierId: string
  documents: SupplierDocument[]
  canUpload: boolean
}) {
  const [uploadOpen, setUploadOpen] = useState(false)

  if (documents.length === 0 && !canUpload) {
    return (
      <p className="text-sm text-muted-foreground">No compliance documents uploaded.</p>
    )
  }

  return (
    <div className="space-y-4">
      {canUpload && (
        <div className="flex justify-end">
          <Button
            size="sm"
            variant="outline"
            onClick={() => setUploadOpen(true)}
            aria-label="Upload compliance document"
          >
            <Upload className="size-4" aria-hidden="true" />
            Upload Document
          </Button>
        </div>
      )}

      {documents.length === 0 ? (
        <p className="text-sm text-muted-foreground">No compliance documents uploaded.</p>
      ) : (
        <div className="rounded-xl border border-border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Document</TableHead>
                <TableHead>Type</TableHead>
                <TableHead>Version</TableHead>
                <TableHead>Expires</TableHead>
                <TableHead>Uploaded</TableHead>
                <TableHead className="w-24 text-right">Download</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {documents.map((doc) => {
                const isExpiringSoon =
                  doc.expires_at &&
                  new Date(doc.expires_at).getTime() - Date.now() <
                    30 * 24 * 60 * 60 * 1000

                return (
                  <TableRow key={doc.id}>
                    <TableCell>
                      <div className="flex items-center gap-2">
                        <FileText
                          className="size-4 shrink-0 text-muted-foreground"
                          aria-hidden="true"
                        />
                        <span className="text-sm">{doc.file_name}</span>
                      </div>
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      {DOCUMENT_TYPE_LABELS[doc.document_type] ?? doc.document_type}
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      v{doc.version}
                    </TableCell>
                    <TableCell>
                      {doc.expires_at ? (
                        <span
                          className={`text-sm ${
                            isExpiringSoon
                              ? "text-amber-600 font-medium dark:text-amber-400"
                              : "text-muted-foreground"
                          }`}
                        >
                          {formatDate(doc.expires_at)}
                          {isExpiringSoon && " ⚠"}
                        </span>
                      ) : (
                        <span className="text-sm text-muted-foreground">—</span>
                      )}
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      {formatDate(doc.created_at)}
                    </TableCell>
                    <TableCell className="text-right">
                      <a
                        href={doc.file_path}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-1 text-xs text-primary hover:underline underline-offset-2"
                        aria-label={`Download ${doc.file_name}`}
                      >
                        <Download className="size-3.5" aria-hidden="true" />
                        Download
                      </a>
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        </div>
      )}

      <UploadDocumentDialog
        supplierId={supplierId}
        open={uploadOpen}
        onOpenChange={setUploadOpen}
      />
    </div>
  )
}

// ─── Performance tab ──────────────────────────────────────────────────────────

function PerformanceTab({
  supplierId,
  onTimeDeliveryRate,
  qualityAcceptanceRate,
}: {
  supplierId: string
  onTimeDeliveryRate: string
  qualityAcceptanceRate: string
}) {
  const [perfPage, setPerfPage] = useState(1)
  const { data, isLoading } = useSupplierPerformance(supplierId, {
    page: perfPage,
    per_page: 10,
  })

  const records = data?.data ?? []
  const meta = data?.meta

  return (
    <div className="space-y-6">
      {/* Summary gauges */}
      <div className="grid gap-4 sm:grid-cols-2">
        <Card className="p-5 space-y-3">
          <h3 className="text-sm font-semibold">Overall Performance</h3>
          <MetricGauge
            label="On-Time Delivery Rate"
            value={onTimeDeliveryRate}
            color="green"
          />
          <MetricGauge
            label="Quality Acceptance Rate"
            value={qualityAcceptanceRate}
            color="blue"
          />
        </Card>

        <Card className="p-5 space-y-2">
          <h3 className="text-sm font-semibold">Score Summary</h3>
          <div className="space-y-2 pt-1">
            <div className="flex justify-between text-sm">
              <span className="text-muted-foreground">On-Time Delivery</span>
              <span className="tabular-nums font-medium">
                {parseFloat(onTimeDeliveryRate).toFixed(1)}%
              </span>
            </div>
            <Separator />
            <div className="flex justify-between text-sm">
              <span className="text-muted-foreground">Quality Acceptance</span>
              <span className="tabular-nums font-medium">
                {parseFloat(qualityAcceptanceRate).toFixed(1)}%
              </span>
            </div>
            <Separator />
            <div className="flex justify-between text-sm">
              <span className="text-muted-foreground">Average Score</span>
              <span className="tabular-nums font-medium">
                {(
                  (parseFloat(onTimeDeliveryRate) + parseFloat(qualityAcceptanceRate)) /
                  2
                ).toFixed(1)}
                %
              </span>
            </div>
          </div>
        </Card>
      </div>

      {/* Historical records */}
      <div>
        <h3 className="mb-3 text-sm font-semibold">Performance History</h3>
        {isLoading ? (
          <div className="space-y-2">
            {Array.from({ length: 5 }).map((_, i) => (
              <Skeleton key={i} className="h-10 w-full" />
            ))}
          </div>
        ) : records.length === 0 ? (
          <p className="text-sm text-muted-foreground">No performance records available.</p>
        ) : (
          <>
            <div className="rounded-xl border border-border">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Period</TableHead>
                    <TableHead className="text-right">On-Time Delivery</TableHead>
                    <TableHead className="text-right">Quality Acceptance</TableHead>
                    <TableHead>Recorded</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {records.map((rec) => (
                    <TableRow key={rec.id}>
                      <TableCell className="text-sm">{rec.period_label}</TableCell>
                      <TableCell className="text-right tabular-nums text-sm">
                        {parseFloat(rec.on_time_delivery_rate).toFixed(1)}%
                      </TableCell>
                      <TableCell className="text-right tabular-nums text-sm">
                        {parseFloat(rec.quality_acceptance_rate).toFixed(1)}%
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {formatDate(rec.created_at)}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>

            {meta && meta.last_page > 1 && (
              <div className="mt-3 flex items-center justify-between text-sm text-muted-foreground">
                <span>
                  {meta.from ?? 0}–{meta.to ?? 0} of {meta.total} records
                </span>
                <div className="flex items-center gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={perfPage === 1}
                    onClick={() => setPerfPage((p) => p - 1)}
                  >
                    Previous
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={perfPage === meta.last_page}
                    onClick={() => setPerfPage((p) => p + 1)}
                  >
                    Next
                  </Button>
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  )
}

// ─── Transactions tab ─────────────────────────────────────────────────────────

function TransactionsTab({
  purchaseOrders = [],
  contracts = [],
}: {
  purchaseOrders?: PurchaseOrder[]
  contracts?: Contract[]
}) {
  return (
    <div className="space-y-6">
      {/* Purchase Orders */}
      <section aria-labelledby="pos-heading">
        <h3 id="pos-heading" className="mb-3 text-sm font-semibold">
          Purchase Orders
        </h3>
        {purchaseOrders.length === 0 ? (
          <p className="text-sm text-muted-foreground">No purchase orders linked to this supplier.</p>
        ) : (
          <div className="rounded-xl border border-border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>PO Number</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-right">Total</TableHead>
                  <TableHead>Delivery Date</TableHead>
                  <TableHead className="w-16" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {purchaseOrders.map((po) => (
                  <TableRow key={po.id}>
                    <TableCell className="font-mono text-sm font-medium">
                      {po.po_number}
                    </TableCell>
                    <TableCell>
                      <Badge variant="secondary" className="capitalize text-xs">
                        {po.status.replace(/_/g, " ")}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-right tabular-nums text-sm">
                      {po.currency} {parseFloat(po.total_amount).toLocaleString()}
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      {formatDate(po.required_delivery_date)}
                    </TableCell>
                    <TableCell>
                      <a
                        href={`/purchase-orders/${po.id}`}
                        className="text-xs text-primary hover:underline underline-offset-2"
                        aria-label={`View purchase order ${po.po_number}`}
                      >
                        View
                      </a>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </section>

      {/* Contracts */}
      <section aria-labelledby="contracts-heading">
        <h3 id="contracts-heading" className="mb-3 text-sm font-semibold">
          Contracts
        </h3>
        {contracts.length === 0 ? (
          <p className="text-sm text-muted-foreground">No contracts linked to this supplier.</p>
        ) : (
          <div className="rounded-xl border border-border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Contract #</TableHead>
                  <TableHead>Title</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-right">Value</TableHead>
                  <TableHead>End Date</TableHead>
                  <TableHead className="w-16" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {contracts.map((contract) => (
                  <TableRow key={contract.id}>
                    <TableCell className="font-mono text-sm font-medium">
                      {contract.contract_number}
                    </TableCell>
                    <TableCell className="text-sm">{contract.title}</TableCell>
                    <TableCell>
                      <Badge variant="secondary" className="capitalize text-xs">
                        {contract.status.replace(/_/g, " ")}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-right tabular-nums text-sm">
                      {contract.currency} {parseFloat(contract.total_value).toLocaleString()}
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      {formatDate(contract.end_date)}
                    </TableCell>
                    <TableCell>
                      <a
                        href={`/contracts/${contract.id}`}
                        className="text-xs text-primary hover:underline underline-offset-2"
                        aria-label={`View contract ${contract.contract_number}`}
                      >
                        View
                      </a>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </section>
    </div>
  )
}

// ─── Loading skeleton ─────────────────────────────────────────────────────────

function DetailSkeleton() {
  return (
    <div className="space-y-6">
      <Skeleton className="h-8 w-48" />
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-20 rounded-xl" />
        ))}
      </div>
      <Skeleton className="h-10 w-96 rounded-lg" />
      <Skeleton className="h-64 rounded-xl" />
    </div>
  )
}

// ─── Page component ───────────────────────────────────────────────────────────

export default function SupplierDetailPage({
  params,
}: {
  params: Promise<{ id: string }>
}) {
  const { id } = use(params)
  const role = useAuthStore((s) => s.role)
  const canAct = role !== null && PRIVILEGED_ROLES.includes(role)

  const { data, isLoading, isError, refetch } = useSupplier(id)
  const approve = useApproveSupplier()

  const [rejectOpen, setRejectOpen] = useState(false)
  const [blacklistOpen, setBlacklistOpen] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)

  const supplier = data?.data

  async function handleApprove() {
    setActionError(null)
    try {
      await approve.mutateAsync(id)
    } catch {
      setActionError("Failed to approve supplier. Please try again.")
    }
  }

  // ── Loading / error ──────────────────────────────────────────────────────────

  if (isLoading) return <DetailSkeleton />

  if (isError || !supplier) {
    return (
      <div className="flex flex-col items-center gap-4 py-16">
        <p className="text-sm text-muted-foreground">Failed to load supplier details.</p>
        <Button variant="outline" size="sm" onClick={() => refetch()}>
          <RefreshCw className="size-3.5" aria-hidden="true" />
          Retry
        </Button>
      </div>
    )
  }

  const documents = supplier.documents ?? []
  // These would come from the API include parameter
  const purchaseOrders = (supplier as typeof supplier & { purchase_orders?: PurchaseOrder[] })
    .purchase_orders ?? []
  const contracts = (supplier as typeof supplier & { contracts?: Contract[] }).contracts ?? []

  // ── Render ───────────────────────────────────────────────────────────────────

  return (
    <div className="space-y-6">
      {/* Back link */}
      <div>
        <a
          href="/suppliers"
          className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
        >
          <ArrowLeft className="size-4" aria-hidden="true" />
          Back to Suppliers
        </a>
      </div>

      {/* Header */}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="space-y-1">
          <div className="flex items-center gap-3 flex-wrap">
            <h1 className="text-2xl font-semibold tracking-tight">
              {supplier.organization_name}
            </h1>
            <SupplierStatusBadge status={supplier.status} />
          </div>
          <p className="text-sm text-muted-foreground">{supplier.business_category}</p>
        </div>

        {/* Role-gated action buttons */}
        {canAct && (
          <div className="flex items-center gap-2 flex-wrap">
            {supplier.status === "pending_verification" && (
              <>
                <Button
                  size="sm"
                  className="bg-green-600 hover:bg-green-700 text-white"
                  onClick={handleApprove}
                  disabled={approve.isPending}
                  aria-label="Approve supplier registration"
                >
                  <CheckCircle className="size-4" aria-hidden="true" />
                  {approve.isPending ? "Approving…" : "Approve"}
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  className="text-destructive border-destructive/30 hover:bg-destructive/5"
                  onClick={() => setRejectOpen(true)}
                  aria-label="Reject supplier registration"
                >
                  <XCircle className="size-4" aria-hidden="true" />
                  Reject
                </Button>
              </>
            )}

            {supplier.status === "active" && (
              <Button
                size="sm"
                variant="outline"
                className="text-destructive border-destructive/30 hover:bg-destructive/5"
                onClick={() => setBlacklistOpen(true)}
                aria-label="Blacklist supplier"
              >
                <Ban className="size-4" aria-hidden="true" />
                Blacklist
              </Button>
            )}

            {supplier.status === "blacklisted" && (
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <ShieldOff className="size-4 text-destructive" aria-hidden="true" />
                <span>Blacklisted on {supplier.blacklisted_at ? formatDate(supplier.blacklisted_at) : "—"}</span>
              </div>
            )}
          </div>
        )}
      </div>

      {actionError && (
        <Alert variant="destructive" role="alert">
          <AlertDescription>{actionError}</AlertDescription>
        </Alert>
      )}

      {/* Blacklist reason */}
      {supplier.status === "blacklisted" && supplier.blacklist_reason && (
        <Alert variant="destructive">
          <AlertDescription>
            <strong>Blacklist reason:</strong> {supplier.blacklist_reason}
          </AlertDescription>
        </Alert>
      )}

      {/* Profile info cards */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Card className="p-4">
          <div className="flex items-start gap-3">
            <Building2 className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            <div>
              <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                Organization
              </p>
              <p className="mt-1 text-sm font-semibold">{supplier.organization_name}</p>
            </div>
          </div>
        </Card>

        <Card className="p-4">
          <div className="flex items-start gap-3">
            <Mail className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            <div>
              <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                Contact
              </p>
              <p className="mt-1 text-sm font-semibold">{supplier.contact_name}</p>
              <p className="text-xs text-muted-foreground">{supplier.contact_email}</p>
            </div>
          </div>
        </Card>

        <Card className="p-4">
          <div className="flex items-start gap-3">
            <Phone className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            <div>
              <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                Phone
              </p>
              <p className="mt-1 text-sm font-semibold">
                {supplier.contact_phone ?? "—"}
              </p>
            </div>
          </div>
        </Card>

        <Card className="p-4">
          <div className="flex items-start gap-3">
            <Tag className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            <div>
              <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                Category
              </p>
              <p className="mt-1 text-sm font-semibold">{supplier.business_category}</p>
            </div>
          </div>
        </Card>
      </div>

      <Separator />

      {/* Tabbed content */}
      <Tabs defaultValue="documents">
        <TabsList>
          <TabsTrigger value="documents">
            Documents ({documents.length})
          </TabsTrigger>
          <TabsTrigger value="performance">Performance</TabsTrigger>
          <TabsTrigger value="transactions">Transactions</TabsTrigger>
        </TabsList>

        <TabsContent value="documents" className="pt-4">
          <DocumentsTab
            supplierId={supplier.id}
            documents={documents}
            canUpload={canAct}
          />
        </TabsContent>

        <TabsContent value="performance" className="pt-4">
          <PerformanceTab
            supplierId={supplier.id}
            onTimeDeliveryRate={supplier.on_time_delivery_rate}
            qualityAcceptanceRate={supplier.quality_acceptance_rate}
          />
        </TabsContent>

        <TabsContent value="transactions" className="pt-4">
          <TransactionsTab
            purchaseOrders={purchaseOrders}
            contracts={contracts}
          />
        </TabsContent>
      </Tabs>

      {/* Dialogs */}
      <RejectSupplierDialog
        supplier={supplier}
        open={rejectOpen}
        onOpenChange={setRejectOpen}
        onSuccess={() => refetch()}
      />

      <BlacklistSupplierDialog
        supplier={supplier}
        open={blacklistOpen}
        onOpenChange={setBlacklistOpen}
        onSuccess={() => refetch()}
      />
    </div>
  )
}
