<?php

namespace App\Services;

use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

/**
 * ExportService — generates PDF and Excel files for report data.
 *
 * Supports six report types:
 *   dashboard, procurement_timeline, spending_analytics,
 *   supplier_performance, tender_statistics, financial_summary
 *
 * For each report type the service fetches data from ReportingService,
 * flattens it to a table structure, and renders it as either:
 *   - PDF  via barryvdh/laravel-dompdf
 *   - Excel via maatwebsite/excel (in-memory Xlsx)
 *
 * Requirements: 16.7, 16.8
 */
class ExportService
{
    public function __construct(private readonly ReportingService $reportingService)
    {
    }

    // -------------------------------------------------------------------------
    // Supported report types
    // -------------------------------------------------------------------------

    /** @var string[] */
    public const SUPPORTED_REPORT_TYPES = [
        'dashboard',
        'procurement_timeline',
        'spending_analytics',
        'supplier_performance',
        'tender_statistics',
        'financial_summary',
    ];

    // -------------------------------------------------------------------------
    // Row-count estimation
    // -------------------------------------------------------------------------

    /**
     * Estimate the number of data rows for a given report type and filters.
     *
     * We perform a lightweight COUNT query per report type rather than
     * fetching all rows, so this runs fast even for very large datasets.
     *
     * Returns an integer row-count estimate.
     */
    public function estimateRowCount(string $reportType, array $filters, User $user): int
    {
        $isSystemAdmin = $user->hasRole('System_Admin');
        $tenantId      = $user->tenant_id;

        return match ($reportType) {
            'dashboard' => 6, // Always 6 KPI rows — always synchronous

            'procurement_timeline' => (int) \Illuminate\Support\Facades\DB::table('purchase_requests as pr')
                ->join('purchase_orders as po', 'po.purchase_request_id', '=', 'pr.id')
                ->whereNotNull('po.issued_at')
                ->whereNull('pr.deleted_at')
                ->whereNull('po.deleted_at')
                ->when(! $isSystemAdmin, fn ($q) => $q->where('pr.tenant_id', $tenantId))
                ->when(! empty($filters['department_id']), fn ($q) => $q->where('pr.department_id', $filters['department_id']))
                ->when(! empty($filters['created_from']), fn ($q) => $q->whereDate('pr.created_at', '>=', $filters['created_from']))
                ->when(! empty($filters['created_to']),   fn ($q) => $q->whereDate('pr.created_at', '<=', $filters['created_to']))
                ->count(),

            'spending_analytics' => (int) \Illuminate\Support\Facades\DB::table('invoice_items as ii')
                ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
                ->whereIn('inv.status', ['approved', 'paid'])
                ->whereNull('inv.deleted_at')
                ->when(! $isSystemAdmin, fn ($q) => $q->where('inv.tenant_id', $tenantId))
                ->when(! empty($filters['department_id']), fn ($q) => $q
                    ->join('purchase_orders as po', 'po.id', '=', 'inv.purchase_order_id')
                    ->where('po.department_id', $filters['department_id']))
                ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('inv.invoice_date', '>=', $filters['date_from']))
                ->when(! empty($filters['date_to']),   fn ($q) => $q->whereDate('inv.invoice_date', '<=', $filters['date_to']))
                ->count(),

            'supplier_performance' => (int) \Illuminate\Support\Facades\DB::table('suppliers')
                ->whereNull('deleted_at')
                ->when(! $isSystemAdmin, fn ($q) => $q->where('tenant_id', $tenantId))
                ->when(! empty($filters['supplier_id']),       fn ($q) => $q->where('id', $filters['supplier_id']))
                ->when(! empty($filters['business_category']), fn ($q) => $q->where('business_category', $filters['business_category']))
                ->count(),

            'tender_statistics' => (int) \Illuminate\Support\Facades\DB::table('tenders')
                ->whereNull('deleted_at')
                ->when(! $isSystemAdmin, fn ($q) => $q->where('tenant_id', $tenantId))
                ->when(! empty($filters['category']),  fn ($q) => $q->where('category', $filters['category']))
                ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['date_from']))
                ->when(! empty($filters['date_to']),   fn ($q) => $q->whereDate('created_at', '<=', $filters['date_to']))
                ->count(),

            'financial_summary' => (int) \Illuminate\Support\Facades\DB::table('budgets')
                ->when(! $isSystemAdmin, fn ($q) => $q->where('tenant_id', $tenantId))
                ->when(! empty($filters['department_id']), fn ($q) => $q->where('department_id', $filters['department_id']))
                ->count(),

            default => 0,
        };
    }

    // -------------------------------------------------------------------------
    // Main generation entry point
    // -------------------------------------------------------------------------

    /**
     * Generate a report file and return its raw content, MIME type, and file extension.
     *
     * @return array{0: string, 1: string, 2: string}  [fileContent, mimeType, extension]
     *
     * @throws \InvalidArgumentException When report type or format is unsupported.
     */
    public function generate(
        string $reportType,
        string $format,
        array  $filters,
        User   $user,
    ): array {
        if (! in_array($reportType, self::SUPPORTED_REPORT_TYPES, true)) {
            throw new \InvalidArgumentException("Unsupported report type: {$reportType}");
        }

        if (! in_array($format, ['pdf', 'excel'], true)) {
            throw new \InvalidArgumentException("Unsupported format: {$format}. Use 'pdf' or 'excel'.");
        }

        [$headings, $rows, $title] = $this->buildTableData($reportType, $filters, $user);

        return $format === 'pdf'
            ? $this->generatePdf($title, $headings, $rows)
            : $this->generateExcel($title, $headings, $rows);
    }

    // -------------------------------------------------------------------------
    // Data builders — one per report type
    // -------------------------------------------------------------------------

    /**
     * Fetch report data and return [headings[], rows[], title].
     *
     * @return array{0: string[], 1: array[], 2: string}
     */
    private function buildTableData(string $reportType, array $filters, User $user): array
    {
        return match ($reportType) {
            'dashboard'            => $this->buildDashboardTable($filters, $user),
            'procurement_timeline' => $this->buildProcurementTimelineTable($filters, $user),
            'spending_analytics'   => $this->buildSpendingAnalyticsTable($filters, $user),
            'supplier_performance' => $this->buildSupplierPerformanceTable($filters, $user),
            'tender_statistics'    => $this->buildTenderStatisticsTable($filters, $user),
            'financial_summary'    => $this->buildFinancialSummaryTable($filters, $user),
        };
    }

    /** Dashboard: one row per KPI. */
    private function buildDashboardTable(array $filters, User $user): array
    {
        $data = $this->reportingService->getDashboardKPIs($user);

        $rows = [
            ['PR Counts by Status', json_encode($data['pr_counts_by_status'])],
            ['Active Tenders Count', $data['active_tenders_count']],
            ['PO Fulfillment Rate (%)', $data['po_fulfillment_rate']],
            ['Budget Utilization (%)', $data['budget_utilization_percent']],
            ['Pending Approvals Count', $data['pending_approvals_count']],
            ['Overdue Deliveries Count', $data['overdue_deliveries_count']],
        ];

        return [['KPI', 'Value'], $rows, 'Dashboard Report'];
    }

    /** Procurement timeline: summary statistics. */
    private function buildProcurementTimelineTable(array $filters, User $user): array
    {
        $data = $this->reportingService->getProcurementTimeline($user, $filters);

        $rows = [
            ['Average Cycle Time (days)',  $data['avg_cycle_time_days'] ?? 'N/A'],
            ['Minimum Cycle Time (days)',  $data['min_cycle_time_days'] ?? 'N/A'],
            ['Maximum Cycle Time (days)',  $data['max_cycle_time_days'] ?? 'N/A'],
            ['Total Cycles Measured',      $data['total_cycles_measured']],
        ];

        return [['Metric', 'Value'], $rows, 'Procurement Timeline Report'];
    }

    /** Spending analytics: one row per supplier. */
    private function buildSpendingAnalyticsTable(array $filters, User $user): array
    {
        $data = $this->reportingService->getSpendingAnalytics($user, $filters);

        $headings = ['Supplier', 'Total Expenditure'];
        $rows     = array_map(
            fn ($r) => [$r['label'], $r['total_expenditure']],
            $data['by_supplier'],
        );

        return [$headings, $rows, 'Spending Analytics Report'];
    }

    /** Supplier performance: one row per supplier. */
    private function buildSupplierPerformanceTable(array $filters, User $user): array
    {
        $data = $this->reportingService->getSupplierPerformance($user, $filters);

        $headings = [
            'Supplier',
            'Business Category',
            'Status',
            'On-Time Delivery Rate (%)',
            'Quality Acceptance Rate (%)',
            'Total POs',
            'Total Contracts Value',
            'Total Invoiced Amount',
        ];

        $rows = array_map(fn ($r) => [
            $r['organization_name'],
            $r['business_category'],
            $r['status'],
            $r['on_time_delivery_rate'],
            $r['quality_acceptance_rate'],
            $r['total_pos_count'],
            $r['total_contracts_value'],
            $r['total_invoiced_amount'],
        ], $data['suppliers']);

        return [$headings, $rows, 'Supplier Performance Report'];
    }

    /** Tender statistics: summary + one row per status. */
    private function buildTenderStatisticsTable(array $filters, User $user): array
    {
        $data = $this->reportingService->getTenderStatistics($user, $filters);

        $headings = ['Status / Metric', 'Value'];

        $rows = [
            ['Total Tenders',             $data['total_tenders']],
            ['Average Bids per Tender',   $data['avg_bids_per_tender']],
            ['Awarded Count',             $data['awarded_count']],
            ['Cancelled Count',           $data['cancelled_count']],
            ['Awarded vs Cancelled Ratio', $data['awarded_vs_cancelled_ratio'] ?? 'N/A'],
        ];

        foreach ($data['by_status'] as $status => $count) {
            $rows[] = [ucfirst(str_replace('_', ' ', $status)) . ' Count', $count];
        }

        return [$headings, $rows, 'Tender Statistics Report'];
    }

    /** Financial summary: one row per department. */
    private function buildFinancialSummaryTable(array $filters, User $user): array
    {
        $data = $this->reportingService->getFinancialSummary($user, $filters);

        $headings = [
            'Department',
            'Fiscal Year',
            'Invoiced Amount',
            'Paid Amount',
            'Outstanding Amount',
            'Budget Allocated',
            'Budget Spent',
            'Budget Variance',
        ];

        $rows = array_map(fn ($r) => [
            $r['department_name'] ?? $r['department_id'],
            $r['fiscal_year'],
            $r['invoiced_amount'],
            $r['paid_amount'],
            $r['outstanding_amount'],
            $r['budget_allocated'],
            $r['budget_spent'],
            $r['budget_variance'],
        ], $data['departments']);

        return [$headings, $rows, 'Financial Summary Report'];
    }

    // -------------------------------------------------------------------------
    // Renderers
    // -------------------------------------------------------------------------

    /**
     * Render report data as a PDF using barryvdh/laravel-dompdf.
     *
     * Returns [pdfBinaryString, 'application/pdf', 'pdf'].
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function generatePdf(string $title, array $headings, array $rows): array
    {
        $html = $this->buildHtmlTable($title, $headings, $rows);

        $pdf = Pdf::loadHTML($html)
            ->setPaper('a4', 'landscape')
            ->setOptions([
                'defaultFont' => 'sans-serif',
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => false,
            ]);

        return [$pdf->output(), 'application/pdf', 'pdf'];
    }

    /**
     * Render report data as an Excel (.xlsx) file using maatwebsite/excel.
     *
     * Returns [xlsxBinaryString, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx'].
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function generateExcel(string $title, array $headings, array $rows): array
    {
        $export = new class (collect($rows), $headings, $title) implements
            FromCollection,
            WithHeadings,
            WithTitle,
            ShouldAutoSize
        {
            public function __construct(
                private readonly Collection $data,
                private readonly array      $headings,
                private readonly string     $sheetTitle,
            ) {
            }

            public function collection(): Collection
            {
                return $this->data;
            }

            public function headings(): array
            {
                return $this->headings;
            }

            public function title(): string
            {
                // Sheet names max 31 chars in Excel
                return mb_substr($this->sheetTitle, 0, 31);
            }
        };

        // Write to storage/app/tmp and read the raw bytes back, then clean up
        $relPath = 'tmp/report_' . \Illuminate\Support\Str::uuid() . '.xlsx';
        Excel::store($export, $relPath, 'local');
        $content = \Illuminate\Support\Facades\Storage::disk('local')->get($relPath);
        \Illuminate\Support\Facades\Storage::disk('local')->delete($relPath);

        $mimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return [$content, $mimeType, 'xlsx'];
    }

    // -------------------------------------------------------------------------
    // HTML table builder for PDF
    // -------------------------------------------------------------------------

    /**
     * Build a minimal but clean HTML string with inline CSS for DomPDF rendering.
     */
    private function buildHtmlTable(string $title, array $headings, array $rows): string
    {
        $thCells = implode('', array_map(
            fn ($h) => '<th>' . htmlspecialchars((string) $h, ENT_QUOTES, 'UTF-8') . '</th>',
            $headings,
        ));

        $bodyRows = '';
        foreach ($rows as $index => $row) {
            $bgColor  = ($index % 2 === 0) ? '#f9f9f9' : '#ffffff';
            $tdCells  = implode('', array_map(
                fn ($cell) => '<td>' . htmlspecialchars((string) ($cell ?? ''), ENT_QUOTES, 'UTF-8') . '</td>',
                $row,
            ));
            $bodyRows .= "<tr style=\"background:{$bgColor}\">{$tdCells}</tr>";
        }

        $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $generatedAt  = now()->toDateTimeString();

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: sans-serif; font-size: 11px; color: #222; margin: 20px; }
                h1   { font-size: 16px; margin-bottom: 4px; }
                p.meta { font-size: 9px; color: #666; margin-bottom: 12px; }
                table { width: 100%; border-collapse: collapse; }
                th {
                    background: #2563eb;
                    color: #fff;
                    padding: 6px 8px;
                    text-align: left;
                    font-size: 10px;
                }
                td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; }
            </style>
        </head>
        <body>
            <h1>{$escapedTitle}</h1>
            <p class="meta">Generated: {$generatedAt}</p>
            <table>
                <thead><tr>{$thCells}</tr></thead>
                <tbody>{$bodyRows}</tbody>
            </table>
        </body>
        </html>
        HTML;
    }
}
