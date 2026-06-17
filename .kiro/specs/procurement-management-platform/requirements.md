# Requirements Document

## Introduction

The Procurement Management Platform (PMP) is a production-ready, enterprise-grade, multi-tenant SaaS application that automates the full procurement lifecycle for multiple independent organizations sharing a common infrastructure. Each tenant operates in complete data isolation, managing its own users, departments, budgets, suppliers, workflows, and reports.

The platform covers the end-to-end procurement process: purchase request creation, multi-level approval workflows, supplier management, tender and bidding, bid evaluation, purchase order management, contract lifecycle management, goods receiving, budget tracking, invoice and payment processing, reporting and analytics, real-time notifications, and immutable audit logging.

The system is built on a Laravel 12 backend with a Next.js 15 frontend and a Flutter mobile application, backed by MySQL, Redis, and deployed via Docker with CI/CD pipelines.

---

## Glossary

- **Platform**: The Procurement Management Platform as a whole system.
- **Tenant**: An independent organization registered on the Platform with its own isolated data, users, and configuration.
- **System_Admin**: A super-administrator with cross-tenant access who manages the Platform infrastructure and tenant lifecycle.
- **Tenant_Admin**: An administrator scoped to a single Tenant who manages that Tenant's users, roles, departments, and policies.
- **Department_Staff**: A Tenant user who creates purchase requests on behalf of a department.
- **Procurement_Officer**: A Tenant user responsible for managing tenders, purchase orders, and supplier interactions.
- **Finance_Officer**: A Tenant user responsible for invoice verification, payment approval, and budget management.
- **Store_Manager**: A Tenant user responsible for goods receiving, inventory management, and warehouse operations.
- **Committee_Member**: A Tenant user who participates in bid evaluation and goods inspection committees.
- **Supplier**: An external entity registered on the Platform to submit bids, receive purchase orders, and submit invoices.
- **Purchase_Request (PR)**: A formal internal request to procure goods or services, identified by a unique PR number.
- **Approval_Workflow**: A configurable, multi-level chain of approvers that a PR or other document must pass through.
- **Tender**: A formal solicitation published to Suppliers inviting bids for goods or services.
- **Bid**: A Supplier's formal response to a Tender, including pricing and supporting documents.
- **Bid_Evaluation**: The structured process of scoring and comparing Bids using a weighted criteria system.
- **Purchase_Order (PO)**: A legally binding document issued to a Supplier authorizing the supply of goods or services.
- **Contract**: A formal agreement between the Tenant and a Supplier governing the terms of supply.
- **Goods_Receipt**: The formal record of goods delivered and inspected against a PO.
- **Invoice**: A Supplier's payment request submitted against a PO or Contract.
- **Budget**: An annual financial allocation assigned to a Tenant or Department for procurement spending.
- **Audit_Log**: An immutable, timestamped record of every action performed on the Platform.
- **RBAC**: Role-Based Access Control — the permission model governing what each role can do.
- **JWT**: JSON Web Token — the authentication token format used by the API.
- **API**: The versioned RESTful HTTP interface exposed at `/api/v1`.
- **Queue**: Laravel's asynchronous job processing system backed by Redis.
- **Notification**: An in-app or email alert sent to users about relevant procurement events.
- **UUID**: Universally Unique Identifier — used as the primary key for all major entities.

---

## Requirements

---

### Requirement 1: Multi-Tenant Architecture

**User Story:** As a System_Admin, I want the Platform to enforce strict tenant-level data isolation, so that no Tenant can access another Tenant's data under any circumstances.

#### Acceptance Criteria

1. THE Platform SHALL resolve the active Tenant on every inbound request using a middleware that reads a tenant identifier from the request context (subdomain, header, or JWT claim).
2. WHEN a database query is executed, THE Platform SHALL automatically scope all queries to the active Tenant's identifier, preventing cross-tenant data access.
3. IF a request arrives without a resolvable Tenant identifier, THEN THE Platform SHALL reject the request with HTTP 401 and log the attempt.
4. THE Platform SHALL store each Tenant's data in logically isolated partitions within the shared database schema, enforced by a `tenant_id` foreign key on all tenant-scoped tables.
5. WHEN a Tenant is suspended, THE Platform SHALL deny all authentication and API requests for that Tenant's users while preserving all Tenant data.
6. THE Platform SHALL support registering a new Tenant with a unique subdomain, organization name, and administrator email.
7. WHEN a Tenant is registered, THE Platform SHALL provision default roles, permissions, and configuration for that Tenant automatically.
8. THE System_Admin SHALL be able to view aggregated analytics across all Tenants without exposing individual Tenant data to other Tenants.
9. THE Platform SHALL enforce a configurable per-Tenant policy set including password rules, session timeout, and approval workflow depth.
10. WHEN a Tenant's status changes (active, suspended, deactivated), THE Platform SHALL record the change in the Audit_Log with the System_Admin's identity and timestamp.

---

### Requirement 2: Authentication and Session Security

**User Story:** As a user of any role, I want to authenticate securely and have my session protected, so that unauthorized parties cannot access my account or Tenant data.

#### Acceptance Criteria

