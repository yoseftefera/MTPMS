<?php

namespace Database\Seeders;

use App\Models\Bid;
use App\Models\Budget;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Creates a fully-populated demo tenant with:
 *  - One user per each of the 8 roles
 *  - 5 departments
 *  - Budgets per department
 *  - Sample purchase requests in various statuses
 *  - Active suppliers
 *  - Published tenders with bids
 *  - Purchase orders, contracts, invoices, and payments
 */
class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        // -----------------------------------------------------------------------
        // 1. Create the demo tenant
        // -----------------------------------------------------------------------
        /** @var Tenant $tenant */
        $tenant = Tenant::create([
            'name'        => 'Acme Corporation (Demo)',
            'subdomain'   => 'acme-demo',
            'admin_email' => 'admin@acme-demo.example.com',
            'status'      => Tenant::STATUS_ACTIVE,
            'tenant_code' => 'ACME01',
            'settings'    => [
                'password_min_length'     => 8,
                'session_timeout_minutes' => 60,
                'max_failed_logins'       => 5,
                'approval_workflow_depth' => 5,
            ],
        ]);

        $this->command->info("Demo tenant created: [{$tenant->name}] (ID: {$tenant->id})");

        // -----------------------------------------------------------------------
        // 2. Create departments
        // -----------------------------------------------------------------------
        $departments = $this->createDepartments($tenant);

        // -----------------------------------------------------------------------
        // 3. Create one user per role
        // -----------------------------------------------------------------------
        $users = $this->createUsers($tenant, $departments);

        // -----------------------------------------------------------------------
        // 4. Create budgets for each department
        // -----------------------------------------------------------------------
        $this->createBudgets($tenant, $departments, $users['finance_officer']);

        // -----------------------------------------------------------------------
        // 5. Create purchase requests
        // -----------------------------------------------------------------------
        $this->createPurchaseRequests($tenant, $departments, $users);

        // -----------------------------------------------------------------------
        // 6. Create suppliers
        // -----------------------------------------------------------------------
        $suppliers = $this->createSuppliers($tenant, $users['supplier']);

        // -----------------------------------------------------------------------
        // 7. Create tenders with bids
        // -----------------------------------------------------------------------
        $tenders = $this->createTendersWithBids($tenant, $users['procurement_officer'], $suppliers);

        // -----------------------------------------------------------------------
        // 8. Create purchase orders
        // -----------------------------------------------------------------------
        $purchaseOrders = $this->createPurchaseOrders($tenant, $departments, $suppliers, $users);

        // -----------------------------------------------------------------------
        // 9. Create contracts
        // -----------------------------------------------------------------------
        $contracts = $this->createContracts($tenant, $suppliers, $purchaseOrders, $users['procurement_officer']);

        // -----------------------------------------------------------------------
        // 10. Create invoices and payments
        // -----------------------------------------------------------------------
        $this->createInvoicesAndPayments($tenant, $suppliers, $purchaseOrders, $contracts, $users['finance_officer']);

        $this->command->info('Demo tenant seeded successfully.');
        $this->command->newLine();
        $this->command->info('Demo login credentials:');
        $this->command->table(
            ['Role', 'Email', 'Password'],
            [
                ['System_Admin',       'sysadmin@platform.example.com',          'Password@123'],
                ['Tenant_Admin',       'tenant.admin@acme-demo.example.com',     'Password@123'],
                ['Department_Staff',   'dept.staff@acme-demo.example.com',       'Password@123'],
                ['Procurement_Officer','procurement@acme-demo.example.com',      'Password@123'],
                ['Finance_Officer',    'finance@acme-demo.example.com',          'Password@123'],
                ['Store_Manager',      'store.manager@acme-demo.example.com',    'Password@123'],
                ['Committee_Member',   'committee@acme-demo.example.com',        'Password@123'],
                ['Supplier',           'supplier@acme-demo.example.com',         'Password@123'],
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function createDepartments(Tenant $tenant): array
    {
        $deptData = [
            ['name' => 'Finance & Accounts',    'code' => 'FIN'],
            ['name' => 'Information Technology', 'code' => 'IT'],
            ['name' => 'Operations',             'code' => 'OPS'],
            ['name' => 'Human Resources',        'code' => 'HR'],
            ['name' => 'Procurement',            'code' => 'PROC'],
        ];

        $departments = [];
        foreach ($deptData as $data) {
            $dept = Department::create([
                'tenant_id' => $tenant->id,
                'name'      => $data['name'],
                'code'      => $data['code'],
                'parent_id' => null,
                'status'    => 'active',
            ]);
            $departments[$data['code']] = $dept;
        }

        $this->command->info('Created ' . count($departments) . ' departments.');

        return $departments;
    }

    private function createUsers(Tenant $tenant, array $departments): array
    {
        $password = Hash::make('Password@123');

        $userData = [
            'system_admin' => [
                'name'          => 'System Administrator',
                'email'         => 'sysadmin@platform.example.com',
                'role'          => 'System_Admin',
                'department_id' => null,
                // Note: true System_Admins use the system_admins table.
                // This demo user is stored in the users table under the demo
                // tenant so it can be used for API testing with JWT auth.
            ],
            'tenant_admin' => [
                'name'          => 'Tenant Administrator',
                'email'         => 'tenant.admin@acme-demo.example.com',
                'role'          => 'Tenant_Admin',
                'department_id' => $departments['FIN']->id,
            ],
            'dept_staff' => [
                'name'          => 'Alice Mwangi',
                'email'         => 'dept.staff@acme-demo.example.com',
                'role'          => 'Department_Staff',
                'department_id' => $departments['IT']->id,
            ],
            'procurement_officer' => [
                'name'          => 'Bob Kamau',
                'email'         => 'procurement@acme-demo.example.com',
                'role'          => 'Procurement_Officer',
                'department_id' => $departments['PROC']->id,
            ],
            'finance_officer' => [
                'name'          => 'Carol Njeri',
                'email'         => 'finance@acme-demo.example.com',
                'role'          => 'Finance_Officer',
                'department_id' => $departments['FIN']->id,
            ],
            'store_manager' => [
                'name'          => 'David Ochieng',
                'email'         => 'store.manager@acme-demo.example.com',
                'role'          => 'Store_Manager',
                'department_id' => $departments['OPS']->id,
            ],
            'committee_member' => [
                'name'          => 'Eve Wanjiku',
                'email'         => 'committee@acme-demo.example.com',
                'role'          => 'Committee_Member',
                'department_id' => $departments['PROC']->id,
            ],
            'supplier' => [
                'name'          => 'Frank Otieno',
                'email'         => 'supplier@acme-demo.example.com',
                'role'          => 'Supplier',
                'department_id' => null,
            ],
        ];

        $users = [];
        foreach ($userData as $key => $data) {
            $user = User::create([
                'tenant_id'         => $tenant->id,
                'name'              => $data['name'],
                'email'             => $data['email'],
                'password'          => $password,
                'department_id'     => $data['department_id'],
                'status'            => 'active',
                'email_verified_at' => now(),
            ]);

            // Assign Spatie role
            $role = Role::where('name', $data['role'])->where('guard_name', 'api')->first();
            if ($role) {
                $user->assignRole($role);
            }

            $users[$key] = $user;
        }

        $this->command->info('Created ' . count($users) . ' demo users (one per role).');

        return $users;
    }

    private function createBudgets(Tenant $tenant, array $departments, User $createdBy): void
    {
        $budgetData = [
            'FIN'  => ['total' => 500000.00,  'spent' => 120000.00, 'encumbered' => 50000.00],
            'IT'   => ['total' => 1200000.00, 'spent' => 450000.00, 'encumbered' => 200000.00],
            'OPS'  => ['total' => 800000.00,  'spent' => 310000.00, 'encumbered' => 80000.00],
            'HR'   => ['total' => 300000.00,  'spent' => 95000.00,  'encumbered' => 30000.00],
            'PROC' => ['total' => 600000.00,  'spent' => 180000.00, 'encumbered' => 120000.00],
        ];

        foreach ($budgetData as $code => $amounts) {
            Budget::create([
                'tenant_id'         => $tenant->id,
                'department_id'     => $departments[$code]->id,
                'fiscal_year'       => now()->year,
                'currency'          => 'USD',
                'total_amount'      => $amounts['total'],
                'encumbered_amount' => $amounts['encumbered'],
                'spent_amount'      => $amounts['spent'],
                'created_by'        => $createdBy->id,
            ]);
        }

        $this->command->info('Created ' . count($budgetData) . ' department budgets.');
    }

    private function createPurchaseRequests(Tenant $tenant, array $departments, array $users): void
    {
        $prData = [
            [
                'pr_number'       => 'PR-ACME01-' . now()->year . '-00001',
                'department_id'   => $departments['IT']->id,
                'submitted_by'    => $users['dept_staff']->id,
                'status'          => 'approved',
                'title'           => 'Laptop Computers for Development Team',
                'description'     => 'Purchase of 10 high-performance laptops for the software development team.',
                'estimated_total' => 25000.00,
                'required_date'   => now()->addDays(30)->toDateString(),
                'submitted_at'    => now()->subDays(15),
            ],
            [
                'pr_number'       => 'PR-ACME01-' . now()->year . '-00002',
                'department_id'   => $departments['OPS']->id,
                'submitted_by'    => $users['dept_staff']->id,
                'status'          => 'pending_approval',
                'title'           => 'Office Furniture Replacement',
                'description'     => 'Replacement of worn-out office chairs and desks in the operations wing.',
                'estimated_total' => 8500.00,
                'required_date'   => now()->addDays(45)->toDateString(),
                'submitted_at'    => now()->subDays(3),
            ],
            [
                'pr_number'       => 'PR-ACME01-' . now()->year . '-00003',
                'department_id'   => $departments['FIN']->id,
                'submitted_by'    => $users['dept_staff']->id,
                'status'          => 'draft',
                'title'           => 'Accounting Software Licenses',
                'description'     => 'Annual renewal of accounting software licenses for the finance team.',
                'estimated_total' => 12000.00,
                'required_date'   => now()->addDays(60)->toDateString(),
                'submitted_at'    => null,
            ],
            [
                'pr_number'       => 'PR-ACME01-' . now()->year . '-00004',
                'department_id'   => $departments['HR']->id,
                'submitted_by'    => $users['dept_staff']->id,
                'status'          => 'rejected',
                'title'           => 'Team Building Event Supplies',
                'description'     => 'Supplies and catering for the annual team building event.',
                'estimated_total' => 5000.00,
                'required_date'   => now()->addDays(20)->toDateString(),
                'submitted_at'    => now()->subDays(10),
            ],
        ];

        foreach ($prData as $data) {
            PurchaseRequest::create(array_merge($data, ['tenant_id' => $tenant->id, 'currency' => 'USD']));
        }

        $this->command->info('Created ' . count($prData) . ' purchase requests.');
    }

    private function createSuppliers(Tenant $tenant, User $supplierUser): array
    {
        $supplierData = [
            [
                'organization_name'       => 'TechPro Solutions Ltd',
                'contact_name'            => 'Frank Otieno',
                'contact_email'           => 'supplier@acme-demo.example.com',
                'contact_phone'           => '+254 700 123 456',
                'business_category'       => 'IT Equipment',
                'status'                  => 'active',
                'on_time_delivery_rate'   => 94.50,
                'quality_acceptance_rate' => 97.20,
                'user_id'                 => $supplierUser->id,
            ],
            [
                'organization_name'       => 'Office World Kenya',
                'contact_name'            => 'Grace Akinyi',
                'contact_email'           => 'grace@officeworld.example.com',
                'contact_phone'           => '+254 722 987 654',
                'business_category'       => 'Office Supplies',
                'status'                  => 'active',
                'on_time_delivery_rate'   => 88.00,
                'quality_acceptance_rate' => 92.50,
                'user_id'                 => null,
            ],
            [
                'organization_name'       => 'BuildRight Contractors',
                'contact_name'            => 'Henry Mwenda',
                'contact_email'           => 'henry@buildright.example.com',
                'contact_phone'           => '+254 733 456 789',
                'business_category'       => 'Construction Materials',
                'status'                  => 'active',
                'on_time_delivery_rate'   => 79.00,
                'quality_acceptance_rate' => 85.00,
                'user_id'                 => null,
            ],
            [
                'organization_name'       => 'FastTrack Logistics',
                'contact_name'            => 'Irene Chebet',
                'contact_email'           => 'irene@fasttrack.example.com',
                'contact_phone'           => '+254 711 234 567',
                'business_category'       => 'Transportation',
                'status'                  => 'pending_verification',
                'on_time_delivery_rate'   => 0.00,
                'quality_acceptance_rate' => 0.00,
                'user_id'                 => null,
            ],
        ];

        $suppliers = [];
        foreach ($supplierData as $data) {
            $suppliers[] = Supplier::create(array_merge($data, ['tenant_id' => $tenant->id]));
        }

        $this->command->info('Created ' . count($suppliers) . ' suppliers.');

        return $suppliers;
    }

    private function createTendersWithBids(Tenant $tenant, User $createdBy, array $suppliers): array
    {
        $tenderData = [
            [
                'reference_number'    => 'TND-' . now()->year . '-00001',
                'title'               => 'Supply of IT Equipment — Laptops and Peripherals',
                'description'         => 'Open tender for the supply of 20 high-performance laptops, monitors, keyboards, and mice for the IT department.',
                'category'            => 'IT Equipment',
                'tender_type'         => 'open',
                'estimated_value'     => 60000.00,
                'submission_deadline' => now()->addDays(14),
                'status'              => 'published',
                'published_at'        => now()->subDays(7),
            ],
            [
                'reference_number'    => 'TND-' . now()->year . '-00002',
                'title'               => 'Office Furniture and Fittings',
                'description'         => 'Restricted tender for supply and installation of ergonomic office furniture.',
                'category'            => 'Office Supplies',
                'tender_type'         => 'restricted',
                'estimated_value'     => 35000.00,
                'submission_deadline' => now()->subDays(5),
                'status'              => 'closed',
                'published_at'        => now()->subDays(21),
            ],
        ];

        $tenders = [];
        foreach ($tenderData as $data) {
            $tender = Tender::create(array_merge($data, [
                'tenant_id'  => $tenant->id,
                'created_by' => $createdBy->id,
            ]));
            $tenders[] = $tender;
        }

        // Create bids for the closed tender
        $closedTender = $tenders[1];
        $activeSuppliersForBid = array_filter($suppliers, fn ($s) => $s->status === 'active');

        foreach (array_slice($activeSuppliersForBid, 0, 2) as $supplier) {
            Bid::create([
                'tenant_id'      => $tenant->id,
                'tender_id'      => $closedTender->id,
                'supplier_id'    => $supplier->id,
                'total_amount'   => fake()->randomFloat(2, 28000, 40000),
                'currency'       => 'USD',
                'delivery_days'  => fake()->numberBetween(14, 45),
                'technical_notes' => 'We confirm full compliance with all technical specifications.',
                'status'         => 'under_evaluation',
                'submitted_at'   => now()->subDays(6),
                'weighted_score' => null,
            ]);
        }

        $this->command->info('Created ' . count($tenders) . ' tenders with bids.');

        return $tenders;
    }

    private function createPurchaseOrders(Tenant $tenant, array $departments, array $suppliers, array $users): array
    {
        $activeSupplier = $suppliers[0]; // TechPro Solutions

        $poData = [
            [
                'po_number'              => 'PO-ACME01-' . now()->year . '-00001',
                'supplier_id'            => $activeSupplier->id,
                'department_id'          => $departments['IT']->id,
                'status'                 => 'accepted',
                'total_amount'           => 25000.00,
                'currency'               => 'USD',
                'delivery_address'       => '123 Corporate Park, Nairobi, Kenya',
                'required_delivery_date' => now()->addDays(21)->toDateString(),
                'issued_at'              => now()->subDays(10),
                'accepted_at'            => now()->subDays(8),
                'created_by'             => $users['procurement_officer']->id,
            ],
            [
                'po_number'              => 'PO-ACME01-' . now()->year . '-00002',
                'supplier_id'            => $suppliers[1]->id, // Office World
                'department_id'          => $departments['OPS']->id,
                'status'                 => 'issued',
                'total_amount'           => 8500.00,
                'currency'               => 'USD',
                'delivery_address'       => '123 Corporate Park, Nairobi, Kenya',
                'required_delivery_date' => now()->addDays(14)->toDateString(),
                'issued_at'              => now()->subDays(2),
                'accepted_at'            => null,
                'created_by'             => $users['procurement_officer']->id,
            ],
        ];

        $purchaseOrders = [];
        foreach ($poData as $data) {
            $purchaseOrders[] = PurchaseOrder::create(array_merge($data, ['tenant_id' => $tenant->id]));
        }

        $this->command->info('Created ' . count($purchaseOrders) . ' purchase orders.');

        return $purchaseOrders;
    }

    private function createContracts(Tenant $tenant, array $suppliers, array $purchaseOrders, User $createdBy): array
    {
        $contractData = [
            [
                'contract_number'   => 'CTR-' . now()->year . '-00001',
                'purchase_order_id' => $purchaseOrders[0]->id,
                'tender_id'         => null,
                'supplier_id'       => $suppliers[0]->id,
                'title'             => 'IT Equipment Supply Agreement',
                'scope'             => 'Supply of 20 laptops and peripherals as per PO-ACME01-' . now()->year . '-00001.',
                'total_value'       => 25000.00,
                'consumed_value'    => 0.00,
                'currency'          => 'USD',
                'start_date'        => now()->subDays(8)->toDateString(),
                'end_date'          => now()->addMonths(6)->toDateString(),
                'payment_terms'     => 'Net 30 days after delivery and acceptance',
                'status'            => 'active',
                'created_by'        => $createdBy->id,
            ],
        ];

        $contracts = [];
        foreach ($contractData as $data) {
            $contracts[] = Contract::create(array_merge($data, ['tenant_id' => $tenant->id]));
        }

        $this->command->info('Created ' . count($contracts) . ' contracts.');

        return $contracts;
    }

    private function createInvoicesAndPayments(
        Tenant $tenant,
        array $suppliers,
        array $purchaseOrders,
        array $contracts,
        User $processedBy
    ): void {
        // Invoice against the accepted PO
        $invoice = Invoice::create([
            'tenant_id'         => $tenant->id,
            'invoice_number'    => 'INV-' . now()->year . '-000001',
            'supplier_id'       => $suppliers[0]->id,
            'purchase_order_id' => $purchaseOrders[0]->id,
            'contract_id'       => $contracts[0]->id,
            'total_amount'      => 25000.00,
            'paid_amount'       => 12500.00,
            'currency'          => 'USD',
            'invoice_date'      => now()->subDays(5)->toDateString(),
            'due_date'          => now()->addDays(25)->toDateString(),
            'status'            => 'partially_paid',
            'submitted_at'      => now()->subDays(5),
        ]);

        // Partial payment against the invoice
        Payment::create([
            'tenant_id'         => $tenant->id,
            'invoice_id'        => $invoice->id,
            'amount'            => 12500.00,
            'currency'          => 'USD',
            'payment_method'    => 'Bank Transfer',
            'payment_reference' => 'PAY-2024-001234',
            'payment_date'      => now()->subDays(2)->toDateString(),
            'due_date'          => now()->addDays(25)->toDateString(),
            'status'            => 'processed',
            'processed_by'      => $processedBy->id,
            'notes'             => 'First instalment — 50% of invoice value.',
        ]);

        // Scheduled second payment
        Payment::create([
            'tenant_id'         => $tenant->id,
            'invoice_id'        => $invoice->id,
            'amount'            => 12500.00,
            'currency'          => 'USD',
            'payment_method'    => 'Bank Transfer',
            'payment_reference' => 'PAY-2024-001235',
            'payment_date'      => now()->addDays(25)->toDateString(),
            'due_date'          => now()->addDays(25)->toDateString(),
            'status'            => 'scheduled',
            'processed_by'      => null,
            'notes'             => 'Second instalment — remaining 50%.',
        ]);

        $this->command->info('Created 1 invoice with 2 payment records.');
    }
}
