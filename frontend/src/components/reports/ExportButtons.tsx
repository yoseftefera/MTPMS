"use client";

/**
 * PDF and Excel export buttons for report pages.
 *
 * - Excel: calls the API export endpoint and triggers a browser download.
 * - PDF:   same, but opens in a new tab for inline preview / print.
 * - If the backend returns 202 (async), shows a toast-like notification that
 *   the report will be available shortly via the notification system.
 *
 * Validates: Requirements 16.7, 16.8, 22.10
 */

import { useState } from "react";
import { FileDown, FileText, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { exportReportExcel, exportReportPDF } from "@/lib/api/reporting";
import type { ReportFilters } from "@/types/reporting";

// ─── Types ────────────────────────────────────────────────────────────────────

type ReportType =
  | "spending-analytics"
  | "supplier-performance"
  | "tender-statistics"
  | "financial-summary"
  | "procurement-timeline";

interface ExportButtonsProps {
  reportType: ReportType;
  filters?: ReportFilters;
  filename?: string; // base filename without extension
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function triggerDownload(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

function openPDFInNewTab(blob: Blob) {
  const url = URL.createObjectURL(blob);
  window.open(url, "_blank", "noopener,noreferrer");
  // Revoke after a short delay to allow the new tab to load
  setTimeout(() => URL.revokeObjectURL(url), 10_000);
}

// ─── Component ────────────────────────────────────────────────────────────────

export function ExportButtons({
  reportType,
  filters,
  filename = "report",
}: ExportButtonsProps) {
  const [excelLoading, setExcelLoading] = useState(false);
  const [pdfLoading, setPdfLoading] = useState(false);
  const [asyncMessage, setAsyncMessage] = useState<string | null>(null);

  async function handleExcel() {
    setExcelLoading(true);
    setAsyncMessage(null);
    try {
      const blob = await exportReportExcel(reportType, filters);
      if (blob === null) {
        // Async export — backend will send a notification when ready
        setAsyncMessage(
          "Large report queued. You will receive a notification when it is ready to download.",
        );
      } else {
        triggerDownload(blob, `${filename}.xlsx`);
      }
    } catch {
      setAsyncMessage("Export failed. Please try again.");
    } finally {
      setExcelLoading(false);
    }
  }

  async function handlePDF() {
    setPdfLoading(true);
    setAsyncMessage(null);
    try {
      const blob = await exportReportPDF(reportType, filters);
      if (blob === null) {
        setAsyncMessage(
          "Large report queued. You will receive a notification when it is ready to download.",
        );
      } else {
        openPDFInNewTab(blob);
      }
    } catch {
      setAsyncMessage("Export failed. Please try again.");
    } finally {
      setPdfLoading(false);
    }
  }

  return (
    <div className="flex flex-col gap-2">
      <div className="flex items-center gap-2">
        <Button
          variant="outline"
          size="sm"
          onClick={handleExcel}
          disabled={excelLoading || pdfLoading}
          aria-label="Export report as Excel"
          className="gap-1.5"
        >
          {excelLoading ? (
            <Loader2 className="size-3.5 animate-spin" />
          ) : (
            <FileDown className="size-3.5" />
          )}
          Export Excel
        </Button>

        <Button
          variant="outline"
          size="sm"
          onClick={handlePDF}
          disabled={excelLoading || pdfLoading}
          aria-label="Export report as PDF"
          className="gap-1.5"
        >
          {pdfLoading ? (
            <Loader2 className="size-3.5 animate-spin" />
          ) : (
            <FileText className="size-3.5" />
          )}
          Export PDF
        </Button>
      </div>

      {asyncMessage && (
        <p
          role="status"
          aria-live="polite"
          className="text-xs text-muted-foreground"
        >
          {asyncMessage}
        </p>
      )}
    </div>
  );
}