1. WHEN a user submits valid credentials (email and password) for an active Tenant, THE Authentication_Service SHALL issue a signed JWT with the user's identity, role, and tenant_id embedded as claims.
2. WHEN a user submits invalid credentials, THE Authentication_Service SHALL increment a failed-attempt counter for that account.
3. IF a user's failed-attempt counter reaches the configured threshold (default: 5), THEN THE Authentication_Service SHALL lock the account and send a password-reset email to the registered address.
4. WHILE a user's session JWT is valid, THE Platform SHALL enforce the session timeout period configured for the Tenant; a JWT older than the timeout SHALL be rejected with HTTP 401.
5. WHEN a user requests a password reset, THE Authentication_Service SHALL send a time-limited (60-minute) reset link to the user's registered email address.
6. IF a password-reset link has expired, THEN THE Authentication_Service SHALL reject the reset attempt and prompt the user to request a new link.
7. THE Platform SHALL protect all state-changing API endpoints with CSRF tokens for browser-based clients.
8. THE Platform SHALL enforce rate limiting of 60 requests per minute per IP address on authentication endpoints, returning HTTP 429 when exceeded.
9. WHEN a user logs out, THE Authentication_Service SHALL invalidate the user's JWT and record the logout event in the Audit_Log.
10. THE Platform SHALL validate and sanitize all user-supplied input on both client and server to prevent injection attacks.
11. WHEN a file is uploaded, THE Platform SHALL validate the file's MIME type, size (maximum 10 MB per file), and scan for disallowed content before storing it.

---

### Requirement 3: Role-Based Access Control (RBAC)

**User Story:** As a Tenant_Admin, I want to assign roles and permissions to users, so that each user can only perform actions appropriate to their role.

#### Acceptance Criteria

1. THE Platform SHALL define eight system roles: System_Admin, Tenant_Admin, Department_Staff, Procurement_Officer, Finance_Officer, Store_Manager, Committee_Member, and Supplier.
2. THE Platform SHALL enforce permission checks on every API endpoint using role-guard middleware, returning HTTP 403 when a user lacks the required permission.
3. WHEN a Tenant_Admin assigns a role to a user, THE RBAC_Service SHALL immediately apply the new permissions to that user's subsequent requests.
4. THE Tenant_Admin SHALL be able to create custom permission sets and assign them to roles within the Tenant's scope.
5. THE Platform SHALL prevent a user from being assigned the System_Admin role through the Tenant-facing interface.
6. WHEN a user's role is changed or revoked, THE RBAC_Service SHALL invalidate any cached permission sets for that user within 5 seconds.
7. THE Platform SHALL provide each role with a dedicated dashboard view showing only the modules and KPIs relevant to that role.
8. IF a user attempts to access a module not permitted by their role, THEN THE Platform SHALL redirect the user to their role-specific dashboard and display an access-denied message.
9. THE Platform SHALL log every permission assignment and revocation in the Audit_Log with the Tenant_Admin's identity and timestamp.

---

### Requirement 4: User and Department Management

**User Story:** As a Tenant_Admin, I want to manage users and departments within my organization, so that the Platform reflects my organizational structure accurately.

#### Acceptance Criteria

1. THE Tenant_Admin SHALL be able to create, read, update, and deactivate user accounts within the Tenant.
2. WHEN a new user is created, THE User_Management_Service SHALL send a welcome email containing a one-time password-setup link valid for 24 hours.
3. THE Tenant_Admin SHALL be able to create, rename, and deactivate departments within the Tenant.
4. WHEN a department is deactivated, THE Platform SHALL prevent new Purchase_Requests from being submitted under that department while preserving all historical records.
5. THE Platform SHALL allow a user to be assigned to exactly one primary department and optionally to additional departments for cross-departmental visibility.
6. THE Tenant_Admin SHALL be able to view a paginated, searchable, and sortable list of all users and their assigned roles within the Tenant.
7. WHEN a user updates their profile (name, phone, avatar), THE User_Management_Service SHALL validate the input and persist the changes immediately.
8. THE Platform SHALL enforce unique email addresses per Tenant for user accounts.
9. IF a Tenant_Admin attempts to delete a user who has active PRs or POs, THEN THE Platform SHALL reject the deletion and display the count of active records linked to that user.

---

### Requirement 5: Purchase Request Management

**User Story:** As a Department_Staff, I want to create and track purchase requests, so that my department's procurement needs are formally documented and processed.

#### Acceptance Criteria

1. WHEN a Department_Staff submits a Purchase_Request, THE PR_Service SHALL generate a unique PR number in the format `PR-{TENANT_CODE}-{YEAR}-{SEQUENCE}` and persist the PR with status `draft`.
2. THE PR_Service SHALL allow a PR to contain one or more line items, each with a description, quantity, unit of measure, estimated unit price, and budget code.
3. WHEN a Department_Staff submits a PR for approval, THE PR_Service SHALL validate that the total estimated value does not exceed the submitting department's available budget balance.
4. IF the total estimated PR value exceeds the department's available budget balance, THEN THE PR_Service SHALL reject the submission and display the available balance and the shortfall amount.
5. WHILE a PR is in `draft` status, THE PR_Service SHALL allow the submitting Department_Staff to edit, add, or remove line items.
6. WHEN a PR is submitted for approval, THE PR_Service SHALL transition the PR status to `pending_approval` and trigger the configured Approval_Workflow for that department.
7. THE PR_Service SHALL maintain a full history of all status transitions, edits, and comments on each PR, accessible to authorized users.
8. THE Platform SHALL allow authorized users to search PRs by PR number, department, status, date range, and submitter name.
9. WHEN a PR is approved, rejected, or returned for revision, THE PR_Service SHALL notify the submitting Department_Staff via in-app and email Notification.
10. THE PR_Service SHALL support attaching supporting documents (PDF, DOCX, XLSX, images) to a PR, subject to the file upload constraints in Requirement 2.

---

### Requirement 6: Multi-Level Approval Workflow

**User Story:** As a Tenant_Admin, I want to configure dynamic multi-level approval chains, so that procurement documents are reviewed and authorized by the correct stakeholders before proceeding.

#### Acceptance Criteria

