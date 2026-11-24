# ARNA Loan Configuration Workflow — Implementation Record

## Implemented on the existing production-bootstrap PR branch

The Loan domain now has a first controlled product-configuration workflow foundation. It does not seed or activate any product or financial term.

### API lifecycle

All routes inherit the Loan domain's existing authentication and tenant-membership middleware.

- `GET /api/loans/configuration` — tenant-scoped, paginated submission list; requires `loan.configuration.view`.
- `POST /api/loans/configuration` — create a validated draft; requires `loan.configuration.manage`.
- `PUT /api/loans/configuration/{submission}` — edit an author's own draft only; requires `loan.configuration.manage`.
- `POST /api/loans/configuration/{submission}/submit` — submit a draft; requires `loan.configuration.submit`.
- `POST /api/loans/configuration/{submission}/review` — approve or reject; requires `loan.configuration.approve`.
- `POST /api/loans/configuration/{submission}/activate` — activate an approved product after its effective date; requires `loan.configuration.activate`.

Tenant identity is derived from the authenticated tenant-membership context, never from request payload. Cross-tenant submission IDs are resolved only within that tenant scope.

### Approval and publication

- A draft is editable only by its author and only while its status is `draft`.
- A submission becomes immutable to the author after submission.
- The author cannot approve or reject their own submission.
- Approval creates an immutable `loan_product_rules` version through Core's `VersionedPolicyRegistry` and creates the product with `is_active = false`.
- Activation is a separate permission and action, and is rejected before the policy effective date.
- Every lifecycle transition appends an audit row containing the actor, tenant, event, timestamp, and event metadata.
- The new configuration permissions are catalogued but are not automatically granted to any role. An Owner-governed RBAC assignment is required before operators can use these endpoints.

### Payload and validation

The draft stores product identity/envelope separately from the nested financial policy. Required policy sections are `interest`, `term`, `arrears`, `collateral`, and `fees`. The validator requires a product-specific decimal annual rate, optional penalty configuration, explicit arrears/collateral enablement, term limits, and an explicit fee list. It rejects unsupported calculation methods and malformed decimal values. No business interest rate, grace days, penalty rate, fee, or product limits are supplied by code.

The existing Loan calculation engine currently supports declining-balance annual percentage interest, monthly annuity schedules, Actual/365, half-up rounding at four decimal places, monthly repayment, and overdue-installment penalty basis. Those are algorithm compatibility constraints, not default financial values.

### Database safeguards

- New `loan_products` rows default to inactive.
- The forward migration changes the existing column default to inactive and deactivates existing catalogue entries pending review. It does not delete products, policies, or loans.
- `loan_configuration_submissions` stores drafts, review state, publication references, and activation state.
- `loan_configuration_audit_logs` stores append-only lifecycle evidence.
- Rolling back the workflow tables does not restore an active-by-default product setting.

### Still outstanding

- Automated MySQL migration and end-to-end workflow tests are implemented; the most recent exact-head run passed before the current minimum-term grace and stale-revision regression additions, which require a new A4 run.
- Explicit Owner-approved permission-to-role assignments for designated product drafters, submitters, reviewers, and activators.
- Product retirement and reviewer queue UX. Existing-product policy revision and the tenant-scoped audit-history read endpoint are implemented and covered by MySQL lifecycle tests.
- Independent review of the financial payload contract against every Loan consumer before enabling production lending. Remaining unsupported fee obligations and mandatory-collateral enforcement stay explicitly fail-closed.

Until A4 passes and the role assignments and financial governance are approved, do not activate products or enable loan origination. Existing loan servicing remains governed by each loan's frozen policy version.
