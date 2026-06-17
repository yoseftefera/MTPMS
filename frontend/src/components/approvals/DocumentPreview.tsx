"use client"

/**
 * DocumentPreview — inline summary card showing key fields of a document
 * that is pending approval.
 *
 * Supports document types: purchase_request, tender, purchase_order.
 * Used inside approval action dialogs to provide context before acting.
 *
 * Validates: Requirements 22.5
 */

import { FileText, Calendar, Building2, DollarSign, Tag } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { DOCUMENT_TYPE_LABELS, type DocumentType } from "@/lib/validations/approvalWorkflows"
import type { Approval } from "@/types/models.types"

interface DocumentPreviewProps {
  approval: Approval
}

function MetaRow({
  icon: Icon,
  label,
  value,
}: {
  icon: React.ElementType
  label: string
  value: React.ReactNode
}) {
  return (
    <div className="flex items-center gap-2 text-xs">
      <Icon className="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
      <span className="text-muted-foreground">{label}:</span>
      <span className="font-medium text-foreground">{value ?? "—"}</span>
    </div>
  )
}

export function DocumentPreview({ approval }: DocumentPreviewProps) {
  const docTypeLabel =
    DOCUMENT_TYPE_LABELS[approval.document_type as DocumentType] ??
    approval.document_type

  const formatDate = (iso: string) =>
    new Intl.DateTimeFormat("en-US", {
      month: "short",
      day: "numeric",
      year: "numeric",
    }).format(new Date(iso))

  return (
    <div className="rounded-lg border border-border bg-muted/30 p-4 space-y-3">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <FileText className="size-4 text-muted-foreground" aria-hidden="true" />
          <span className="text-sm font-semibold">Document</span>
        </div>
        <Badge variant="secondary">{docTypeLabel}</Badge>
      </div>

      {/* Core fields */}
      <div className="space-y-1.5">
        <MetaRow
          icon={Tag}
          label="Document ID"
          value={
            <span className="font-mono text-xs">{approval.document_id}</span>
          }
        />
        <MetaRow
          icon={Building2}
          label="Approval Level"
          value={approval.level_id}
        />
        <MetaRow
          icon={Calendar}
          label="Submitted"
          value={formatDate(approval.created_at)}
        />
        <MetaRow
          icon={DollarSign}
          label="Current Status"
          value={
            <Badge
              variant={
                approval.action === "approved"
                  ? "success"
                  : approval.action === "rejected"
                  ? "destructive"
                  : approval.action === "returned"
                  ? "warning"
                  : "outline"
              }
              className="capitalize"
            >
              {approval.action}
            </Badge>
          }
        />
      </div>
    </div>
  )
}