1. THE Approval_Workflow_Service SHALL support configurable approval chains of 1 to 10 sequential levels, where each level specifies one or more approvers by role or by named user.
2. WHEN a document enters an approval level, THE Approval_Workflow_Service SHALL notify all designated approvers for that level via in-app and email Notification.
3. WHEN an approver approves a document at the current level, THE Approval_Workflow_Service SHALL advance the document to the next level or mark it `approved` if no further levels remain.
4. WHEN an approver rejects a document, THE Approval_Workflow_Service SHALL transition the document to `rejected` status and notify the originator with the rejection reason.
5. WHEN an approver returns a document for revision, THE Approval_Workflow_Service SHALL transition the document to `revision_required` status and notify the originator with the revision comments.
6. WHILE a document is awaiting approval at a given level, THE Approval_Workflow_Service SHALL enforce that only designated approvers for that level can act on it.
7. THE Approval_Workflow_Service SHALL record every approval action (approve, reject, return) with the approver's identity, timestamp, and comment in the Audit_Log.
8. THE Tenant_Admin SHALL be able to configure separate approval chains for Purchase_Requests, Tenders, Purchase_Orders, Contracts, and Invoices.
9. WHEN an approval action is pending for more than the configured escalation period (default: 48 hours), THE Approval_Workflow_Service SHALL send an escalation Notification to the approver's supervisor.
10. THE Approval_Workflow_Service SHALL support parallel approval at a single level, where all designated approvers must approve before the document advances.

---

### Requirement 7: Supplier Management

**User Story:** As a Procurement_Officer, I want to manage supplier registrations and track supplier performance, so that the organization works only with qualified and reliable suppliers.

#### Acceptance Criteria

1. THE Supplier_Management_Service SHALL allow Suppliers to self-register by submitting organization name, contact details, business category, and required compliance documents (TIN certificate, VAT certificate, business license).
2. WHEN a Supplier submits a registration, THE Supplier_Management_Service SHALL set the Supplier status to `pending_verification` and notify the Procurement_Officer.
3. WHEN a Procurement_Officer approves a Supplier registration, THE Supplier_Management_Service SHALL set the Supplier status to `active` and send a confirmation email to the Supplier.
4. THE Procurement_Officer SHALL be able to blacklist a Supplier by providing a documented reason; a blacklisted Supplier SHALL be excluded from all future Tender invitations.
5. WHEN a Supplier is blacklisted, THE Supplier_Management_Service SHALL record the action, reason, and Procurement_Officer identity in the Audit_Log.
6. THE Platform SHALL track Supplier performance metrics including on-time delivery rate, quality acceptance rate, and average bid competitiveness, updated after each completed transaction.
7. THE Procurement_Officer SHALL be able to view a Supplier's full transaction history including Bids, Purchase_Orders, Contracts, and Invoices within the Tenant.
8. WHEN a Supplier's compliance document approaches its expiry date (30 days prior), THE Supplier_Management_Service SHALL send a renewal reminder Notification to the Supplier and the Procurement_Officer.
9. THE Platform SHALL enforce that only `active` Suppliers can submit Bids or receive Purchase_Orders.
10. THE Supplier_Management_Service SHALL support uploading and versioning of Supplier compliance documents, retaining all historical versions.

---

### Requirement 8: Tender and Bidding Management

**User Story:** As a Procurement_Officer, I want to create and publish tenders and manage the bidding process, so that the organization obtains competitive offers from qualified suppliers.

#### Acceptance Criteria

1. THE Tender_Service SHALL allow a Procurement_Officer to create a Tender with a title, description, category, estimated value, submission deadline, and required bid documents list.
2. WHEN a Tender is published, THE Tender_Service SHALL notify all `active` Suppliers in the relevant category via email and in-app Notification.
3. THE Tender_Service SHALL allow a Procurement_Officer to attach tender documents (specifications, drawings, terms) to a Tender before publication.
4. WHEN a Supplier submits a Bid, THE Tender_Service SHALL validate that the submission timestamp is before the Tender's deadline; Bids submitted after the deadline SHALL be rejected with an appropriate error message.
5. WHILE a Tender is in `open` status, THE Tender_Service SHALL allow each eligible Supplier to submit exactly one Bid per Tender, with the ability to revise the Bid before the deadline.
6. WHEN the Tender deadline passes, THE Tender_Service SHALL automatically transition the Tender status to `closed` and lock all Bid submissions.
7. THE Tender_Service SHALL prevent Suppliers from viewing other Suppliers' Bids at any time.
8. THE Procurement_Officer SHALL be able to extend a Tender deadline before the original deadline, with the extension recorded in the Audit_Log.
9. WHEN a Tender is cancelled, THE Tender_Service SHALL notify all Suppliers who submitted Bids and record the cancellation reason in the Audit_Log.
10. THE Tender_Service SHALL support open, restricted (invited Suppliers only), and single-source Tender types.

---

### Requirement 9: Bid Evaluation System

**User Story:** As a Committee_Member, I want to evaluate bids using a structured weighted scoring system, so that the winning supplier is selected transparently and objectively.

#### Acceptance Criteria

1. THE Bid_Evaluation_Service SHALL support a configurable weighted scoring model where the Procurement_Officer defines evaluation criteria (e.g., price, technical compliance, delivery time) and assigns a percentage weight to each criterion, with all weights summing to 100.
2. WHEN a Committee_Member scores a Bid on a criterion, THE Bid_Evaluation_Service SHALL record the score, the Committee_Member's identity, and the timestamp.
3. THE Bid_Evaluation_Service SHALL calculate each Bid's weighted total score as the sum of (criterion score × criterion weight / 100) across all criteria.
4. WHEN all Committee_Members have submitted scores for all Bids, THE Bid_Evaluation_Service SHALL generate a ranked comparison report showing all Bids ordered by weighted total score.
5. THE Bid_Evaluation_Service SHALL allow the Procurement_Officer to select the winning Bid from the ranked list, with a mandatory justification comment.
6. WHEN a winning Bid is selected, THE Bid_Evaluation_Service SHALL notify the winning Supplier and all non-winning Suppliers of the outcome.
7. THE Bid_Evaluation_Service SHALL record all scoring actions, the final ranking, and the winner selection in the Audit_Log.
8. THE Platform SHALL prevent a Committee_Member from viewing other Committee_Members' scores until all members have submitted their scores for a given Bid.
9. IF a Committee_Member attempts to modify a submitted score after the evaluation is finalized, THEN THE Bid_Evaluation_Service SHALL reject the modification and log the attempt.
10. THE Bid_Evaluation_Service SHALL support price-only evaluation as a simplified mode for low-value procurements.

