<?php

namespace App\Traits;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Generates unique, sequential document numbers for PRs and POs.
 *
 * Format: {PREFIX}-{TENANT_CODE}-{YEAR}-{SEQUENCE}
 * Example: PR-ACME-2025-00001
 *
 * Uses a database-level SELECT ... FOR UPDATE lock to prevent race conditions
 * under concurrent submissions. The sequence is derived by counting existing
 * documents for the same tenant and year, then incrementing by 1.
 */
trait GeneratesDocumentNumber
{
    /**
     * Generate the next document number for the given prefix and tenant.
     *
     * @param  string  $prefix  e.g. 'PR' or 'PO'
     * @param  Tenant  $tenant
     * @param  int     $year    Fiscal year (defaults to current year)
     * @return string
     */
    protected function generateDocumentNumber(string $prefix, Tenant $tenant, int $year = 0): string
    {
        if ($year === 0) {
            $year = now()->year;
        }

        $tenantCode = strtoupper($tenant->tenant_code);
        $tableName  = $this->getTable();

        // Atomic sequence generation using a database-level row lock.
        // lockForUpdate() prevents concurrent transactions from reading the
        // same count, ensuring each document gets a unique sequence number.
        $sequence = DB::transaction(function () use ($tableName, $tenant, $year) {
            $count = DB::table($tableName)
                ->where('tenant_id', $tenant->id)
                ->whereYear('created_at', $year)
                ->lockForUpdate()
                ->count();

            return $count + 1;
        });

        return sprintf('%s-%s-%d-%05d', $prefix, $tenantCode, $year, $sequence);
    }

    /**
     * Generate a PR number for the given tenant.
     *
     * @param  Tenant  $tenant
     * @param  int     $year
     * @return string  e.g. PR-ACME-2025-00001
     */
    protected function generatePrNumber(Tenant $tenant, int $year = 0): string
    {
        return $this->generateDocumentNumber('PR', $tenant, $year);
    }

    /**
     * Generate a PO number for the given tenant.
     *
     * @param  Tenant  $tenant
     * @param  int     $year
     * @return string  e.g. PO-ACME-2025-00001
     */
    protected function generatePoNumber(Tenant $tenant, int $year = 0): string
    {
        return $this->generateDocumentNumber('PO', $tenant, $year);
    }
}
