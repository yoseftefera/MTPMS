<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds all 8 system roles and 20 permissions from the RBAC matrix in the design.
 *
 * Roles (8):
 *   System_Admin, Tenant_Admin, Department_Staff, Procurement_Officer,
 *   Finance_Officer, Store_Manager, Committee_Member, Supplier
 *
 * Permissions (20 — from design RBAC matrix, hyphen-notation):
 *   manage-tenants, manage-users, manage-departments,
 *   create-purchase-request, approve-purchase-request,
 *   manage-suppliers, manage-tenders, submit-bid, evaluate-bids,
 *   manage-purchase-orders, accept-purchase-order,
 *   manage-contracts, manage-goods-receipts, inspect-goods,
 *   manage-budgets, manage-invoices, process-payments,
 *   view-reports, view-audit-logs, manage-notifications
 *
 * Additional granular permissions (dot-notation) used by API route guards:
 *   users.view, users.create, users.update, users.delete
 *   departments.view, departments.create, departments.update, departments.delete
 *   purchase_requests.view, purchase_requests.create, purchase_requests.submit, purchase_requests.approve
 *   tenders.view, tenders.create, tenders.publish, tenders.evaluate
 *   purchase_orders.view, purchase_orders.create, purchase_orders.approve
 *   budgets.view, budgets.manage
 *   suppliers.view, suppliers.manage
 *   contracts.view, contracts.manage
 *   invoices.view, invoices.submit, invoices.approve
 *   payments.view, payments.process
 *   reports.view, audit_logs.view, roles.assign
 *
 * Requirements: 3.1
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * The 20 canonical permissions from the design RBAC matrix (hyphen-notation).
     *
     * These match the design document exactly:
     * | Permission               | System_Admin | Tenant_Admin | Dept_Staff | Proc_Officer | Finance_Officer | Store_Manager | Committee_Member | Supplier |
     * |--------------------------|:------------:|:------------:|:----------:|:------------:|:---------------:|:-------------:|:----------------:|:--------:|
     * | manage-tenants           |      ✓       |              |            |              |                 |               |                  |          |
     * | manage-users             |              |      ✓       |            |              |                 |               |                  |          |
     * | manage-departments       |              |      ✓       |            |              |                 |               |                  |          |
     * | create-purchase-request  |              |              |     ✓      |              |                 |               |                  |          |
     * | approve-purchase-request |              |      ✓       |            |      ✓       |        ✓        |               |                  |          |
     * | manage-suppliers         |              |              |            |      ✓       |                 |               |                  |          |
     * | manage-tenders           |              |              |            |      ✓       |                 |               |                  |          |
     * | submit-bid               |              |              |            |              |                 |               |                  |    ✓     |
     * | evaluate-bids            |              |              |            |      ✓       |                 |               |        ✓         |          |
     * | manage-purchase-orders   |              |              |            |      ✓       |                 |               |                  |          |
     * | accept-purchase-order    |              |              |            |              |                 |               |                  |    ✓     |
     * | manage-contracts         |              |              |            |      ✓       |                 |               |                  |          |
     * | manage-goods-receipts    |              |              |            |              |                 |       ✓       |                  |          |
     * | inspect-goods            |              |              |            |              |                 |               |        ✓         |          |
     * | manage-budgets           |              |              |            |              |        ✓        |               |                  |          |
     * | manage-invoices          |              |              |            |              |        ✓        |               |                  |    ✓     |
     * | process-payments         |              |              |            |              |        ✓        |               |                  |          |
     * | view-reports             |              |      ✓       |            |      ✓       |        ✓        |       ✓       |                  |          |
     * | view-audit-logs          |      ✓       |      ✓       |            |              |                 |               |                  |          |
     * | manage-notifications     |              |      ✓       |            |              |                 |               |                  |          |
     */
    private const DESIGN_PERMISSIONS = [
        'manage-tenants',
        'manage-users',
        'manage-departments',
        'create-purchase-request',
        'approve-purchase-request',
        'manage-suppliers',
        'manage-tenders',
        'submit-bid',
        'evaluate-bids',
        'manage-purchase-orders',
        'accept-purchase-order',
        'manage-contracts',
        'manage-goods-receipts',
        'inspect-goods',
        'manage-budgets',
        'manage-invoices',
        'process-payments',
        'view-reports',
        'view-audit-logs',
        'manage-notifications',
    ];

    /**
     * Additional granular permissions (dot-notation) used by API route guards.
     * These provide fine-grained control for individual CRUD operations.
     */
    private const GRANULAR_PERMISSIONS = [
        // User management
        'users.view',
        'users.create',
        'users.update',
        'users.delete',

        // Department management
        'departments.view',
        'departments.create',
        'departments.update',
        'departments.delete',

        // Purchase requests
        'purchase_requests.view',
        'purchase_requests.create',
        'purchase_requests.submit',
        'purchase_requests.approve',

        // Tenders
        'tenders.view',
        'tenders.create',
        'tenders.publish',
        'tenders.evaluate',

        // Purchase orders
        'purchase_orders.view',
        'purchase_orders.create',
        'purchase_orders.approve',

        // Budgets
        'budgets.view',
        'budgets.manage',

        // Suppliers
        'suppliers.view',
        'suppliers.manage',

        // Contracts
        'contracts.view',
        'contracts.manage',

        // Invoices
        'invoices.view',
        'invoices.submit',
        'invoices.approve',

        // Payments
        'payments.view',
        'payments.process',

        // Reports
        'reports.view',

        // Audit logs
        'audit_logs.view',

        // Role assignment
        'roles.assign',

        // Approval workflows
        'workflows.manage',
    ];

    /**
     * RBAC matrix: role → list of permissions it holds.
     *
     * Each role receives both its design-level permissions (hyphen-notation)
     * and the corresponding granular permissions (dot-notation) used by route guards.
     *
     * | Permission                  | Sys_Admin | Tenant_Admin | Dept_Staff | Proc_Officer | Finance_Officer | Store_Manager | Committee_Member | Supplier |
     * |-----------------------------|:---------:|:------------:|:----------:|:------------:|:---------------:|:-------------:|:----------------:|:--------:|
     * | users.view                  |           |      ✓       |            |              |                 |               |                  |          |
     * | users.create                |           |      ✓       |            |              |                 |               |                  |          |
     * | users.update                |           |      ✓       |            |              |                 |               |                  |          |
     * | users.delete                |           |      ✓       |            |              |                 |               |                  |          |
     * | departments.view            |           |      ✓       |     ✓      |      ✓       |        ✓        |       ✓       |                  |          |
     * | departments.create          |           |      ✓       |            |              |                 |               |                  |          |
     * | departments.update          |           |      ✓       |            |              |                 |               |                  |          |
     * | departments.delete          |           |      ✓       |            |              |                 |               |                  |          |
     * | purchase_requests.view      |           |      ✓       |     ✓      |      ✓       |        ✓        |               |                  |          |
     * | purchase_requests.create    |           |              |     ✓      |              |                 |               |                  |          |
     * | purchase_requests.submit    |           |              |     ✓      |              |                 |               |                  |          |
     * | purchase_requests.approve   |           |      ✓       |            |      ✓       |        ✓        |               |                  |          |
     * | tenders.view                |           |      ✓       |            |      ✓       |                 |               |        ✓         |    ✓     |
     * | tenders.create              |           |              |            |      ✓       |                 |               |                  |          |
     * | tenders.publish             |           |              |            |      ✓       |                 |               |                  |          |
     * | tenders.evaluate            |           |              |            |      ✓       |                 |               |        ✓         |          |
     * | purchase_orders.view        |           |      ✓       |            |      ✓       |        ✓        |       ✓       |                  |    ✓     |
     * | purchase_orders.create      |           |              |            |      ✓       |                 |               |                  |          |
     * | purchase_orders.approve     |           |              |            |      ✓       |                 |               |                  |    ✓     |
     * | budgets.view                |           |      ✓       |     ✓      |              |        ✓        |               |                  |          |
     * | budgets.manage              |           |              |            |              |        ✓        |               |                  |          |
     * | suppliers.view              |           |      ✓       |            |      ✓       |                 |               |                  |          |
     * | suppliers.manage            |           |              |            |      ✓       |                 |               |                  |          |
     * | contracts.view              |           |      ✓       |            |      ✓       |        ✓        |               |                  |    ✓     |
     * | contracts.manage            |           |              |            |      ✓       |                 |               |                  |          |
     * | invoices.view               |           |      ✓       |            |              |        ✓        |               |                  |    ✓     |
     * | invoices.submit             |           |              |            |              |                 |               |                  |    ✓     |
     * | invoices.approve            |           |              |            |              |        ✓        |               |                  |          |
     * | payments.view               |           |      ✓       |            |              |        ✓        |               |                  |    ✓     |
     * | payments.process            |           |              |            |              |        ✓        |               |                  |          |
     * | reports.view                |     ✓     |      ✓       |            |      ✓       |        ✓        |       ✓       |                  |          |
     * | audit_logs.view             |     ✓     |      ✓       |            |              |                 |               |                  |          |
     * | roles.assign                |           |      ✓       |            |              |                 |               |                  |          |
     */
    private const ROLE_PERMISSIONS = [
        'System_Admin' => [
            // Design permissions
            'manage-tenants',
            'view-audit-logs',
            // Granular permissions
            'reports.view',
            'audit_logs.view',
        ],
        'Tenant_Admin' => [
            // Design permissions
            'manage-users',
            'manage-departments',
            'approve-purchase-request',
            'view-reports',
            'view-audit-logs',
            'manage-notifications',
            // Granular permissions
            'users.view',
            'users.create',
            'users.update',
            'users.delete',
            'departments.view',
            'departments.create',
            'departments.update',
            'departments.delete',
            'purchase_requests.view',
            'purchase_requests.approve',
            'tenders.view',
            'purchase_orders.view',
            'budgets.view',
            'suppliers.view',
            'contracts.view',
            'invoices.view',
            'payments.view',
            'reports.view',
            'audit_logs.view',
            'roles.assign',
            'workflows.manage',
        ],
        'Department_Staff' => [
            // Design permissions
            'create-purchase-request',
            // Granular permissions
            'departments.view',
            'purchase_requests.view',
            'purchase_requests.create',
            'purchase_requests.submit',
            'budgets.view',
        ],
        'Procurement_Officer' => [
            // Design permissions
            'approve-purchase-request',
            'manage-suppliers',
            'manage-tenders',
            'evaluate-bids',
            'manage-purchase-orders',
            'manage-contracts',
            'view-reports',
            // Granular permissions
            'departments.view',
            'purchase_requests.view',
            'purchase_requests.approve',
            'tenders.view',
            'tenders.create',
            'tenders.publish',
            'tenders.evaluate',
            'purchase_orders.view',
            'purchase_orders.create',
            'purchase_orders.approve',
            'suppliers.view',
            'suppliers.manage',
            'contracts.view',
            'contracts.manage',
            'reports.view',
        ],
        'Finance_Officer' => [
            // Design permissions
            'approve-purchase-request',
            'manage-budgets',
            'manage-invoices',
            'process-payments',
            'view-reports',
            // Granular permissions
            'departments.view',
            'purchase_requests.view',
            'purchase_requests.approve',
            'purchase_orders.view',
            'budgets.view',
            'budgets.manage',
            'contracts.view',
            'invoices.view',
            'invoices.approve',
            'payments.view',
            'payments.process',
            'reports.view',
        ],
        'Store_Manager' => [
            // Design permissions
            'manage-goods-receipts',
            'view-reports',
            // Granular permissions
            'departments.view',
            'purchase_orders.view',
            'reports.view',
        ],
        'Committee_Member' => [
            // Design permissions
            'evaluate-bids',
            'inspect-goods',
            // Granular permissions
            'tenders.view',
            'tenders.evaluate',
        ],
        'Supplier' => [
            // Design permissions
            'submit-bid',
            'accept-purchase-order',
            'manage-invoices',
            // Granular permissions
            'tenders.view',
            'purchase_orders.view',
            'purchase_orders.approve',
            'contracts.view',
            'invoices.view',
            'invoices.submit',
            'payments.view',
        ],
    ];

    public function run(): void
    {
        // Reset cached roles and permissions before seeding
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Create all 20 design permissions (hyphen-notation) — idempotent
        foreach (self::DESIGN_PERMISSIONS as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'api']);
        }

        $this->command->info('Created/verified ' . count(self::DESIGN_PERMISSIONS) . ' design permissions (hyphen-notation).');

        // Create all granular permissions (dot-notation) — idempotent
        foreach (self::GRANULAR_PERMISSIONS as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'api']);
        }

        $this->command->info('Created/verified ' . count(self::GRANULAR_PERMISSIONS) . ' granular permissions (dot-notation).');

        // Create all 8 roles and sync their permissions (idempotent)
        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'api']);
            $role->syncPermissions($permissions);

            $this->command->info("Role [{$roleName}] created/updated with " . count($permissions) . ' permissions.');
        }

        $this->command->info('All 8 roles and 20 design permissions seeded successfully.');
    }
}