---

### Requirement 10: Purchase Order Management

**User Story:** As a Procurement_Officer, I want to generate and track purchase orders, so that suppliers receive formal authorization and delivery obligations are monitored.

#### Acceptance Criteria

1. WHEN a winning Bid is confirmed or a direct procurement is approved, THE PO_Service SHALL generate a Purchase_Order with a unique PO number in the format `PO-{TENANT_CODE}-{YEAR}-{SEQUENCE}`.
2. THE PO_Service SHALL populate the Purchase_Order with the Supplier details, line items, agreed prices, delivery address, and required delivery date from the source PR and Bid.
3. WHEN a Purchase_Order is issued, THE PO_Service SHALL send the PO document to the Supplier via email and make it available in the Supplier's portal.
4. WHEN a Supplier accepts a Purchase_Order, THE PO_Service SHALL transition the PO status to `accepted` and notify the Procurement_Officer.
5. WHEN a Supplier rejects a Purchase_Order, THE PO_Service SHALL transition the PO status to `rejected`, notify the Procurement_Officer with the rejection reason, and allow the Procurement_Officer to issue a revised PO or select an alternative Supplier.
6. THE PO_Service SHALL track delivery milestones against the PO's required delivery date and send reminder Notifications to the Supplier 7 days and 1 day before the due date.
7. WHEN a PO's required delivery date passes without a confirmed Goods_Receipt, THE PO_Service SHALL flag the PO as `overdue` and notify the Procurement_Officer.
8. THE PO_Service SHALL support partial deliveries, allowing multiple Goods_Receipts against a single PO until the full quantity is received.
9. THE Procurement_Officer SHALL be able to amend a PO (quantity, price, delivery date) before the Supplier accepts it; amendments after acceptance require Supplier acknowledgment.
10. THE PO_Service SHALL record all PO status transitions and amendments in the Audit_Log.

---

### Requirement 11: Contract Lifecycle Management

**User Story:** As a Procurement_Officer, I want to manage contracts from creation through renewal or termination, so that all supplier agreements are formally documented and monitored.

#### Acceptance Criteria

1. THE Contract_Service SHALL allow a Procurement_Officer to generate a Contract document linked to a Purchase_Order or Tender, capturing parties, scope, value, start date, end date, and payment terms.
2. THE Contract_Service SHALL support uploading a signed Contract document (PDF) and associating it with the Contract record.
3. WHEN a Contract's end date is 60 days away, THE Contract_Service SHALL send a renewal alert Notification to the Procurement_Officer and Tenant_Admin.
4. WHEN a Contract's end date is 30 days away and no renewal action has been taken, THE Contract_Service SHALL send a second escalation Notification.
5. THE Contract_Service SHALL maintain a full version history of all Contract amendments, retaining the original and each amended version.
6. WHEN a Contract is amended, THE Contract_Service SHALL require a documented reason and record the amendment in the Audit_Log with the Procurement_Officer's identity and timestamp.
7. THE Contract_Service SHALL enforce that a Contract requires a performance bond document upload before the Contract status can be set to `active`.
8. IF a performance bond document is not uploaded, THEN THE Contract_Service SHALL prevent the Contract from transitioning to `active` status and display a descriptive error.
9. THE Contract_Service SHALL track Contract value consumption against the total Contract value and alert the Procurement_Officer when consumption reaches 80% of the Contract value.
10. THE Procurement_Officer SHALL be able to terminate a Contract early by providing a termination reason, which is recorded in the Audit_Log.

---

### Requirement 12: Inventory and Goods Receiving

**User Story:** As a Store_Manager, I want to record and inspect received goods against purchase orders, so that inventory is accurately maintained and only quality-approved goods are accepted.

#### Acceptance Criteria

1. WHEN a Supplier delivers goods against a Purchase_Order, THE Goods_Receipt_Service SHALL allow the Store_Manager to create a Goods_Receipt record referencing the PO number, delivery note number, and received quantities per line item.
2. THE Goods_Receipt_Service SHALL support a committee inspection workflow where designated Committee_Members review received goods and record quality acceptance or rejection per line item.
3. WHEN all Committee_Members have submitted their inspection results, THE Goods_Receipt_Service SHALL calculate the accepted quantity per line item and update the inventory accordingly.
4. IF a line item is rejected during inspection, THEN THE Goods_Receipt_Service SHALL record the rejection reason and notify the Procurement_Officer and Supplier.
5. WHEN a Goods_Receipt is fully accepted, THE Goods_Receipt_Service SHALL update the corresponding PO's received quantity and transition the PO to `partially_received` or `fully_received` based on cumulative receipts.
6. THE Goods_Receipt_Service SHALL generate a Delivery_Note document for each accepted Goods_Receipt, available for download by the Store_Manager and Supplier.
7. THE Inventory_Service SHALL maintain a real-time stock balance per item per warehouse, updated immediately upon Goods_Receipt acceptance.
8. THE Store_Manager SHALL be able to search inventory by item name, category, warehouse, and stock level.
9. WHEN an inventory item's stock level falls below the configured reorder threshold, THE Inventory_Service SHALL send a low-stock Notification to the Store_Manager and Procurement_Officer.
10. THE Goods_Receipt_Service SHALL record all receiving and inspection actions in the Audit_Log with the responsible user's identity and timestamp.

---

### Requirement 13: Budget Management

**User Story:** As a Finance_Officer, I want to allocate and monitor budgets at the tenant and department level, so that procurement spending stays within authorized limits.

#### Acceptance Criteria

1. THE Budget_Service SHALL allow a Finance_Officer to create an annual Budget allocation for each department, specifying the fiscal year, total amount, and currency.
2. WHEN a Purchase_Request is submitted, THE Budget_Service SHALL validate the PR's estimated total value against the submitting department's remaining budget balance for the fiscal year.
3. IF a Purchase_Request's estimated total value exceeds the department's remaining budget balance, THEN THE Budget_Service SHALL reject the PR submission and return the available balance and the shortfall amount.
4. WHEN a Purchase_Order is issued, THE Budget_Service SHALL reserve (encumber) the PO value from the department's budget balance, reducing the available balance immediately.
5. WHEN a Purchase_Order is cancelled or rejected, THE Budget_Service SHALL release the encumbered amount back to the department's available balance.
6. WHEN an Invoice is approved for payment, THE Budget_Service SHALL record the actual expenditure against the department's budget and release the corresponding encumbrance.
7. THE Budget_Service SHALL send a Notification to the Finance_Officer and Tenant_Admin when a department's budget consumption reaches 75% and 90% of the annual allocation.
8. THE Finance_Officer SHALL be able to transfer budget between departments within the same fiscal year, with the transfer recorded in the Audit_Log.
9. THE Budget_Service SHALL prevent any procurement action that would cause a department's total committed and actual expenditure to exceed 100% of the annual allocation, unless a Finance_Officer explicitly approves an over-budget exception.
10. THE Budget_Service SHALL provide a real-time budget utilization report per department showing allocated, encumbered, spent, and available amounts.

---

### Requirement 14: Invoice and Payment Processing

**User Story:** As a Finance_Officer, I want to verify supplier invoices and process payments, so that suppliers are paid accurately and on time in accordance with contract terms.

#### Acceptance Criteria

1. THE Invoice_Service SHALL allow a Supplier to submit an Invoice referencing a specific Purchase_Order or Contract, including invoice number, date, line items, and total amount.
2. WHEN an Invoice is submitted, THE Invoice_Service SHALL validate that the invoiced amounts do not exceed the corresponding PO or Contract value and that the referenced goods have been received and accepted.
3. IF an Invoice's total amount exceeds the PO or Contract value, THEN THE Invoice_Service SHALL reject the Invoice and notify the Supplier with the discrepancy details.
4. WHEN an Invoice passes validation, THE Invoice_Service SHALL route it through the configured Invoice approval workflow for Finance_Officer review.
5. WHEN a Finance_Officer approves an Invoice, THE Payment_Service SHALL create a payment record with the approved amount, payment method, and scheduled payment date.
6. WHEN a payment is processed, THE Payment_Service SHALL update the Invoice status to `paid`, record the payment reference, and notify the Supplier.
7. THE Payment_Service SHALL track payment due dates and send reminder Notifications to the Finance_Officer 5 days before a payment is due.
8. THE Finance_Officer SHALL be able to record partial payments against an Invoice, with the Invoice status transitioning to `partially_paid` until the full amount is settled.
9. THE Invoice_Service SHALL record all invoice submission, verification, approval, and payment actions in the Audit_Log.
10. THE Finance_Officer SHALL be able to generate a payment schedule report showing all pending, overdue, and completed payments within a date range.

---

### Requirement 15: Notifications System

**User Story:** As a user of any role, I want to receive timely in-app and email notifications about procurement events relevant to my role, so that I can act on pending tasks without delay.

#### Acceptance Criteria

1. THE Notification_Service SHALL deliver in-app notifications to users in real time using WebSocket broadcasting (Laravel Echo with Pusher or a self-hosted equivalent).
2. THE Notification_Service SHALL deliver email notifications for all procurement events using a queued job to prevent blocking the main request thread.
3. WHEN a notification is delivered, THE Notification_Service SHALL store a record of the notification in the database with the recipient, event type, message, read status, and timestamp.
4. THE Platform SHALL allow each user to mark individual notifications as read or mark all notifications as read in a single action.
5. THE Notification_Service SHALL send notifications for the following events: PR submitted, PR approved/rejected/returned, Tender published, Bid deadline approaching (24 hours prior), Bid evaluation completed, PO issued, PO accepted/rejected, Goods_Receipt created, Invoice submitted, Invoice approved/rejected, payment processed, budget threshold reached, Contract renewal alert, and account locked.
6. WHEN a user has unread notifications, THE Platform SHALL display an unread count badge on the notification icon in the navigation interface.
7. THE Platform SHALL allow users to view a paginated notification history filtered by event type and date range.
8. THE Notification_Service SHALL respect each Tenant's notification configuration, allowing Tenant_Admins to enable or disable specific notification types.
9. IF an email notification fails to deliver after 3 retry attempts, THEN THE Notification_Service SHALL log the failure with the error details and alert the System_Admin.
10. THE Notification_Service SHALL ensure all notifications are scoped to the recipient's Tenant and never expose cross-tenant information.

---

### Requirement 16: Reporting and Analytics

**User Story:** As a Finance_Officer, Procurement_Officer, or Tenant_Admin, I want to view dashboards and generate reports on procurement activity, so that I can make informed decisions and demonstrate accountability.

#### Acceptance Criteria

1. THE Reporting_Service SHALL provide role-specific dashboards displaying KPI widgets relevant to each role, including: total PRs by status, active Tenders, PO fulfillment rate, budget utilization percentage, pending approvals count, and overdue deliveries count.
2. THE Reporting_Service SHALL provide a procurement timeline report showing the average cycle time from PR creation to PO issuance, filterable by department, category, and date range.
3. THE Reporting_Service SHALL provide a spending analytics report showing actual expenditure by department, category, and supplier, with month-over-month trend charts.
4. THE Reporting_Service SHALL provide a supplier performance report showing each Supplier's on-time delivery rate, quality acceptance rate, and total contract value within a date range.
5. THE Reporting_Service SHALL provide a tender statistics report showing the number of Tenders published, average number of Bids per Tender, and average evaluation duration.
6. THE Reporting_Service SHALL provide a financial summary report showing total invoiced, total paid, total outstanding, and budget variance per department and fiscal year.
7. WHEN a user requests a report export, THE Reporting_Service SHALL generate the report in PDF or Excel format and make it available for download within 30 seconds for datasets up to 10,000 rows.
8. IF a report dataset exceeds 10,000 rows, THEN THE Reporting_Service SHALL process the export as a background Queue job and notify the user via Notification when the file is ready for download.
9. THE Reporting_Service SHALL ensure all report data is scoped to the requesting user's Tenant and filtered by the user's role-based data access permissions.
10. THE Reporting_Service SHALL support filtering all reports by date range, department, category, status, and supplier.

---

### Requirement 17: Audit Logging and Traceability

**User Story:** As a System_Admin or Tenant_Admin, I want every action on the Platform to be recorded in an immutable audit log, so that all procurement activities are fully traceable and compliant with governance requirements.

#### Acceptance Criteria

1. THE Audit_Service SHALL record an Audit_Log entry for every create, read (sensitive data), update, delete, and status-change action performed on any entity in the Platform.
2. WHEN an Audit_Log entry is created, THE Audit_Service SHALL capture: the acting user's UUID, role, and tenant_id; the action type; the affected entity type and UUID; the before and after state (JSON diff); the IP address; and the UTC timestamp.
3. THE Audit_Service SHALL record authentication events including: successful login, failed login attempt, account lockout, password reset, and logout.
4. THE Audit_Service SHALL record all financial actions including: budget allocation, budget transfer, PO issuance, Invoice approval, and payment processing.
5. THE Audit_Log SHALL be stored in an append-only manner; no update or delete operation SHALL be permitted on Audit_Log records through any application interface or API endpoint.
6. IF an attempt is made to modify or delete an Audit_Log record via the API, THEN THE Audit_Service SHALL reject the request with HTTP 403 and log the attempt as a security event.
7. THE Tenant_Admin SHALL be able to search and filter Audit_Logs by user, action type, entity type, date range, and IP address within their Tenant's scope.
8. THE System_Admin SHALL be able to access Audit_Logs across all Tenants for platform-level security investigations.
9. THE Audit_Service SHALL write Audit_Log entries asynchronously via a Queue job to prevent performance impact on the main request thread, with a maximum write latency of 5 seconds.
10. THE Platform SHALL retain Audit_Log records for a minimum of 7 years in accordance with enterprise compliance requirements.

---

### Requirement 18: API Design and Documentation

**User Story:** As a developer integrating with the Platform, I want a versioned, well-documented RESTful API, so that I can build reliable integrations without ambiguity.

#### Acceptance Criteria

1. THE API SHALL expose all endpoints under the versioned base path `/api/v1` to allow future non-breaking version increments.
2. THE API SHALL return all responses in a consistent JSON envelope with fields: `success` (boolean), `data` (object or array), `message` (string), `errors` (object, present on validation failure), and `meta` (pagination metadata where applicable).
3. WHEN a request fails validation, THE API SHALL return HTTP 422 with an `errors` object mapping each invalid field name to an array of error messages.
4. THE API SHALL support pagination on all list endpoints using `page` and `per_page` query parameters, with a default page size of 20 and a maximum of 100.
5. THE API SHALL support filtering, searching, and sorting on list endpoints via query parameters, with the supported parameters documented per endpoint.
6. THE API SHALL be documented using OpenAPI 3.0 specification, with the interactive Swagger UI accessible at `/api/documentation`.
7. WHEN the OpenAPI specification is generated, THE API_Documentation_Service SHALL include all endpoints, request schemas, response schemas, authentication requirements, and example payloads.
8. THE API SHALL return appropriate HTTP status codes: 200 for successful reads, 201 for successful creates, 204 for successful deletes, 400 for bad requests, 401 for unauthenticated, 403 for unauthorized, 404 for not found, 422 for validation errors, and 429 for rate limit exceeded.
9. THE API SHALL enforce authentication on all endpoints except the login, password-reset-request, and Supplier self-registration endpoints.
10. THE API SHALL include request ID headers (`X-Request-ID`) in all responses to facilitate distributed tracing and support correlation.

---

### Requirement 19: Database Design and Integrity

**User Story:** As a developer, I want the database schema to enforce referential integrity, use UUIDs, and be optimized for the Platform's query patterns, so that data is consistent and queries perform efficiently.

#### Acceptance Criteria

1. THE Database SHALL use UUIDs as primary keys for all major entity tables: tenants, users, departments, budgets, purchase_requests, purchase_request_items, approvals, suppliers, tenders, bids, bid_evaluations, purchase_orders, contracts, invoices, payments, inventory, notifications, and audit_logs.
2. THE Database SHALL enforce foreign key constraints between all related tables to prevent orphaned records.
3. THE Database SHALL include indexes on all foreign key columns, all columns used in WHERE clauses for list queries (status, tenant_id, created_at, department_id), and all columns used in ORDER BY clauses.
4. THE Database SHALL use database-level unique constraints on: user email per tenant, PR number per tenant, PO number per tenant, and Tender reference number per tenant.
5. THE Database SHALL include `created_at` and `updated_at` timestamp columns on all tables, managed automatically by the ORM.
6. THE Database SHALL include `deleted_at` soft-delete columns on all tables where records must be retained for audit purposes rather than permanently deleted.
7. THE Platform SHALL provide database migrations for all schema changes, with rollback capability for each migration.
8. THE Platform SHALL provide database seeders for system roles, permissions, and a demo Tenant with sample data for development and testing environments.
9. THE Platform SHALL provide model factories for all major entities to support automated test data generation.
10. THE Database SHALL enforce that all monetary values are stored as DECIMAL(15,2) to prevent floating-point precision errors.

---

### Requirement 20: Infrastructure, DevOps, and Deployment

**User Story:** As a DevOps engineer, I want the Platform to be containerized and deployable via CI/CD pipelines, so that deployments are repeatable, reliable, and environment-consistent.

#### Acceptance Criteria

1. THE Platform SHALL provide a `docker-compose.yml` file that orchestrates all required services: the Laravel application, Next.js frontend, MySQL database, Redis cache, Nginx reverse proxy, and Queue worker.
2. THE Platform SHALL provide separate Docker environment configurations for development, staging, and production environments.
3. WHEN the Docker environment starts, THE Platform SHALL run database migrations and seed required system data automatically on first initialization.
4. THE Platform SHALL provide a CI/CD pipeline configuration (GitHub Actions or equivalent) that runs automated tests, builds Docker images, and deploys to the target environment on merge to the main branch.
5. THE Platform SHALL use environment variables for all sensitive configuration values (database credentials, JWT secret, mail credentials, Redis connection) and SHALL NOT hard-code these values in source code.
6. THE Platform SHALL configure Nginx as a reverse proxy with TLS termination, HTTP-to-HTTPS redirection, and appropriate security headers (HSTS, X-Frame-Options, Content-Security-Policy).
7. THE Platform SHALL configure Redis as the cache driver, session driver, and Queue backend to support horizontal scaling.
8. THE Platform SHALL provide a database backup strategy that performs automated daily backups with a 30-day retention policy.
9. THE Queue_Worker SHALL process jobs from at least three named queues: `default`, `notifications`, and `reports`, with the `notifications` queue having higher priority than `default`.
10. THE Platform SHALL expose a health-check endpoint at `/api/health` that returns the status of the database connection, Redis connection, and Queue worker availability.

---

### Requirement 21: Testing Strategy

**User Story:** As a developer, I want a comprehensive automated test suite covering unit, feature, API, and multi-tenant isolation scenarios, so that regressions are caught before deployment.

#### Acceptance Criteria

1. THE Test_Suite SHALL include unit tests for all Service and Repository classes, achieving a minimum of 80% code coverage on business logic classes.
2. THE Test_Suite SHALL include feature tests for all API endpoints using PHPUnit or Pest, verifying correct HTTP status codes, response structures, and business rule enforcement.
3. THE Test_Suite SHALL include multi-tenant isolation tests that verify a user from Tenant A cannot read, modify, or delete data belonging to Tenant B through any API endpoint.
4. THE Test_Suite SHALL include authentication tests covering: successful login, failed login with wrong password, account lockout after threshold failures, JWT expiry rejection, and password reset flow.
5. THE Test_Suite SHALL include approval workflow tests covering: single-level approval, multi-level approval, rejection at each level, return for revision, and escalation notification triggering.
6. THE Test_Suite SHALL include budget enforcement tests covering: PR submission within budget, PR rejection when over budget, PO encumbrance, encumbrance release on PO cancellation, and over-budget exception approval.
7. THE Test_Suite SHALL include end-to-end API tests using Cypress or Playwright that simulate the full procurement lifecycle from PR creation to payment processing.
8. THE Test_Suite SHALL include property-based tests for the PR number and PO number generation functions, verifying that generated identifiers are unique across a large set of generated inputs.
9. FOR ALL valid serialized entity representations (JSON API responses), parsing the response and re-serializing it SHALL produce an equivalent JSON structure (round-trip property).
10. THE CI/CD pipeline SHALL execute the full test suite on every pull request and block merging if any test fails.

---

### Requirement 22: Frontend and Mobile UI/UX

**User Story:** As a user of any role, I want a modern, responsive, and accessible interface on both web and mobile, so that I can perform procurement tasks efficiently from any device.

#### Acceptance Criteria

1. THE Frontend SHALL implement a role-specific sidebar navigation that displays only the modules accessible to the authenticated user's role.
2. THE Frontend SHALL support dark mode and light mode, with the user's preference persisted in local storage and applied on subsequent sessions.
3. THE Frontend SHALL be responsive and render correctly on screen widths from 320px (mobile) to 2560px (large desktop) without horizontal scrolling.
4. THE Frontend SHALL meet WCAG 2.1 Level AA accessibility standards, including keyboard navigation, sufficient color contrast ratios, and ARIA labels on interactive elements.
5. THE Frontend SHALL use TanStack Query for all server-state management, implementing optimistic updates for status-change actions to provide immediate visual feedback.
6. THE Frontend SHALL use React Hook Form with Zod schema validation for all forms, displaying inline field-level error messages without full-page reloads.
7. THE Frontend SHALL display loading skeletons during data fetching and error boundary components when API requests fail, with a retry action available to the user.
8. THE Mobile_App SHALL implement Clean Architecture with separate data, domain, and presentation layers, consuming the same `/api/v1` endpoints as the web frontend.
9. THE Mobile_App SHALL support offline-capable viewing of the user's last-fetched dashboard data, displaying a clear indicator when the device is offline.
10. THE Frontend SHALL use Recharts and Framer Motion to render animated analytics charts on dashboard pages, with chart data updating automatically when the underlying data changes.

---

### Requirement 23: File Management

**User Story:** As a user of any role, I want to upload and download procurement documents securely, so that all supporting evidence is attached to the relevant records and accessible to authorized users.

#### Acceptance Criteria

1. THE File_Management_Service SHALL accept file uploads in the following formats: PDF, DOCX, XLSX, PNG, JPG, and JPEG.
2. WHEN a file is uploaded, THE File_Management_Service SHALL validate that the file size does not exceed 10 MB and that the MIME type matches the declared file extension.
3. IF a file fails validation, THEN THE File_Management_Service SHALL reject the upload and return a descriptive error message identifying the specific validation failure.
4. THE File_Management_Service SHALL store uploaded files in a tenant-scoped directory structure, ensuring files from one Tenant are not accessible to another Tenant.
5. WHEN a file download is requested, THE File_Management_Service SHALL verify that the requesting user belongs to the same Tenant as the file owner before serving the file.
6. THE File_Management_Service SHALL generate a unique, non-guessable storage key for each uploaded file to prevent enumeration attacks.
7. THE File_Management_Service SHALL retain all uploaded files associated with completed procurement records for a minimum of 7 years.
8. THE Platform SHALL support configuring cloud object storage (e.g., AWS S3 or compatible) as the file storage backend via environment variables.
9. WHEN a file is deleted by an authorized user, THE File_Management_Service SHALL perform a soft delete, retaining the file in storage and recording the deletion in the Audit_Log.
10. THE File_Management_Service SHALL provide a virus/malware scan integration point, rejecting files that fail the scan before storing them.

---

### Requirement 24: Performance and Scalability

**User Story:** As a System_Admin, I want the Platform to perform reliably under concurrent load from multiple tenants, so that all users experience acceptable response times during peak usage.

#### Acceptance Criteria

1. THE API SHALL respond to authenticated list and detail requests within 500ms at the 95th percentile under a load of 100 concurrent users per Tenant.
2. THE API SHALL respond to authentication requests within 300ms at the 95th percentile under a load of 50 concurrent login attempts.
3. THE Platform SHALL use Redis caching for frequently accessed, low-volatility data (role permissions, Tenant configuration, budget summaries) with a cache TTL of 300 seconds.
4. WHEN cached data is invalidated (e.g., role change, budget update), THE Platform SHALL purge the relevant cache keys immediately.
5. THE Platform SHALL use database query optimization techniques including eager loading of relationships, indexed lookups, and pagination to prevent N+1 query patterns.
6. THE Queue_Worker SHALL process Notification jobs within 10 seconds of enqueue under normal load conditions.
7. THE Platform SHALL support horizontal scaling of the application tier by ensuring no server-local state is stored outside of Redis or the database.
8. THE Platform SHALL implement database connection pooling to support a minimum of 200 concurrent database connections without connection exhaustion.
9. THE Reporting_Service SHALL cache generated report data for 5 minutes to prevent redundant computation when the same report is requested multiple times in quick succession.
10. THE Platform SHALL log slow queries (execution time exceeding 1 second) to a dedicated slow-query log for performance monitoring.

---

### Requirement 25: Data Serialization and API Parsing

**User Story:** As a developer, I want all data exchanged through the API to be consistently serialized and parseable, so that client integrations are reliable and data integrity is maintained across the wire.

#### Acceptance Criteria

1. THE API_Serializer SHALL serialize all entity responses into a consistent JSON structure defined by the OpenAPI schema for each resource type.
2. WHEN a client submits a JSON request body, THE API_Parser SHALL parse and validate the payload against the defined request schema before passing it to the Service layer.
3. IF a JSON request body contains fields not defined in the request schema, THEN THE API_Parser SHALL strip the unknown fields and process only the defined fields (strict input filtering).
4. THE API_Serializer SHALL format all date and datetime values as ISO 8601 strings (e.g., `2025-01-15T10:30:00Z`) in all responses.
5. THE API_Serializer SHALL format all monetary values as strings with exactly 2 decimal places (e.g., `"1234.56"`) to prevent client-side floating-point precision loss.
6. FOR ALL valid API resource objects, serializing the object to JSON and then parsing the JSON back into a resource object SHALL produce an equivalent object with identical field values (round-trip property).
7. THE API_Parser SHALL reject requests with a `Content-Type` other than `application/json` on endpoints that expect a JSON body, returning HTTP 415.
8. THE API_Serializer SHALL include a `links` object in paginated responses containing `first`, `last`, `prev`, and `next` URL references for cursor navigation.
9. WHEN a resource has related entities, THE API_Serializer SHALL support an `include` query parameter to embed related resources in the response, avoiding separate round-trip requests.
10. THE API_Parser SHALL validate that UUID fields in request bodies conform to the UUID v4 format, returning a validation error for malformed UUIDs.

---

## Business Rules Summary

The following cross-cutting business rules apply across all modules and are enforced at the Service layer:

1. **Tenant Scoping**: Every data access operation MUST be scoped to the active Tenant. No query may return records from a different Tenant.
2. **Budget Enforcement**: A Purchase_Request or Purchase_Order MUST NOT be approved or issued if it would cause the department's total committed expenditure to exceed the annual budget allocation, unless a Finance_Officer explicitly approves an over-budget exception.
3. **Bid Immutability After Deadline**: A Supplier MUST NOT be permitted to create, modify, or withdraw a Bid after the Tender's submission deadline has passed.
4. **Performance Bond Requirement**: A Contract MUST NOT transition to `active` status until a signed performance bond document has been uploaded and recorded.
5. **Goods Inspection Requirement**: Delivered goods MUST pass committee inspection before the corresponding Goods_Receipt is accepted and inventory is updated.
6. **Audit Log Immutability**: Audit_Log records MUST NOT be modified or deleted through any application interface, API, or direct database operation accessible to application users.
7. **Procurement Traceability**: Every procurement action (PR, Tender, Bid, PO, Contract, Invoice, Payment) MUST be traceable to the originating user, Tenant, department, and timestamp.
8. **Supplier Eligibility**: Only Suppliers with `active` status MUST be eligible to receive Tender invitations, submit Bids, or receive Purchase_Orders.
9. **Invoice Validation**: An Invoice MUST NOT be approved for payment if the invoiced amount exceeds the corresponding PO or Contract value, or if the referenced goods have not been received and accepted.
10. **Cross-Tenant Data Isolation**: Under no circumstances MUST a user from one Tenant be able to read, write, or infer the existence of data belonging to another Tenant.
