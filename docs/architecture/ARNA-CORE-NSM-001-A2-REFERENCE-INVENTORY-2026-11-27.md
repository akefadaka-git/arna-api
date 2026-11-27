# ARNA Core Namespace Migration — A2 Path and Reference Inventory

- **Record:** ARNA-CORE-NSM-001-A2-INV
- **Assessment:** 2026-11-27 (EAT)
- **API repository:** `akefadaka-git/arna-api`
- **API main baseline:** `9c0ce83afcc084101fb14e6e55d54e1d953c2ffe`
- **App repository:** `akefadaka-git/arna-app`
- **App main baseline:** `71bed2158894e3ce9cf507e8bf70492ac1f9dfd4`
- **Purpose:** read-only path, reference, runtime dependency and external-consumer inventory before any implementation.
- **Boundary:** no source, tests, CI, migrations, database, deployment or production state changed.

## 1. Complete API path inventory

The recursive Git tree at the API baseline returned `truncated=false` and 1046 tracked file paths.

- Core source root: **642 files**, of which **636 PHP files** and 6 non-PHP files.
- Domain sub-tree: 544 files / 539 PHP files / 64 domain test files.
- Root test tree: 92 PHP test files.
- Total PHP test files: **156** (64 under Core domain tree + 92 under `tests/`).
- Tracked workflows: **39**; **27** contain old Core path references.
- Database migrations at `database/migrations/`: 53 PHP files (stay in place).
- Core-owned migrations under `mases_core/src/**/Migrations`: 52 PHP files (move with the Core source tree; no migration execution or schema change is implied).
- The 642 exact tracked Core paths are enumerated below. This is the authoritative move-set for the source tree at this SHA.

### 1.1 All tracked paths under `mases_core/src/`

- `mases_core/src/Audit/Listeners/LogFailedLogin.php`
- `mases_core/src/Audit/Listeners/LogLogout.php`
- `mases_core/src/Audit/Listeners/LogSuccessfulLogin.php`
- `mases_core/src/Audit/Models/AuditLog.php`
- `mases_core/src/Audit/Services/AuditService.php`
- `mases_core/src/Audit/Traits/LogsAudit.php`
- `mases_core/src/Auth/Events/FailedLogin.php`
- `mases_core/src/Auth/Models/DeviceSession.php`
- `mases_core/src/Auth/Models/User.php`
- `mases_core/src/Auth/Providers/AuthServiceProvider.php`
- `mases_core/src/Auth/Services/AdaptiveAuthenticationPolicy.php`
- `mases_core/src/Auth/Services/AdaptiveStepUpContext.php`
- `mases_core/src/Auth/Services/AdaptiveStepUpGuard.php`
- `mases_core/src/Auth/Services/AdaptiveStepUpService.php`
- `mases_core/src/Auth/Services/AuthenticationService.php`
- `mases_core/src/Auth/Services/DeviceSessionService.php`
- `mases_core/src/Auth/Services/LoginIdentifierResolver.php`
- `mases_core/src/Auth/Services/PhoneVerificationDelivery.php`
- `mases_core/src/Auth/Services/PhoneVerificationService.php`
- `mases_core/src/Auth/Services/TotpMfaService.php`
- `mases_core/src/Authorization/Events/RolePermissionsChanged.php`
- `mases_core/src/Authorization/ExecutiveOverviewAccessPolicy.php`
- `mases_core/src/Authorization/Middleware/ApplicationAccessMiddleware.php`
- `mases_core/src/Authorization/Middleware/AuthorizationMiddleware.php`
- `mases_core/src/Authorization/Middleware/CheckApplicationAccess.php`
- `mases_core/src/Authorization/Middleware/CheckPermission.php`
- `mases_core/src/Authorization/Middleware/PermissionMiddleware.php`
- `mases_core/src/Authorization/Middleware/RoleMiddleware.php`
- `mases_core/src/Authorization/Models/Permission.php`
- `mases_core/src/Authorization/Models/PlatformApplication.php`
- `mases_core/src/Authorization/Models/Role.php`
- `mases_core/src/Authorization/Models/RoleHasPermission.php`
- `mases_core/src/Authorization/Models/UserHasPermission.php`
- `mases_core/src/Authorization/Models/UserHasRole.php`
- `mases_core/src/Authorization/Services/AuthorizationService.php`
- `mases_core/src/Calendar/Http/Controllers/CalendarController.php`
- `mases_core/src/Calendar/Models/Calendar.php`
- `mases_core/src/Calendar/Models/CalendarAppointment.php`
- `mases_core/src/Calendar/Models/CalendarAttendee.php`
- `mases_core/src/Calendar/Models/CalendarEvent.php`
- `mases_core/src/Calendar/Models/CalendarEventLink.php`
- `mases_core/src/Calendar/Services/CalendarDomainResourceAuthorizer.php`
- `mases_core/src/Calendar/Services/CalendarReminderDispatcher.php`
- `mases_core/src/Calendar/Services/CalendarService.php`
- `mases_core/src/Config/localization.php`
- `mases_core/src/Config/mases.php`
- `mases_core/src/Domains/Documents/Contracts/DocumentSignatureEvidenceVerifier.php`
- `mases_core/src/Domains/Documents/Contracts/DocumentWorkflowAuthorizer.php`
- `mases_core/src/Domains/Documents/Database/Seeders/DocumentsPermissionCatalogue.php`
- `mases_core/src/Domains/Documents/Database/Seeders/DocumentsRolePermissionGrants.php`
- `mases_core/src/Domains/Documents/Models/Document.php`
- `mases_core/src/Domains/Documents/Models/DocumentLifecycleEvent.php`
- `mases_core/src/Domains/Documents/Models/DocumentWorkflowAggregate.php`
- `mases_core/src/Domains/Documents/Models/DocumentWorkflowEvent.php`
- `mases_core/src/Domains/Documents/Registries/DocumentTypeRegistry.php`
- `mases_core/src/Domains/Documents/Services/DocumentEvidenceCaptureLifecycle.php`
- `mases_core/src/Domains/Documents/Services/DocumentEvidenceCaptureReconciler.php`
- `mases_core/src/Domains/Documents/Services/DocumentNumberAllocator.php`
- `mases_core/src/Domains/Documents/Services/DocumentNumberFormatter.php`
- `mases_core/src/Domains/Documents/Services/DocumentSigningKeyRevocationService.php`
- `mases_core/src/Domains/Documents/Services/DocumentWorkflowAggregateService.php`
- `mases_core/src/Domains/Documents/Services/DocumentWorkflowAuthorizationService.php`
- `mases_core/src/Domains/Documents/Services/EthiopianDocumentYearResolver.php`
- `mases_core/src/Domains/Documents/Services/ImmutableDocumentBinaryStore.php`
- `mases_core/src/Domains/Documents/Services/SodiumDocumentSignatureEvidenceVerifier.php`
- `mases_core/src/Domains/FileManagement/Models/File.php`
- `mases_core/src/Domains/FileManagement/Services/FileService.php`
- `mases_core/src/Domains/Finance/Config/FinanceApplicationCatalogue.php`
- `mases_core/src/Domains/Finance/Config/chart_of_accounts_v1.php`
- `mases_core/src/Domains/Finance/Config/finance.php`
- `mases_core/src/Domains/Finance/Contracts/FinanceIntelligenceProviderContract.php`
- `mases_core/src/Domains/Finance/Contracts/FinancialReportContract.php`
- `mases_core/src/Domains/Finance/Contracts/JournalSourceReferenceResolver.php`
- `mases_core/src/Domains/Finance/Contracts/SourceLegalEntityMappingContract.php`
- `mases_core/src/Domains/Finance/Contracts/SubledgerPostingContract.php`
- `mases_core/src/Domains/Finance/Database/Migrations/2026_10_28_000003_create_financial_ledger_foundation.php`
- `mases_core/src/Domains/Finance/Database/Migrations/2026_10_28_000004_add_journal_type_to_journal_entries.php`
- `mases_core/src/Domains/Finance/Database/Migrations/2026_10_28_000005_enforce_single_journal_reversal.php`
- `mases_core/src/Domains/Finance/Database/Migrations/2026_10_28_000006_create_journal_source_entity_mappings.php`
- `mases_core/src/Domains/Finance/Database/Migrations/2026_10_28_120000_add_canonical_coa_metadata_to_ledger_accounts.php`
- `mases_core/src/Domains/Finance/Database/Migrations/2026_10_29_000001_create_finance_consolidation_and_fx_foundation.php`
- `mases_core/src/Domains/Finance/Database/Migrations/2026_11_02_120000_create_governance_settlement_finance_tables.php`
- `mases_core/src/Domains/Finance/Database/Migrations/2026_11_12_000001_harden_journal_line_amount_signs.php`
- `mases_core/src/Domains/Finance/Database/Migrations/2026_11_12_000002_create_finance_outbox_messages.php`
- `mases_core/src/Domains/Finance/Database/Migrations/2026_11_12_000003_create_finance_ai_proposals.php`
- `mases_core/src/Domains/Finance/Database/Migrations/2026_11_12_000004_create_finance_inbox_events.php`
- `mases_core/src/Domains/Finance/Database/Seeders/FinanceChartOfAccountsSeeder.php`
- `mases_core/src/Domains/Finance/Database/Seeders/FinancePermissionCatalogue.php`
- `mases_core/src/Domains/Finance/Database/Seeders/FinanceRoleCatalogue.php`
- `mases_core/src/Domains/Finance/Database/Seeders/FinanceRolePermissionGrants.php`
- `mases_core/src/Domains/Finance/Documentation/ARNA-COA-v1.0.0.md`
- `mases_core/src/Domains/Finance/Documentation/consolidation-and-manual-fx-foundation.md`
- `mases_core/src/Domains/Finance/Documentation/finance-accounting-domain-contract.md`
- `mases_core/src/Domains/Finance/Documentation/financial-accounting-domain-contract.md`
- `mases_core/src/Domains/Finance/Enums/AccountingPeriodStatus.php`
- `mases_core/src/Domains/Finance/Enums/JournalEntryStatus.php`
- `mases_core/src/Domains/Finance/Enums/JournalEntryType.php`
- `mases_core/src/Domains/Finance/Enums/JournalSourceType.php`
- `mases_core/src/Domains/Finance/Events/AccountingPeriodClosed.php`
- `mases_core/src/Domains/Finance/Events/AccountingPeriodOpened.php`
- `mases_core/src/Domains/Finance/Events/FinanceLifecycleEvent.php`
- `mases_core/src/Domains/Finance/Events/JournalCancelled.php`
- `mases_core/src/Domains/Finance/Events/JournalDraftCreated.php`
- `mases_core/src/Domains/Finance/Events/JournalPosted.php`
- `mases_core/src/Domains/Finance/Events/JournalReversed.php`
- `mases_core/src/Domains/Finance/Events/LedgerAccountCreated.php`
- `mases_core/src/Domains/Finance/Events/LegalEntityCreated.php`
- `mases_core/src/Domains/Finance/Http/Controllers/FinanceController.php`
- `mases_core/src/Domains/Finance/Http/Controllers/FinanceIntegrationHealthController.php`
- `mases_core/src/Domains/Finance/Http/Controllers/FinanceIntelligenceController.php`
- `mases_core/src/Domains/Finance/Http/Controllers/FinanceSubledgerInboxController.php`
- `mases_core/src/Domains/Finance/Http/Controllers/GovernancePenaltySettlementController.php`
- `mases_core/src/Domains/Finance/Http/Requests/CancelJournalRequest.php`
- `mases_core/src/Domains/Finance/Http/Requests/ReverseJournalRequest.php`
- `mases_core/src/Domains/Finance/Http/Requests/StoreAccountingPeriodRequest.php`
- `mases_core/src/Domains/Finance/Http/Requests/StoreJournalRequest.php`
- `mases_core/src/Domains/Finance/Http/Requests/StoreLedgerAccountRequest.php`
- `mases_core/src/Domains/Finance/Http/Requests/StoreLegalEntityRequest.php`
- `mases_core/src/Domains/Finance/Http/Resources/LegalEntityResource.php`
- `mases_core/src/Domains/Finance/Http/Resources/TrialBalanceResource.php`
- `mases_core/src/Domains/Finance/Listeners/AuditFinanceLifecycleEvent.php`
- `mases_core/src/Domains/Finance/Listeners/PersistFinanceJournalPostedOutbox.php`
- `mases_core/src/Domains/Finance/Models/AccountingPeriod.php`
- `mases_core/src/Domains/Finance/Models/FinanceAiProposal.php`
- `mases_core/src/Domains/Finance/Models/FinanceAuditEntry.php`
- `mases_core/src/Domains/Finance/Models/FinanceConsolidationAccountMapping.php`
- `mases_core/src/Domains/Finance/Models/FinanceExchangeRate.php`
- `mases_core/src/Domains/Finance/Models/FinanceInboxEvent.php`
- `mases_core/src/Domains/Finance/Models/FinanceOutboxMessage.php`
- `mases_core/src/Domains/Finance/Models/GovernanceSettlementAccountMapping.php`
- `mases_core/src/Domains/Finance/Models/JournalEntry.php`
- `mases_core/src/Domains/Finance/Models/JournalLine.php`
- `mases_core/src/Domains/Finance/Models/JournalSourceEntityMapping.php`
- `mases_core/src/Domains/Finance/Models/LedgerAccount.php`
- `mases_core/src/Domains/Finance/Models/LegalEntity.php`
- `mases_core/src/Domains/Finance/Models/ShareholderDividendPayable.php`
- `mases_core/src/Domains/Finance/Policies/AccountingPeriodPolicy.php`
- `mases_core/src/Domains/Finance/Policies/FinancePolicy.php`
- `mases_core/src/Domains/Finance/Policies/JournalEntryPolicy.php`
- `mases_core/src/Domains/Finance/Policies/JournalSourceEntityMappingPolicy.php`
- `mases_core/src/Domains/Finance/Policies/LedgerAccountPolicy.php`
- `mases_core/src/Domains/Finance/Policies/LegalEntityPolicy.php`
- `mases_core/src/Domains/Finance/Providers/FinanceServiceProvider.php`
- `mases_core/src/Domains/Finance/README.md`
- `mases_core/src/Domains/Finance/Repositories/AccountingPeriodRepository.php`
- `mases_core/src/Domains/Finance/Repositories/JournalEntryRepository.php`
- `mases_core/src/Domains/Finance/Repositories/LedgerAccountRepository.php`
- `mases_core/src/Domains/Finance/Repositories/LegalEntityRepository.php`
- `mases_core/src/Domains/Finance/Resources/lang/am/attributes.php`
- `mases_core/src/Domains/Finance/Resources/lang/am/validation.php`
- `mases_core/src/Domains/Finance/Resources/lang/en/attributes.php`
- `mases_core/src/Domains/Finance/Resources/lang/en/validation.php`
- `mases_core/src/Domains/Finance/Routes/api.php`
- `mases_core/src/Domains/Finance/Services/AccountingPeriodService.php`
- `mases_core/src/Domains/Finance/Services/FinanceConsolidationMappingService.php`
- `mases_core/src/Domains/Finance/Services/FinanceConsolidationService.php`
- `mases_core/src/Domains/Finance/Services/FinanceCurrencyConverter.php`
- `mases_core/src/Domains/Finance/Services/FinanceExchangeRateService.php`
- `mases_core/src/Domains/Finance/Services/FinanceIntegrationHealthService.php`
- `mases_core/src/Domains/Finance/Services/FinanceIntelligenceService.php`
- `mases_core/src/Domains/Finance/Services/FinanceOutboxDispatcher.php`
- `mases_core/src/Domains/Finance/Services/FinanceSubledgerInboxService.php`
- `mases_core/src/Domains/Finance/Services/FinancialReportService.php`
- `mases_core/src/Domains/Finance/Services/GovernancePenaltySettlementService.php`
- `mases_core/src/Domains/Finance/Services/JournalEntryService.php`
- `mases_core/src/Domains/Finance/Services/JournalSourceEntityMappingResolver.php`
- `mases_core/src/Domains/Finance/Services/JournalSourceReferenceResolverRegistry.php`
- `mases_core/src/Domains/Finance/Services/LedgerAccountService.php`
- `mases_core/src/Domains/Finance/Services/LegalEntityService.php`
- `mases_core/src/Domains/Finance/Services/SourceLegalEntityMappingService.php`
- `mases_core/src/Domains/Finance/Services/SubledgerIntegrationService.php`
- `mases_core/src/Domains/Finance/Services/SubledgerPostingInstruction.php`
- `mases_core/src/Domains/Finance/Services/TrialBalanceService.php`
- `mases_core/src/Domains/Finance/Services/UnavailableFinanceIntelligenceProvider.php`
- `mases_core/src/Domains/Finance/Support/FinanceCalendarDateAdapter.php`
- `mases_core/src/Domains/Finance/Support/FinanceCurrencyPolicy.php`
- `mases_core/src/Domains/Finance/Support/MoneyArithmetic.php`
- `mases_core/src/Domains/Finance/Tests/Feature/FinanceGeneralLedgerTest.php`
- `mases_core/src/Domains/Finance/Tests/Feature/FinanceSourceEntityMappingResolverTest.php`
- `mases_core/src/Domains/Finance/Tests/Feature/FinanceSourceEntityMappingTest.php`
- `mases_core/src/Domains/Finance/Tests/Feature/GovernancePenaltySettlementAccountingTest.php`
- `mases_core/src/Domains/Finance/Tests/Feature/GovernancePenaltySettlementAuthorizationTest.php`
- `mases_core/src/Domains/Finance/Tests/Feature/GovernancePenaltySettlementServiceTest.php`
- `mases_core/src/Domains/Finance/Tests/Unit/FinanceCalendarDateAdapterTest.php`
- `mases_core/src/Domains/Finance/Tests/Unit/FinanceCurrencyConverterTest.php`
- `mases_core/src/Domains/Finance/Tests/Unit/FinanceIntelligenceBoundaryTest.php`
- `mases_core/src/Domains/Finance/Tests/Unit/FinanceMoneyArithmeticTest.php`
- `mases_core/src/Domains/Finance/Tests/Unit/FinancePermissionCatalogueTest.php`
- `mases_core/src/Domains/Governance/Config/governance.php`
- `mases_core/src/Domains/Governance/Database/Migrations/2026_09_18_100000_create_governance_bodies_table.php`
- `mases_core/src/Domains/Governance/Database/Migrations/2026_09_18_110000_create_governance_positions_table.php`
- `mases_core/src/Domains/Governance/Database/Migrations/2026_09_18_120000_create_governance_terms_table.php`
- `mases_core/src/Domains/Governance/Database/Migrations/2026_09_18_130000_create_governance_appointments_table.php`
- `mases_core/src/Domains/Governance/Database/Migrations/2026_09_18_140000_create_governance_memberships_table.php`
- `mases_core/src/Domains/Governance/Database/Migrations/2026_10_27_120000_create_governance_authority_grants_table.php`
- `mases_core/src/Domains/Governance/Database/Migrations/2026_10_27_130000_create_governance_seats_and_acting_assignments_tables.php`
- `mases_core/src/Domains/Governance/Database/Migrations/2026_11_02_100000_create_governance_meeting_attendance_tables.php`
- `mases_core/src/Domains/Governance/Database/Migrations/2026_11_02_110000_create_governance_attendance_review_tables.php`
- `mases_core/src/Domains/Governance/Database/Migrations/2026_11_17_100000_create_election_committee_security_foundation.php`
- `mases_core/src/Domains/Governance/Database/Migrations/2026_11_18_100000_create_governance_elections_foundation.php`
- `mases_core/src/Domains/Governance/Database/Migrations/2026_11_18_110000_create_election_candidate_intake.php`
- `mases_core/src/Domains/Governance/Database/Migrations/2026_11_18_110100_create_election_nomination_events.php`
- `mases_core/src/Domains/Governance/Database/Migrations/2026_11_18_110200_create_nomination_window_events.php`
- `mases_core/src/Domains/Governance/Database/Migrations/2026_11_20_160000_create_election_authority_and_committee_appointments.php`
- `mases_core/src/Domains/Governance/Database/Seeders/GovernanceApplicationSeeder.php`
- `mases_core/src/Domains/Governance/Database/Seeders/GovernanceElectionCommitteePermissionsSeeder.php`
- `mases_core/src/Domains/Governance/Database/Seeders/GovernancePermissionsSeeder.php`
- `mases_core/src/Domains/Governance/Database/Seeders/GovernanceRolesSeeder.php`
- `mases_core/src/Domains/Governance/Database/Seeders/GovernanceSeeder.php`
- `mases_core/src/Domains/Governance/Enums/GovernanceAppointmentInstrumentType.php`
- `mases_core/src/Domains/Governance/Enums/GovernanceAppointmentMode.php`
- `mases_core/src/Domains/Governance/Enums/GovernanceAppointmentStatus.php`
- `mases_core/src/Domains/Governance/Enums/GovernanceBodyType.php`
- `mases_core/src/Domains/Governance/Enums/GovernanceMembershipStatus.php`
- `mases_core/src/Domains/Governance/Events/GovernanceAppointmentRegistered.php`
- `mases_core/src/Domains/Governance/Events/GovernanceAppointmentUpdated.php`
- `mases_core/src/Domains/Governance/Events/GovernanceBodyRegistered.php`
- `mases_core/src/Domains/Governance/Events/GovernanceBodyUpdated.php`
- `mases_core/src/Domains/Governance/Events/GovernanceElectionLifecycleChanged.php`
- `mases_core/src/Domains/Governance/Events/GovernanceMembershipRegistered.php`
- `mases_core/src/Domains/Governance/Events/GovernanceMembershipUpdated.php`
- `mases_core/src/Domains/Governance/Events/GovernancePositionRegistered.php`
- `mases_core/src/Domains/Governance/Events/GovernancePositionUpdated.php`
- `mases_core/src/Domains/Governance/Events/GovernanceTermRegistered.php`
- `mases_core/src/Domains/Governance/Events/GovernanceTermUpdated.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceAppointmentController.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceAuthorityGrantController.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceBodyController.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionAuthorityController.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionCandidateController.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionCommitteeAppointmentController.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionCommitteeSecurityController.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionController.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceMeetingAttendanceController.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceMembershipController.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernancePingController.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernancePositionController.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceSeatController.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceTermController.php`
- `mases_core/src/Domains/Governance/Http/Requests/StoreGovernanceAppointmentRequest.php`
- `mases_core/src/Domains/Governance/Http/Requests/StoreGovernanceBodyRequest.php`
- `mases_core/src/Domains/Governance/Http/Requests/StoreGovernanceMembershipRequest.php`
- `mases_core/src/Domains/Governance/Http/Requests/StoreGovernancePositionRequest.php`
- `mases_core/src/Domains/Governance/Http/Requests/StoreGovernanceTermRequest.php`
- `mases_core/src/Domains/Governance/Http/Requests/UpdateGovernanceAppointmentRequest.php`
- `mases_core/src/Domains/Governance/Http/Requests/UpdateGovernanceBodyRequest.php`
- `mases_core/src/Domains/Governance/Http/Requests/UpdateGovernanceMembershipRequest.php`
- `mases_core/src/Domains/Governance/Http/Requests/UpdateGovernancePositionRequest.php`
- `mases_core/src/Domains/Governance/Http/Requests/UpdateGovernanceTermRequest.php`
- `mases_core/src/Domains/Governance/Http/Resources/Concerns/RendersLocalizedDates.php`
- `mases_core/src/Domains/Governance/Http/Resources/GovernanceAppointmentResource.php`
- `mases_core/src/Domains/Governance/Http/Resources/GovernanceBodyResource.php`
- `mases_core/src/Domains/Governance/Http/Resources/GovernanceMembershipResource.php`
- `mases_core/src/Domains/Governance/Http/Resources/GovernancePositionResource.php`
- `mases_core/src/Domains/Governance/Http/Resources/GovernanceTermResource.php`
- `mases_core/src/Domains/Governance/Listeners/AuditGovernanceElectionLifecycleEvent.php`
- `mases_core/src/Domains/Governance/Listeners/AuditGovernanceEvent.php`
- `mases_core/src/Domains/Governance/Models/GovernanceActingAssignment.php`
- `mases_core/src/Domains/Governance/Models/GovernanceAppointment.php`
- `mases_core/src/Domains/Governance/Models/GovernanceAuthorityGrant.php`
- `mases_core/src/Domains/Governance/Models/GovernanceBody.php`
- `mases_core/src/Domains/Governance/Models/GovernanceElection.php`
- `mases_core/src/Domains/Governance/Models/GovernanceElectionAuthorityEvidence.php`
- `mases_core/src/Domains/Governance/Models/GovernanceElectionCandidate.php`
- `mases_core/src/Domains/Governance/Models/GovernanceElectionCandidateDecision.php`
- `mases_core/src/Domains/Governance/Models/GovernanceElectionCandidateEvent.php`
- `mases_core/src/Domains/Governance/Models/GovernanceElectionCandidateEvidence.php`
- `mases_core/src/Domains/Governance/Models/GovernanceElectionCommitteeMember.php`
- `mases_core/src/Domains/Governance/Models/GovernanceElectionLifecycleTransition.php`
- `mases_core/src/Domains/Governance/Models/GovernanceElectionNominationEvent.php`
- `mases_core/src/Domains/Governance/Models/GovernanceElectionNominationSubmission.php`
- `mases_core/src/Domains/Governance/Models/GovernanceElectionNominationWindow.php`
- `mases_core/src/Domains/Governance/Models/GovernanceMembership.php`
- `mases_core/src/Domains/Governance/Models/GovernancePosition.php`
- `mases_core/src/Domains/Governance/Models/GovernanceSeat.php`
- `mases_core/src/Domains/Governance/Models/GovernanceSeatOccupancy.php`
- `mases_core/src/Domains/Governance/Models/GovernanceTerm.php`
- `mases_core/src/Domains/Governance/Policies/GovernanceAppointmentPolicy.php`
- `mases_core/src/Domains/Governance/Policies/GovernanceBodyPolicy.php`
- `mases_core/src/Domains/Governance/Policies/GovernanceMembershipPolicy.php`
- `mases_core/src/Domains/Governance/Policies/GovernancePositionPolicy.php`
- `mases_core/src/Domains/Governance/Policies/GovernanceTermPolicy.php`
- `mases_core/src/Domains/Governance/Providers/GovernanceServiceProvider.php`
- `mases_core/src/Domains/Governance/Repositories/GovernanceAppointmentRepository.php`
- `mases_core/src/Domains/Governance/Repositories/GovernanceBodyRepository.php`
- `mases_core/src/Domains/Governance/Repositories/GovernanceMembershipRepository.php`
- `mases_core/src/Domains/Governance/Repositories/GovernancePositionRepository.php`
- `mases_core/src/Domains/Governance/Repositories/GovernanceTermRepository.php`
- `mases_core/src/Domains/Governance/Resources/lang/am/common.php`
- `mases_core/src/Domains/Governance/Resources/lang/en/common.php`
- `mases_core/src/Domains/Governance/Routes/api.php`
- `mases_core/src/Domains/Governance/Services/GovernanceActingAssignmentService.php`
- `mases_core/src/Domains/Governance/Services/GovernanceAppointmentService.php`
- `mases_core/src/Domains/Governance/Services/GovernanceAuthorityGrantService.php`
- `mases_core/src/Domains/Governance/Services/GovernanceBodyService.php`
- `mases_core/src/Domains/Governance/Services/GovernanceElectionAuthorityService.php`
- `mases_core/src/Domains/Governance/Services/GovernanceElectionCandidateService.php`
- `mases_core/src/Domains/Governance/Services/GovernanceElectionCommitteeAppointmentService.php`
- `mases_core/src/Domains/Governance/Services/GovernanceElectionCommitteeSecurityService.php`
- `mases_core/src/Domains/Governance/Services/GovernanceElectionLifecycleService.php`
- `mases_core/src/Domains/Governance/Services/GovernanceMembershipService.php`
- `mases_core/src/Domains/Governance/Services/GovernancePositionService.php`
- `mases_core/src/Domains/Governance/Services/GovernanceSeatService.php`
- `mases_core/src/Domains/Governance/Services/GovernanceTermService.php`
- `mases_core/src/Domains/Governance/Tests/Feature/GovernanceAppointmentTest.php`
- `mases_core/src/Domains/Governance/Tests/Feature/GovernanceAuthorityGrantTest.php`
- `mases_core/src/Domains/Governance/Tests/Feature/GovernanceBodyTest.php`
- `mases_core/src/Domains/Governance/Tests/Feature/GovernanceElectionCandidateIntakeTest.php`
- `mases_core/src/Domains/Governance/Tests/Feature/GovernanceElectionCommitteeSecurityTest.php`
- `mases_core/src/Domains/Governance/Tests/Feature/GovernanceElectionLifecycleTest.php`
- `mases_core/src/Domains/Governance/Tests/Feature/GovernanceElectionNominationConcurrencyTest.php`
- `mases_core/src/Domains/Governance/Tests/Feature/GovernanceMeetingAttendanceTest.php`
- `mases_core/src/Domains/Governance/Tests/Feature/GovernanceMembershipTest.php`
- `mases_core/src/Domains/Governance/Tests/Feature/GovernancePositionTest.php`
- `mases_core/src/Domains/Governance/Tests/Feature/GovernanceTermTest.php`
- `mases_core/src/Domains/Loan/Config/loan.php`
- `mases_core/src/Domains/Loan/Console/Commands/AccrueInterestCommand.php`
- `mases_core/src/Domains/Loan/Console/Commands/DetectArrearsCommand.php`
- `mases_core/src/Domains/Loan/Database/Migrations/2026_09_12_200010_create_loan_products_table.php`
- `mases_core/src/Domains/Loan/Database/Migrations/2026_09_12_200020_create_loans_table.php`
- `mases_core/src/Domains/Loan/Database/Migrations/2026_09_12_200030_create_loan_installments_table.php`
- `mases_core/src/Domains/Loan/Database/Migrations/2026_09_12_200040_create_loan_collaterals_table.php`
- `mases_core/src/Domains/Loan/Database/Migrations/2026_09_12_200050_create_loan_restructurings_table.php`
- `mases_core/src/Domains/Loan/Database/Migrations/2026_09_12_200060_create_loan_write_offs_table.php`
- `mases_core/src/Domains/Loan/Database/Migrations/2026_09_12_200070_create_loan_status_histories_table.php`
- `mases_core/src/Domains/Loan/Database/Migrations/2026_09_12_200080_create_loan_interest_accruals_table.php`
- `mases_core/src/Domains/Loan/Database/Migrations/2026_09_12_200090_create_loan_penalty_accruals_table.php`
- `mases_core/src/Domains/Loan/Database/Seeders/LoanPermissionsSeeder.php`
- `mases_core/src/Domains/Loan/Database/Seeders/LoanProductRulesPolicySeeder.php`
- `mases_core/src/Domains/Loan/Database/Seeders/LoanProductsSeeder.php`
- `mases_core/src/Domains/Loan/Database/Seeders/LoanRolesSeeder.php`
- `mases_core/src/Domains/Loan/Enums/CollateralStatus.php`
- `mases_core/src/Domains/Loan/Enums/CollateralType.php`
- `mases_core/src/Domains/Loan/Enums/InstallmentStatus.php`
- `mases_core/src/Domains/Loan/Enums/LoanStatus.php`
- `mases_core/src/Domains/Loan/Events/LoanApplicationSubmitted.php`
- `mases_core/src/Domains/Loan/Events/LoanApproved.php`
- `mases_core/src/Domains/Loan/Events/LoanClosed.php`
- `mases_core/src/Domains/Loan/Events/LoanCollateralReleased.php`
- `mases_core/src/Domains/Loan/Events/LoanDisbursed.php`
- `mases_core/src/Domains/Loan/Events/LoanEnteredArrears.php`
- `mases_core/src/Domains/Loan/Events/LoanInstallmentSettled.php`
- `mases_core/src/Domains/Loan/Events/LoanPenaltyRecorded.php`
- `mases_core/src/Domains/Loan/Events/LoanRejected.php`
- `mases_core/src/Domains/Loan/Events/LoanRepaymentRecorded.php`
- `mases_core/src/Domains/Loan/Events/LoanRestructured.php`
- `mases_core/src/Domains/Loan/Events/LoanSettled.php`
- `mases_core/src/Domains/Loan/Events/LoanWrittenOff.php`
- `mases_core/src/Domains/Loan/Http/Controllers/LoanCollateralController.php`
- `mases_core/src/Domains/Loan/Http/Controllers/LoanController.php`
- `mases_core/src/Domains/Loan/Http/Controllers/LoanProductController.php`
- `mases_core/src/Domains/Loan/Http/Controllers/LoanReconciliationController.php`
- `mases_core/src/Domains/Loan/Http/Controllers/LoanRepaymentController.php`
- `mases_core/src/Domains/Loan/Http/Controllers/LoanScheduleController.php`
- `mases_core/src/Domains/Loan/Http/Controllers/LoanStatementController.php`
- `mases_core/src/Domains/Loan/Http/Controllers/MyLoanController.php`
- `mases_core/src/Domains/Loan/Http/Requests/ApproveLoanRequest.php`
- `mases_core/src/Domains/Loan/Http/Requests/DisburseLoanRequest.php`
- `mases_core/src/Domains/Loan/Http/Requests/RejectLoanRequest.php`
- `mases_core/src/Domains/Loan/Http/Requests/ReleaseCollateralRequest.php`
- `mases_core/src/Domains/Loan/Http/Requests/StoreCollateralRequest.php`
- `mases_core/src/Domains/Loan/Http/Requests/StoreLoanRequest.php`
- `mases_core/src/Domains/Loan/Http/Requests/StoreRepaymentRequest.php`
- `mases_core/src/Domains/Loan/Http/Requests/StoreRestructureRequest.php`
- `mases_core/src/Domains/Loan/Http/Requests/StoreWriteOffRequest.php`
- `mases_core/src/Domains/Loan/Http/Resources/LoanCollateralResource.php`
- `mases_core/src/Domains/Loan/Http/Resources/LoanInstallmentResource.php`
- `mases_core/src/Domains/Loan/Http/Resources/LoanProductResource.php`
- `mases_core/src/Domains/Loan/Http/Resources/LoanReconciliationResource.php`
- `mases_core/src/Domains/Loan/Http/Resources/LoanResource.php`
- `mases_core/src/Domains/Loan/Http/Resources/LoanScheduleResource.php`
- `mases_core/src/Domains/Loan/Http/Resources/LoanStatementResource.php`
- `mases_core/src/Domains/Loan/Http/Resources/LoanStatusHistoryResource.php`
- `mases_core/src/Domains/Loan/Listeners/AuditLoanEvent.php`
- `mases_core/src/Domains/Loan/Models/Loan.php`
- `mases_core/src/Domains/Loan/Models/LoanCollateral.php`
- `mases_core/src/Domains/Loan/Models/LoanInstallment.php`
- `mases_core/src/Domains/Loan/Models/LoanInterestAccrual.php`
- `mases_core/src/Domains/Loan/Models/LoanPenaltyAccrual.php`
- `mases_core/src/Domains/Loan/Models/LoanProduct.php`
- `mases_core/src/Domains/Loan/Models/LoanRestructuring.php`
- `mases_core/src/Domains/Loan/Models/LoanStatusHistory.php`
- `mases_core/src/Domains/Loan/Models/LoanWriteOff.php`
- `mases_core/src/Domains/Loan/Policies/LoanCollateralPolicy.php`
- `mases_core/src/Domains/Loan/Policies/LoanPolicy.php`
- `mases_core/src/Domains/Loan/Policies/LoanProductPolicy.php`
- `mases_core/src/Domains/Loan/Providers/LoanServiceProvider.php`
- `mases_core/src/Domains/Loan/Repositories/LoanAccrualRepository.php`
- `mases_core/src/Domains/Loan/Repositories/LoanCollateralRepository.php`
- `mases_core/src/Domains/Loan/Repositories/LoanInstallmentRepository.php`
- `mases_core/src/Domains/Loan/Repositories/LoanProductRepository.php`
- `mases_core/src/Domains/Loan/Repositories/LoanRepository.php`
- `mases_core/src/Domains/Loan/Routes/api.php`
- `mases_core/src/Domains/Loan/Services/LoanApplicationService.php`
- `mases_core/src/Domains/Loan/Services/LoanApprovalService.php`
- `mases_core/src/Domains/Loan/Services/LoanArrearsService.php`
- `mases_core/src/Domains/Loan/Services/LoanCollateralService.php`
- `mases_core/src/Domains/Loan/Services/LoanDisbursementService.php`
- `mases_core/src/Domains/Loan/Services/LoanInterestAccrualService.php`
- `mases_core/src/Domains/Loan/Services/LoanReconciliationService.php`
- `mases_core/src/Domains/Loan/Services/LoanRepaymentService.php`
- `mases_core/src/Domains/Loan/Services/LoanRestructuringService.php`
- `mases_core/src/Domains/Loan/Services/LoanScheduleService.php`
- `mases_core/src/Domains/Loan/Services/LoanStateMachine.php`
- `mases_core/src/Domains/Loan/Services/LoanWriteOffService.php`
- `mases_core/src/Domains/Loan/Support/AmortizationCalculator.php`
- `mases_core/src/Domains/Loan/Support/LoanPolicyKeys.php`
- `mases_core/src/Domains/Loan/Tests/Feature/LoanApplicationTest.php`
- `mases_core/src/Domains/Loan/Tests/Feature/LoanApprovalTest.php`
- `mases_core/src/Domains/Loan/Tests/Feature/LoanCollateralTest.php`
- `mases_core/src/Domains/Loan/Tests/Feature/LoanDisbursementTest.php`
- `mases_core/src/Domains/Loan/Tests/Feature/LoanInterestAccrualTest.php`
- `mases_core/src/Domains/Loan/Tests/Feature/LoanProductTest.php`
- `mases_core/src/Domains/Loan/Tests/Feature/LoanReconciliationTest.php`
- `mases_core/src/Domains/Loan/Tests/Feature/LoanRepaymentTest.php`
- `mases_core/src/Domains/Loan/Tests/Feature/LoanScheduleTest.php`
- `mases_core/src/Domains/Loan/Tests/Feature/LoanStatementTest.php`
- `mases_core/src/Domains/Loan/Tests/Feature/TenantIsolationTest.php`
- `mases_core/src/Domains/Loan/Tests/Unit/AmortizationCalculatorTest.php`
- `mases_core/src/Domains/Loan/Tests/Unit/LoanApplicationServiceTest.php`
- `mases_core/src/Domains/Loan/Tests/Unit/LoanApprovalServiceTest.php`
- `mases_core/src/Domains/Loan/Tests/Unit/LoanArrearsServiceTest.php`
- `mases_core/src/Domains/Loan/Tests/Unit/LoanDisbursementServiceTest.php`
- `mases_core/src/Domains/Loan/Tests/Unit/LoanInterestAccrualServiceTest.php`
- `mases_core/src/Domains/Loan/Tests/Unit/LoanReconciliationServiceTest.php`
- `mases_core/src/Domains/Loan/Tests/Unit/LoanRepaymentServiceTest.php`
- `mases_core/src/Domains/Loan/Tests/Unit/LoanRestructuringServiceTest.php`
- `mases_core/src/Domains/Loan/Tests/Unit/LoanScheduleServiceTest.php`
- `mases_core/src/Domains/Loan/Tests/Unit/LoanStateMachineTest.php`
- `mases_core/src/Domains/Loan/Tests/Unit/LoanWriteOffServiceTest.php`
- `mases_core/src/Domains/Organization/Http/Controllers/OrganizationController.php`
- `mases_core/src/Domains/Organization/Models/OrganizationAssignment.php`
- `mases_core/src/Domains/Organization/Models/OrganizationAuditEvent.php`
- `mases_core/src/Domains/Organization/Models/OrganizationUnit.php`
- `mases_core/src/Domains/Organization/Policies/OrganizationAssignmentPolicy.php`
- `mases_core/src/Domains/Organization/Policies/OrganizationHierarchyPolicy.php`
- `mases_core/src/Domains/Organization/Policies/OrganizationUnitPolicy.php`
- `mases_core/src/Domains/People/Models/EmployeeRecord.php`
- `mases_core/src/Domains/People/Models/Person.php`
- `mases_core/src/Domains/People/Models/PersonDocumentLink.php`
- `mases_core/src/Domains/People/Models/PersonTenantAccess.php`
- `mases_core/src/Domains/People/Policies/PersonPolicy.php`
- `mases_core/src/Domains/People/Services/PersonIdentityService.php`
- `mases_core/src/Domains/People/Services/WorkforceAggregateProvider.php`
- `mases_core/src/Domains/PublicContent/Models/CorporateSiteEntry.php`
- `mases_core/src/Domains/PublicContent/Models/CorporateSiteInquiry.php`
- `mases_core/src/Domains/PublicContent/Models/CorporateSitePage.php`
- `mases_core/src/Domains/PublicContent/Models/PublicContent.php`
- `mases_core/src/Domains/Shareholder/Config/shareholder.php`
- `mases_core/src/Domains/Shareholder/Database/Migrations/2026_09_11_000001_create_share_classes_table.php`
- `mases_core/src/Domains/Shareholder/Database/Migrations/2026_09_11_000002_create_shareholders_table.php`
- `mases_core/src/Domains/Shareholder/Database/Migrations/2026_09_11_000003_create_share_holdings_table.php`
- `mases_core/src/Domains/Shareholder/Database/Migrations/2026_09_11_000004_create_share_holding_entries_table.php`
- `mases_core/src/Domains/Shareholder/Database/Migrations/2026_09_11_000005_create_share_certificates_table.php`
- `mases_core/src/Domains/Shareholder/Database/Migrations/2026_10_21_000001_reconcile_existing_shareholder_numbers.php`
- `mases_core/src/Domains/Shareholder/Database/Seeders/ShareClassSeeder.php`
- `mases_core/src/Domains/Shareholder/Database/Seeders/ShareholderPermissionsSeeder.php`
- `mases_core/src/Domains/Shareholder/Database/Seeders/ShareholderRolesSeeder.php`
- `mases_core/src/Domains/Shareholder/Enums/CertificateStatus.php`
- `mases_core/src/Domains/Shareholder/Enums/HoldingDirection.php`
- `mases_core/src/Domains/Shareholder/Enums/ShareholderType.php`
- `mases_core/src/Domains/Shareholder/Events/CertificateIssued.php`
- `mases_core/src/Domains/Shareholder/Events/ShareHoldingChanged.php`
- `mases_core/src/Domains/Shareholder/Events/ShareholderRegistered.php`
- `mases_core/src/Domains/Shareholder/Events/ShareholderUpdated.php`
- `mases_core/src/Domains/Shareholder/Http/Controllers/CertificateController.php`
- `mases_core/src/Domains/Shareholder/Http/Controllers/MyShareholderController.php`
- `mases_core/src/Domains/Shareholder/Http/Controllers/ShareClassController.php`
- `mases_core/src/Domains/Shareholder/Http/Controllers/ShareHoldingController.php`
- `mases_core/src/Domains/Shareholder/Http/Controllers/ShareholderController.php`
- `mases_core/src/Domains/Shareholder/Http/Requests/StoreCertificateRequest.php`
- `mases_core/src/Domains/Shareholder/Http/Requests/StoreShareClassRequest.php`
- `mases_core/src/Domains/Shareholder/Http/Requests/StoreShareHoldingRequest.php`
- `mases_core/src/Domains/Shareholder/Http/Requests/StoreShareholderRequest.php`
- `mases_core/src/Domains/Shareholder/Http/Requests/UpdateShareholderRequest.php`
- `mases_core/src/Domains/Shareholder/Http/Resources/Concerns/RendersLocalizedDates.php`
- `mases_core/src/Domains/Shareholder/Http/Resources/ShareCertificateResource.php`
- `mases_core/src/Domains/Shareholder/Http/Resources/ShareClassResource.php`
- `mases_core/src/Domains/Shareholder/Http/Resources/ShareHoldingResource.php`
- `mases_core/src/Domains/Shareholder/Http/Resources/ShareholderResource.php`
- `mases_core/src/Domains/Shareholder/Listeners/AuditShareholderEvent.php`
- `mases_core/src/Domains/Shareholder/Models/ShareCertificate.php`
- `mases_core/src/Domains/Shareholder/Models/ShareClass.php`
- `mases_core/src/Domains/Shareholder/Models/ShareHolding.php`
- `mases_core/src/Domains/Shareholder/Models/ShareHoldingEntry.php`
- `mases_core/src/Domains/Shareholder/Models/Shareholder.php`
- `mases_core/src/Domains/Shareholder/Models/ShareholderImportDecision.php`
- `mases_core/src/Domains/Shareholder/Models/ShareholderImportSubmission.php`
- `mases_core/src/Domains/Shareholder/Policies/CertificatePolicy.php`
- `mases_core/src/Domains/Shareholder/Policies/ShareClassPolicy.php`
- `mases_core/src/Domains/Shareholder/Policies/ShareHoldingPolicy.php`
- `mases_core/src/Domains/Shareholder/Policies/ShareholderPolicy.php`
- `mases_core/src/Domains/Shareholder/Providers/ShareholderServiceProvider.php`
- `mases_core/src/Domains/Shareholder/Repositories/ShareholderRepository.php`
- `mases_core/src/Domains/Shareholder/Resources/lang/am/status.php`
- `mases_core/src/Domains/Shareholder/Resources/lang/am/type.php`
- `mases_core/src/Domains/Shareholder/Resources/lang/en/status.php`
- `mases_core/src/Domains/Shareholder/Resources/lang/en/type.php`
- `mases_core/src/Domains/Shareholder/Routes/api.php`
- `mases_core/src/Domains/Shareholder/Services/CertificateService.php`
- `mases_core/src/Domains/Shareholder/Services/ShareClassService.php`
- `mases_core/src/Domains/Shareholder/Services/ShareHoldingService.php`
- `mases_core/src/Domains/Shareholder/Services/ShareholderCsvPreviewService.php`
- `mases_core/src/Domains/Shareholder/Services/ShareholderImportPreviewValidator.php`
- `mases_core/src/Domains/Shareholder/Services/ShareholderImportReviewPolicy.php`
- `mases_core/src/Domains/Shareholder/Services/ShareholderImportReviewService.php`
- `mases_core/src/Domains/Shareholder/Services/ShareholderNumberReconciler.php`
- `mases_core/src/Domains/Shareholder/Services/ShareholderService.php`
- `mases_core/src/Domains/Shareholder/Tests/Feature/CertificateTest.php`
- `mases_core/src/Domains/Shareholder/Tests/Feature/ShareHoldingTest.php`
- `mases_core/src/Domains/Shareholder/Tests/Feature/ShareholderRegistryTest.php`
- `mases_core/src/Domains/Shareholder/Tests/Feature/TenantIsolationTest.php`
- `mases_core/src/Domains/Shareholder/Tests/Unit/ShareHoldingServiceTest.php`
- `mases_core/src/Domains/Shareholder/Tests/Unit/ShareholderImportPreviewServiceTest.php`
- `mases_core/src/Domains/Shareholder/Tests/Unit/ShareholderImportReviewPolicyTest.php`
- `mases_core/src/Domains/Shareholder/Tests/Unit/ShareholderServiceTest.php`
- `mases_core/src/Domains/Task/Http/Controllers/TaskController.php`
- `mases_core/src/Domains/Task/Models/Task.php`
- `mases_core/src/Domains/Task/Policies/TaskPolicy.php`
- `mases_core/src/Domains/Task/Services/TaskService.php`
- `mases_core/src/Domains/Wallet/Config/wallet.php`
- `mases_core/src/Domains/Wallet/Database/Migrations/2026_09_11_100010_create_wallets_table.php`
- `mases_core/src/Domains/Wallet/Database/Migrations/2026_09_11_100020_create_wallet_obligations_table.php`
- `mases_core/src/Domains/Wallet/Database/Migrations/2026_09_11_100030_create_wallet_payments_table.php`
- `mases_core/src/Domains/Wallet/Database/Migrations/2026_09_11_100040_create_wallet_payment_allocations_table.php`
- `mases_core/src/Domains/Wallet/Database/Migrations/2026_09_11_100050_create_wallet_payment_allocation_reversals_table.php`
- `mases_core/src/Domains/Wallet/Database/Migrations/2026_09_11_100060_create_wallet_entries_table.php`
- `mases_core/src/Domains/Wallet/Database/Seeders/WalletPermissionsSeeder.php`
- `mases_core/src/Domains/Wallet/Database/Seeders/WalletRolesSeeder.php`
- `mases_core/src/Domains/Wallet/Enums/ObligationComponent.php`
- `mases_core/src/Domains/Wallet/Enums/WalletEntryType.php`
- `mases_core/src/Domains/Wallet/Events/ObligationCancelled.php`
- `mases_core/src/Domains/Wallet/Events/ObligationRecorded.php`
- `mases_core/src/Domains/Wallet/Events/ObligationWrittenOff.php`
- `mases_core/src/Domains/Wallet/Events/PaymentAllocated.php`
- `mases_core/src/Domains/Wallet/Events/PaymentAllocationReversed.php`
- `mases_core/src/Domains/Wallet/Events/PaymentReceived.php`
- `mases_core/src/Domains/Wallet/Events/WalletAdjusted.php`
- `mases_core/src/Domains/Wallet/Events/WalletEntryReversed.php`
- `mases_core/src/Domains/Wallet/Events/WalletOpened.php`
- `mases_core/src/Domains/Wallet/Http/Controllers/AllocationController.php`
- `mases_core/src/Domains/Wallet/Http/Controllers/MyWalletController.php`
- `mases_core/src/Domains/Wallet/Http/Controllers/ObligationController.php`
- `mases_core/src/Domains/Wallet/Http/Controllers/PaymentController.php`
- `mases_core/src/Domains/Wallet/Http/Controllers/StatementController.php`
- `mases_core/src/Domains/Wallet/Http/Controllers/WalletController.php`
- `mases_core/src/Domains/Wallet/Http/Requests/AdjustWalletRequest.php`
- `mases_core/src/Domains/Wallet/Http/Requests/OpenWalletRequest.php`
- `mases_core/src/Domains/Wallet/Http/Requests/ReverseAllocationRequest.php`
- `mases_core/src/Domains/Wallet/Http/Requests/StoreAllocationRequest.php`
- `mases_core/src/Domains/Wallet/Http/Requests/StoreObligationRequest.php`
- `mases_core/src/Domains/Wallet/Http/Requests/StorePaymentRequest.php`
- `mases_core/src/Domains/Wallet/Http/Resources/ObligationResource.php`
- `mases_core/src/Domains/Wallet/Http/Resources/PaymentAllocationResource.php`
- `mases_core/src/Domains/Wallet/Http/Resources/PaymentAllocationReversalResource.php`
- `mases_core/src/Domains/Wallet/Http/Resources/PaymentResource.php`
- `mases_core/src/Domains/Wallet/Http/Resources/WalletEntryResource.php`
- `mases_core/src/Domains/Wallet/Http/Resources/WalletResource.php`
- `mases_core/src/Domains/Wallet/Listeners/AuditWalletEvent.php`
- `mases_core/src/Domains/Wallet/Models/Obligation.php`
- `mases_core/src/Domains/Wallet/Models/Payment.php`
- `mases_core/src/Domains/Wallet/Models/PaymentAllocation.php`
- `mases_core/src/Domains/Wallet/Models/PaymentAllocationReversal.php`
- `mases_core/src/Domains/Wallet/Models/Wallet.php`
- `mases_core/src/Domains/Wallet/Models/WalletEntry.php`
- `mases_core/src/Domains/Wallet/Policies/ObligationPolicy.php`
- `mases_core/src/Domains/Wallet/Policies/PaymentPolicy.php`
- `mases_core/src/Domains/Wallet/Policies/WalletPolicy.php`
- `mases_core/src/Domains/Wallet/Providers/WalletServiceProvider.php`
- `mases_core/src/Domains/Wallet/Repositories/AllocationRepository.php`
- `mases_core/src/Domains/Wallet/Repositories/ObligationRepository.php`
- `mases_core/src/Domains/Wallet/Repositories/PaymentRepository.php`
- `mases_core/src/Domains/Wallet/Repositories/WalletRepository.php`
- `mases_core/src/Domains/Wallet/Routes/api.php`
- `mases_core/src/Domains/Wallet/Services/AllocationService.php`
- `mases_core/src/Domains/Wallet/Services/ObligationService.php`
- `mases_core/src/Domains/Wallet/Services/PaymentService.php`
- `mases_core/src/Domains/Wallet/Services/StatementService.php`
- `mases_core/src/Domains/Wallet/Services/WalletService.php`
- `mases_core/src/Domains/Wallet/Tests/Feature/AllocationTest.php`
- `mases_core/src/Domains/Wallet/Tests/Feature/ObligationTest.php`
- `mases_core/src/Domains/Wallet/Tests/Feature/PaymentTest.php`
- `mases_core/src/Domains/Wallet/Tests/Feature/StatementTest.php`
- `mases_core/src/Domains/Wallet/Tests/Feature/TenantIsolationTest.php`
- `mases_core/src/Domains/Wallet/Tests/Feature/WalletTest.php`
- `mases_core/src/Domains/Wallet/Tests/Unit/AllocationServiceTest.php`
- `mases_core/src/Domains/Wallet/Tests/Unit/ObligationServiceTest.php`
- `mases_core/src/Domains/Wallet/Tests/Unit/PaymentServiceTest.php`
- `mases_core/src/Domains/Wallet/Tests/Unit/StatementServiceTest.php`
- `mases_core/src/Domains/Wallet/Tests/Unit/WalletServiceTest.php`
- `mases_core/src/Events/AuthorizationFailureEvent.php`
- `mases_core/src/Exceptions/AuthenticationException.php`
- `mases_core/src/Exceptions/AuthorizationException.php`
- `mases_core/src/Exceptions/CoreException.php`
- `mases_core/src/Exceptions/ErrorCodeRegistry.php`
- `mases_core/src/Exceptions/FileException.php`
- `mases_core/src/Exceptions/TenantException.php`
- `mases_core/src/Files/Models/File.php`
- `mases_core/src/Files/Services/FileService.php`
- `mases_core/src/Listeners/InvalidateAuthorizationCacheListener.php`
- `mases_core/src/Listeners/LogAuthorizationFailureListener.php`
- `mases_core/src/Localization/Calendar/Contracts/CalendarInstant.php`
- `mases_core/src/Localization/Calendar/Contracts/LocalDate.php`
- `mases_core/src/Localization/Calendar/EthiopianCalendar.php`
- `mases_core/src/Localization/Calendar/EthiopianDate.php`
- `mases_core/src/Localization/EthiopianCalendar.php`
- `mases_core/src/Localization/EthiopianDateTime.php`
- `mases_core/src/Localization/EthiopianFormatter.php`
- `mases_core/src/Localization/LocaleManager.php`
- `mases_core/src/Localization/LocalizationFacade.php`
- `mases_core/src/Localization/LocalizationManager.php`
- `mases_core/src/Localization/Middleware/LocalizationMiddleware.php`
- `mases_core/src/Localization/Services/LocalizationService.php`
- `mases_core/src/Localization/TimeZoneManager.php`
- `mases_core/src/Middleware/LocaleMiddleware.php`
- `mases_core/src/Migrations/.gitkeep`
- `mases_core/src/Migrations/2026_11_01_000001_create_calendar_core_tables.php`
- `mases_core/src/Migrations/2026_11_01_000002_create_calendar_lifecycle_tables.php`
- `mases_core/src/Migrations/2026_11_11_000003_extend_calendar_reminder_occurrences.php`
- `mases_core/src/Migrations/2026_11_12_000004_rekey_calendar_recurring_reminders.php`
- `mases_core/src/Monitoring/ErrorDiagnosticEngine.php`
- `mases_core/src/Notifications/Models/PlatformNotification.php`
- `mases_core/src/Notifications/Services/NotificationService.php`
- `mases_core/src/Platform/Contracts/TenantContextResolverInterface.php`
- `mases_core/src/Policy/Config/policy.php`
- `mases_core/src/Policy/Database/Migrations/2026_09_11_100001_create_versioned_policies_table.php`
- `mases_core/src/Policy/Models/VersionedPolicy.php`
- `mases_core/src/Policy/Providers/PolicyServiceProvider.php`
- `mases_core/src/Policy/Services/VersionedPolicyRegistry.php`
- `mases_core/src/Providers/CoreServiceProvider.php`
- `mases_core/src/Providers/LocalizationServiceProvider.php`
- `mases_core/src/Providers/MasesCoreServiceProvider.php`
- `mases_core/src/Security/Models/SecurityEvent.php`
- `mases_core/src/Security/Services/SecurityEventService.php`
- `mases_core/src/Support/Helpers.php`
- `mases_core/src/Tenancy/Middleware/TenantContextMiddleware.php`
- `mases_core/src/Tenancy/Middleware/TenantMembershipMiddleware.php`
- `mases_core/src/Tenancy/Models/Tenant.php`
- `mases_core/src/Tenancy/Models/TenantMembership.php`
- `mases_core/src/Tenancy/Services/RequestTenantResolver.php`
- `mases_core/src/Tenancy/Services/TenantContextService.php`
- `mases_core/src/Tenancy/Services/TenantMembershipService.php`

## 2. Core boot and package integration

| Path | Verified reference / behavior | A3 impact |
|---|---|---|
| `composer.json` | Package `maale-arna/mases-api`; description says MASES Core; PSR-4 `MaaleArna\\MASES\\Core\\ => mases_core/src/`; Composer `autoload.files` points to `mases_core/src/Support/Helpers.php`. | Update package metadata, PSR-4, and helper file path together. |
| `bootstrap/providers.php` | Actual returned array registers Core, Auth, Shareholder, Policy, Wallet, Loan, Governance and Finance providers. It does **not** register LocalizationServiceProvider or MasesCoreServiceProvider, despite stale comments that imply LocalizationServiceProvider runs. | Update eight registered provider FQCNs; reconcile stale comments; do not activate dormant providers. |
| `mases_core/src/Providers/CoreServiceProvider.php` | Active provider merges `mases_core/src/Config/mases.php` as `mases`, loads migrations via `base_path('mases_core/src/Migrations')`, publishes tag `mases-config`, binds alias `mases.authorization`, and reads `mases.localization.timezone`. | Update absolute source path, config filename/key, publish tag, alias and config consumers. Relative `__DIR__` path derivation moves with the tree. |
| `mases_core/src/Providers/LocalizationServiceProvider.php` | Class exists and declares alias `mases.localization`, but is absent from the actual provider array. | Dormant provider; classify its services and references before retaining or retiring. Do not register by assumption. |
| `mases_core/src/Providers/MasesCoreServiceProvider.php` | Legacy duplicate provider; not registered; comments identify duplicate bindings and stale migration paths. | Dormant artifact; do not register. Decide retain/archive/delete only after reference review. |
| `bootstrap/app.php` | Imports Core exceptions/diagnostics and middleware; declares three global `mases_*` functions: `mases_resolve_error`, `mases_resolve_request_id`, `mases_is_api_request`. | Update imports, function names and all call sites. |
| `config/auth.php` | Imports `MaaleArna\\MASES\\Core\\Auth\\Models\\User`. | Update auth model class reference. |
| `config/app.php` | Reads `MASES_ENABLE_DEMO_SEEDING`. | Rename env contract and guard consumer. |
| `phpunit.xml` | Uses old Core test discovery/coverage paths, Helpers.php path, old `AUTH_MODEL` FQCN and MASES environment variables. | Update suite, coverage, bootstrap, auth model and env fixtures. |
| `rector.php` | Scope includes `mases_core/src/Domains`. | Update static-analysis scope. |
| `app/Console/Commands/MasesRectorVerifyCommand.php` | Class/signature `MasesRectorVerifyCommand` / `mases:rector:verify`; scans old domain path. No explicit registration was found in `bootstrap/app.php`; command availability needs verification before treating it as an active operator interface. | Rename/reconcile class, signature and scan path if retained. |
| `database/seeders/MasesCoreSeeder.php` | Active seeder class; `DatabaseSeeder::run()` calls it. Other references are found in seeders, tests, docs and workflows. | Rename file/class and every call site as one unit. |
| `database/factories/MaaleArna/MASES/Core/Authorization/Models/PermissionFactory.php` | Factory namespace and model import contain old namespace. | Move factory namespace/path and update model import. |
| `scripts/phase0-authorization-audit.py` | Hard-codes old namespace prefix and `mases_core/src` path. | Update namespace resolver and source path. |
| `scripts/verify_document_number_concurrency.php` | Imports Core Documents allocator and Tenant classes using old namespace. | Update imports. |
| Other tracked scripts | `scripts/arna_progress_assessment.py`, `scripts/production-env-preflight.php`, `scripts/a5-php-runtime-diagnostic.php` returned no MASES/path matches in the inspected content. | Keep unless full scan discovers indirect references. |

## 3. Registered and dormant provider classification

**Registered in the actual `bootstrap/providers.php` array:**
1. `MaaleArna\\MASES\\Core\\Providers\\CoreServiceProvider`
2. `MaaleArna\\MASES\\Core\\Auth\\Providers\\AuthServiceProvider`
3. `MaaleArna\\MASES\\Core\\Domains\\Shareholder\\Providers\\ShareholderServiceProvider`
4. `MaaleArna\\MASES\\Core\\Policy\\Providers\\PolicyServiceProvider`
5. `MaaleArna\\MASES\\Core\\Domains\\Wallet\\Providers\\WalletServiceProvider`
6. `MaaleArna\\MASES\\Core\\Domains\\Loan\\Providers\\LoanServiceProvider`
7. `MaaleArna\\MASES\\Core\\Domains\\Governance\\Providers\\GovernanceServiceProvider`
8. `MaaleArna\\MASES\\Core\\Domains\\Finance\\Providers\\FinanceServiceProvider`

**Present but not registered:**
- `LocalizationServiceProvider` — contains a second localization service cluster and `mases.localization` alias; registry comments are stale.
- `MasesCoreServiceProvider` — overlapping bindings and stale migration locations; registry explicitly says not to register it.

## 4. Global helper inventory

Thirteen functions are declared in `mases_core/src/Support/Helpers.php`:
- `mases_is_non_http_context()`
- `mases_tenant_id()`
- `mases_tenant()`
- `mases_is_tenant_context()`
- `mases_is_tenant_isolated()`
- `mases_user()`
- `mases_locale()`
- `mases_environment()`
- `mases_is_production()`
- `mases_is_staging()`
- `mases_is_local()`
- `mases_request_id()`
- `mases_log_context()`

Three additional functions are declared in `bootstrap/app.php`:
- `mases_resolve_error()`
- `mases_resolve_request_id()`
- `mases_is_api_request()`

The indexed search for `mases_tenant_id` returns **89 distinct paths**. All returned paths are recorded below. This is the complete result set returned by the connected repository code-search endpoint for that query; it is not a substitute for a final whole-tree grep at the frozen implementation SHA.

## 5. Config, alias, locale, seeder and migration-path search results

### Search `mases_tenant_id` — 89 distinct paths
- `mases_core/src/Support/Helpers.php`
- `mases_core/src/Audit/Traits/LogsAudit.php`
- `app/Http/Controllers/PersonController.php`
- `app/Http/Middleware/RequireAdaptiveStepUp.php`
- `mases_core/src/Audit/Listeners/LogLogout.php`
- `mases_core/src/Audit/Listeners/LogFailedLogin.php`
- `mases_core/src/Domains/Finance/Policies/FinancePolicy.php`
- `app/Http/Controllers/FileController.php`
- `mases_core/src/Domains/Loan/Policies/LoanProductPolicy.php`
- `bootstrap/app.php`
- `mases_core/src/Authorization/Middleware/RoleMiddleware.php`
- `mases_core/src/Domains/Loan/Http/Controllers/LoanProductController.php`
- `mases_core/src/Domains/Organization/Policies/OrganizationUnitPolicy.php`
- `mases_core/src/Authorization/Middleware/ApplicationAccessMiddleware.php`
- `mases_core/src/Authorization/Middleware/PermissionMiddleware.php`
- `mases_core/src/Domains/Organization/Policies/OrganizationAssignmentPolicy.php`
- `mases_core/src/Audit/Services/AuditService.php`
- `mases_core/src/Domains/Loan/Http/Controllers/LoanReconciliationController.php`
- `app/Http/Controllers/TenantContextController.php`
- `mases_core/src/Authorization/Middleware/CheckPermission.php`
- `app/Http/Controllers/Auth/AuthController.php`
- `mases_core/src/Domains/Finance/Http/Controllers/FinanceSubledgerInboxController.php`
- `mases_core/src/Events/AuthorizationFailureEvent.php`
- `mases_core/src/Domains/Shareholder/Policies/ShareClassPolicy.php`
- `mases_core/src/Domains/Wallet/Config/wallet.php`
- `mases_core/src/Domains/Finance/Http/Controllers/FinanceIntegrationHealthController.php`
- `mases_core/src/Domains/Finance/Http/Controllers/FinanceIntelligenceController.php`
- `mases_core/src/Domains/Shareholder/Policies/ShareholderPolicy.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceAuthorityGrantController.php`
- `mases_core/src/Domains/Shareholder/Policies/ShareHoldingPolicy.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernancePingController.php`
- `mases_core/src/Domains/Wallet/Policies/WalletPolicy.php`
- `mases_core/src/Domains/Shareholder/Policies/CertificatePolicy.php`
- `mases_core/src/Domains/Loan/Config/loan.php`
- `app/Http/Controllers/Admin/AdminAuditSecurityController.php`
- `mases_core/src/Domains/Wallet/Routes/api.php`
- `mases_core/src/Domains/Loan/Policies/LoanPolicy.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionAuthorityController.php`
- `app/Http/Controllers/ExecutiveOverviewController.php`
- `mases_core/src/Authorization/Services/AuthorizationService.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionController.php`
- `mases_core/src/Domains/Loan/Http/Controllers/MyLoanController.php`
- `mases_core/src/Security/Services/SecurityEventService.php`
- `mases_core/src/Domains/Governance/Policies/GovernanceTermPolicy.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceSeatController.php`
- `mases_core/src/Domains/Governance/Policies/GovernanceBodyPolicy.php`
- `mases_core/src/Domains/Finance/Services/SourceLegalEntityMappingService.php`
- `mases_core/src/Domains/Wallet/Http/Controllers/ObligationController.php`
- `mases_core/src/Domains/Wallet/Http/Controllers/MyWalletController.php`
- `mases_core/src/Domains/Wallet/Http/Controllers/WalletController.php`
- `mases_core/src/Auth/Services/AuthenticationService.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionCommitteeAppointmentController.php`
- `mases_core/src/Domains/Wallet/Http/Controllers/PaymentController.php`
- `mases_core/src/Domains/Loan/Http/Controllers/LoanScheduleController.php`
- `mases_core/src/Domains/Wallet/Http/Controllers/StatementController.php`
- `mases_core/src/Domains/Governance/Policies/GovernancePositionPolicy.php`
- `mases_core/src/Domains/Shareholder/Http/Controllers/ShareholderController.php`
- `mases_core/src/Domains/Shareholder/Http/Controllers/MyShareholderController.php`
- `mases_core/src/Domains/Loan/Http/Controllers/LoanController.php`
- `mases_core/src/Domains/Governance/Policies/GovernanceMembershipPolicy.php`
- `mases_core/src/Domains/FileManagement/Services/FileService.php`
- `mases_core/src/Domains/Governance/Policies/GovernanceAppointmentPolicy.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionCommitteeSecurityController.php`
- `mases_core/src/Domains/Loan/Http/Controllers/LoanRepaymentController.php`
- `mases_core/src/Domains/Wallet/Http/Controllers/AllocationController.php`
- `mases_core/src/Authorization/Middleware/AuthorizationMiddleware.php`
- `mases_core/src/Domains/Loan/Http/Controllers/LoanCollateralController.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceBodyController.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceTermController.php`
- `mases_core/src/Domains/Shareholder/Http/Controllers/CertificateController.php`
- `mases_core/src/Domains/Shareholder/Http/Controllers/ShareHoldingController.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionCandidateController.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceMembershipController.php`
- `mases_core/src/Domains/Governance/Services/GovernanceAuthorityGrantService.php`
- `mases_core/src/Domains/Governance/Http/Requests/UpdateGovernanceMembershipRequest.php`
- `mases_core/src/Domains/Loan/Http/Controllers/LoanStatementController.php`
- `mases_core/src/Domains/Governance/Http/Requests/UpdateGovernanceAppointmentRequest.php`
- `mases_core/src/Domains/Shareholder/Repositories/ShareholderRepository.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceAppointmentController.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernancePositionController.php`
- `mases_core/src/Domains/Governance/Services/GovernanceElectionLifecycleService.php`
- `mases_core/src/Domains/Governance/Http/Controllers/GovernanceMeetingAttendanceController.php`
- `tests/Feature/C09/TenantResolutionTest.php`
- `tests/Feature/DocumentWorkflowAggregateServiceTest.php`
- `mases_core/src/Domains/Shareholder/Tests/Feature/CertificateTest.php`
- `mases_core/src/Domains/Shareholder/Tests/Feature/TenantIsolationTest.php`
- `mases_core/src/Domains/Loan/Tests/Feature/LoanApplicationTest.php`
- `mases_core/src/Domains/Wallet/Tests/Feature/TenantIsolationTest.php`
- `mases_core/src/Domains/Governance/Tests/Feature/GovernanceBodyTest.php`

### Search `config mases.` — 47 distinct paths
- `bootstrap/providers.php`
- `mases_core/src/Localization/Services/LocalizationService.php`
- `mases_core/src/Support/Helpers.php`
- `docs/PHASE_1_GATE_REPORT.md`
- `app/Providers/AppServiceProvider.php`
- `mases_core/src/Audit/Traits/LogsAudit.php`
- `mases_core/src/Providers/CoreServiceProvider.php`
- `mases_core/src/Providers/MasesCoreServiceProvider.php`
- `mases_core/src/Providers/LocalizationServiceProvider.php`
- `mases_core/src/Tenancy/Services/RequestTenantResolver.php`
- `app/Http/Controllers/FileController.php`
- `mases_core/src/Authorization/Middleware/RoleMiddleware.php`
- `mases_core/src/Listeners/LogAuthorizationFailureListener.php`
- `mases_core/src/Authorization/Middleware/PermissionMiddleware.php`
- `mases_core/src/Authorization/Middleware/AuthorizationMiddleware.php`
- `mases_core/src/Audit/Services/AuditService.php`
- `mases_core/src/Domains/FileManagement/Services/FileService.php`
- `mases_core/src/Domains/Documents/Services/ImmutableDocumentBinaryStore.php`
- `mases_core/src/Auth/Services/AuthenticationService.php`
- `mases_core/src/Domains/Shareholder/Enums/CertificateStatus.php`
- `docs/architecture/file-management-domain-boundary-a3-1.md`
- `mases_core/src/Authorization/Services/AuthorizationService.php`
- `mases_core/src/Security/Services/SecurityEventService.php`
- `mases_core/src/Domains/Governance/Database/Migrations/2026_09_18_140000_create_governance_memberships_table.php`
- `tests/Unit/TenantResolverTest.php`
- `tests/Feature/TenantIsolationTest.php`
- `tests/Feature/TenantMembershipTest.php`
- `tests/Feature/ApiErrorContractTest.php`
- `tests/Feature/AdminAuditSecurityTest.php`
- `tests/Feature/TenantRequiredRouteTest.php`
- `tests/Feature/CentralNotificationsTest.php`
- `tests/Feature/AuthorizationFailureEventTest.php`
- `tests/Feature/Phase0AuthorizationDenialTest.php`
- `tests/Unit/LocalizationServiceTest.php`
- `tests/Feature/D08TaskManagementTest.php`
- `tests/Feature/RbacAuthorizationTest.php`
- `tests/Unit/Documents/ImmutableDocumentBinaryStoreSecurityTest.php`
- `tests/Feature/AuditSecurityTest.php`
- `tests/Feature/FileSecurityTest.php`
- `tests/Feature/CommunicationAnnouncementsTest.php`
- `tests/Feature/CalendarHttpAuthorizationTest.php`
- `tests/Feature/DocumentWorkflowMySqlConcurrencyTest.php`
- `tests/Feature/CalendarAppointmentBookingTest.php`
- `mases_core/src/Domains/Governance/Tests/Feature/GovernanceElectionLifecycleTest.php`
- `mases_core/src/Domains/Finance/Tests/Feature/GovernancePenaltySettlementAuthorizationTest.php`
- `mases_core/src/Domains/Governance/Tests/Feature/GovernanceBodyTest.php`
- `mases_core/src/Domains/Finance/Tests/Feature/GovernancePenaltySettlementAccountingTest.php`

### Search `mases.localization` — 9 distinct paths
- `mases_core/src/Localization/LocalizationFacade.php`
- `bootstrap/providers.php`
- `mases_core/src/Providers/LocalizationServiceProvider.php`
- `mases_core/src/Support/Helpers.php`
- `mases_core/src/Providers/CoreServiceProvider.php`
- `mases_core/src/Auth/Services/AuthenticationService.php`
- `mases_core/src/Localization/Services/LocalizationService.php`
- `mases_core/src/Domains/Shareholder/Http/Resources/Concerns/RendersLocalizedDates.php`
- `tests/Unit/LocalizationServiceTest.php`

### Search `mases_localization` — 5 distinct paths
- `mases_core/src/Localization/TimeZoneManager.php`
- `mases_core/src/Localization/EthiopianFormatter.php`
- `mases_core/src/Providers/MasesCoreServiceProvider.php`
- `mases_core/src/Localization/LocaleManager.php`
- `mases_core/src/Localization/EthiopianCalendar.php`

### Search `mases_locale` — 4 distinct paths
- `mases_core/src/Support/Helpers.php`
- `mases_core/src/Localization/LocaleManager.php`
- `mases_core/src/Localization/Services/LocalizationService.php`
- `tests/Unit/LocalizationServiceTest.php`

### Search `mases-config` — 3 distinct paths
- `mases_core/src/Providers/MasesCoreServiceProvider.php`
- `bootstrap/providers.php`
- `mases_core/src/Providers/CoreServiceProvider.php`

### Search `MasesCoreSeeder` — 18 distinct paths
- `database/seeders/MasesCoreSeeder.php`
- `database/seeders/DatabaseSeeder.php`
- `database/seeders/RoleSeeder.php`
- `docs/security/P0.9-CREDENTIAL-ROTATION.md`
- `docs/PHASE_1_GATE_REPORT.md`
- `database/seeders/AdminUserSeeder.php`
- `database/seeders/RolePermissionSeeder.php`
- `database/seeders/ShareholderSeeder.php`
- `database/seeders/Data/ApplicationRegistry.php`
- `mases_core/src/Domains/Finance/Documentation/ARNA-COA-v1.0.0.md`
- `mases_core/src/Domains/Wallet/Database/Seeders/WalletRolesSeeder.php`
- `mases_core/src/Domains/Shareholder/Database/Seeders/ShareholderRolesSeeder.php`
- `mases_core/src/Domains/Governance/Database/Seeders/GovernanceRolesSeeder.php`
- `mases_core/src/Domains/Governance/Database/Seeders/GovernancePermissionsSeeder.php`
- `tests/Unit/DemoSeederGuardTest.php`
- `tests/Feature/ShareholderSeedNumberInitializationTest.php`
- `.github/workflows/a3-3-1-exact-sha-verification.yml`
- `.github/workflows/arna-governance-body-regression.yml`

### Search `mases_core/src/Migrations` — 8 distinct paths
- `docs/PHASE_1_GATE_REPORT.md`
- `docs/PHASE_2_SHAREHOLDER_CHARTER.md`
- `bootstrap/providers.php`
- `docs/audit/REPOSITORY_AUDIT_A1_BASELINE_2026-11-04.md`
- `mases_core/src/Providers/CoreServiceProvider.php`
- `docs/calendar/apt-02/ARNA_APT02_A2_READ_ONLY_RECONCILIATION.md`
- `docs/audit/REPOSITORY_AUDIT_A2_DEPENDENCY_RECONCILIATION_2026-11-04.md`
- `.github/workflows/arna-clean-install-mysql.yml`

### Search `base_path mases_core/src` — 5 distinct paths
- `mases_core/src/Providers/CoreServiceProvider.php`
- `app/Console/Commands/MasesRectorVerifyCommand.php`
- `tests/Feature/ShareholderMigrationDiscoveryTest.php`
- `tests/Feature/ShareholderNumberReconciliationTest.php`
- `tests/Feature/LoanRepaymentIdempotencyMigrationDiscoveryTest.php`

## 6. Environment variables and config namespaces

The unique `MASES_*` environment names found in `mases_core/src/Config/mases.php`, `config/app.php` and `phpunit.xml` are:

- `MASES_APP_NAME`
- `MASES_CORS_ALLOWED_ORIGINS`
- `MASES_DEFAULT_LOCALE`
- `MASES_DEFAULT_TENANT_ID`
- `MASES_DOCUMENT_EVIDENCE_DISK`
- `MASES_ENABLE_DEMO_SEEDING`
- `MASES_ENV`
- `MASES_FALLBACK_LOCALE`
- `MASES_FEATURE_API_VERSIONING`
- `MASES_FEATURE_AUDIT`
- `MASES_FEATURE_MFA_REQUIRED`
- `MASES_FEATURE_TENANT_LOGGING`
- `MASES_FILE_DISK`
- `MASES_MAX_UPLOAD_SIZE`
- `MASES_PASSWORD_RESET_EXPIRY`
- `MASES_RATE_LIMIT`
- `MASES_SESSION_LIFETIME`
- `MASES_TENANT_ISOLATION`

The active Core config file has root groups `name`, `env`, `tenant`, `applications`, `security`, `localization`, `files`, `documents` and `features`, exposed under `mases.*`. The distinct old config name `mases_localization` occurs in the dormant LocaleManager/TimeZoneManager cluster and dormant MasesCoreServiceProvider. The active `LocalizationService` uses `mases.localization.timezone`.

Tracked `.env.example` and `.env.production.example` contain no `MASES_*` settings. The live Ethiotelecom Plesk `.env` was not accessible, so actual production values and any custom `SANCTUM_TOKEN_PREFIX` remain UNKNOWN. `config/sanctum.php` contains only a comment recommending `mases_`; the actual setting is `SANCTUM_TOKEN_PREFIX` and defaults to an empty string.

## 7. CI/workflow path consumers

The following **27 of 39** tracked workflows contain old Core source/test/seeder paths, explicit PHP lint/test commands, or path filters. Every path filter and command must be updated; otherwise workflows can silently stop running after the directory move.

- `.github/workflows/a3-3-1-exact-sha-verification.yml`
- `.github/workflows/arna-a3-3-2-document-issuance-mysql.yml`
- `.github/workflows/arna-a4-evidence-remediation.yml`
- `.github/workflows/arna-adaptive-auth-policy.yml`
- `.github/workflows/arna-admin-audit-security.yml`
- `.github/workflows/arna-clean-install-mysql.yml`
- `.github/workflows/arna-d08-task-management.yml`
- `.github/workflows/arna-device-session-pin.yml`
- `.github/workflows/arna-documents-numbering-a4.yml`
- `.github/workflows/arna-employee-id-login.yml`
- `.github/workflows/arna-g01-tenant-isolation-exact-sha.yml`
- `.github/workflows/arna-governance-body-regression.yml`
- `.github/workflows/arna-membership-authorization-reconcile.yml`
- `.github/workflows/arna-module2-current-main-integration.yml`
- `.github/workflows/arna-phase0-effective-authorization.yml`
- `.github/workflows/arna-phone-verification-core.yml`
- `.github/workflows/arna-pr23-exact-head-regression.yml`
- `.github/workflows/arna-pr5-tenant-regression.yml`
- `.github/workflows/arna-shareholder-import-preview.yml`
- `.github/workflows/arna-shareholder-seed-number-initialization.yml`
- `.github/workflows/arna-totp-mfa-database-compatibility.yml`
- `.github/workflows/arna-totp-mfa.yml`
- `.github/workflows/arna-u3-focused-verification.yml`
- `.github/workflows/arna-wallet-account-number-login.yml`
- `.github/workflows/corporate-home-invariant.yml`
- `.github/workflows/executive-overview-a4-api.yml`
- `.github/workflows/public-certificate-verification.yml`

## 8. Test inventory

- 156 PHP test files total: 64 under `mases_core/src/Domains/**/Tests` and 92 under `tests/`.
- `phpunit.xml` discovers domain tests from `mases_core/src/Domains`, includes Core source in coverage, and bootstraps the old Helpers.php path.
- Indexed old-tenant helper references include 7 test files; indexed old config namespace references include multiple root and domain tests; seeder references include `tests/Unit/DemoSeederGuardTest.php` and `tests/Feature/ShareholderSeedNumberInitializationTest.php`.
- The 64 domain test files physically move with the source tree. Root tests stay in `tests/` but imports, FQCNs, config, helper, seeder and path assertions must be reconciled.
- No PHPUnit, syntax, Rector, Composer, Laravel bootstrap or database test was run during A2.

## 9. External consumer — `akefadaka-git/arna-app`

App main baseline: `71bed2158894e3ce9cf507e8bf70492ac1f9dfd4`.

**No PHP package dependency was found:** App search for `mases-api` returned 0 paths; search for `mases_core` returned only one historical dashboard document; no App PHP import of `MaaleArna\\MASES\\Core\\` was found. The App is nevertheless a real integration consumer of API authentication/localization and a separate consumer of the legacy brand/token namespace.

Verified App integration references:
- `src/core/api/client.ts` documents the bearer-token SPA contract and explains that `mases_locale` session/cookie sources are unavailable to the SPA; it sends `Accept-Language`.
- API `mases_core/src/Localization/Services/LocalizationService.php` actively uses session/cookie key `mases_locale`; the API LocalizationMiddleware is registered in `bootstrap/app.php`. This is an API runtime contract for non-SPA/legacy clients and needs a coordinated key migration or explicit compatibility period.
- Browser storage fallback keys are `mases.locale`, `mases.tenant_id`, `mases.auth_token`, and `mases.auth.remembered_identifier`. The App reads old values as migration fallbacks, writes ARNA keys and removes old keys. These are active migration-compatibility references, not new writes.
- `src/styles/tokens.css` contains **273 `--mases-*` definitions** and **273 `--arna-*` definitions**, of which 242 ARNA definitions alias old MASES variables. App search found `mases-color` in 91 distinct paths, `mases-space` in 77, and `mases-font` in 79 (sets overlap). Components consume the old token namespace, so removing it requires coordinated token inversion and component/test updates.
- App tests `tests/brand-identity-contract.test.mjs` and `tests/design-system-theme-contract.test.mjs` explicitly assert legacy storage fallback and old token names. These tests must change if the no-MASES requirement applies to active frontend compatibility identifiers.
- App `README.md` states ARNA is canonical and MASES is retained as the historical/original system name. The exported brand object in `src/router/shell-labels.ts` currently uses `productName: 'ARNA'` and full identity ending in `(ARNA)`; `.env.workspace`, `.env.portal` and `.env.admin` use ARNA app names. Many source comments and architecture documents still mention the former name.
- App search results for the old CSS namespace and localStorage keys are listed below.

### App search `mases-color` — 91 distinct paths
- `src/styles/themes.css`
- `src/features/people/PeopleDirectoryView.vue`
- `src/router/pages/AdminAuditSecurityRoute.vue`
- `src/router/pages/PublicVerificationRoute.vue`
- `src/router/pages/AdminPublicContentRoute.vue`
- `src/features/dashboard/DashboardView.vue`
- `src/components/patterns/Timeline/Timeline.vue`
- `src/components/primitives/BaseBadge/BaseBadge.vue`
- `src/features/notifications/NotificationsCenter.vue`
- `src/features/governance/GovernanceOverviewView.vue`
- `src/features/portal/PortalHomePage.vue`
- `src/components/primitives/BaseLabel/BaseLabel.vue`
- `src/components/patterns/FilePreview/FilePreview.vue`
- `src/styles/tokens.css`
- `src/router/pages/ForgotPasswordRoute.vue`
- `src/router/pages/ResetPasswordRoute.vue`
- `src/features/people/PeopleProfileView.vue`
- `src/features/governance/GovernancePersonProfileView.vue`
- `src/router/pages/LegalCenterRoute.vue`
- `src/router/pages/VerifyEmailRoute.vue`
- `src/features/executive-overview/ExecutiveOverviewView.vue`
- `src/features/governance/GovernancePeopleDirectoryView.vue`
- `src/router/pages/VerifyEmailResendRoute.vue`
- `src/router/pages/ErrorRoute.vue`
- `src/features/loans/LoanRepaymentForm.vue`
- `src/router/pages/RegisterRoute.vue`
- `src/features/loans/LoanProductListView.vue`
- `src/layouts/AppShell/AppHeaderNavigation/AppHeaderNavigation.vue`
- `src/features/auth/AuthLayout/AuthLayout.vue`
- `src/features/governance/GovernanceAttendanceView.vue`
- `src/features/wallet/WalletListView.vue`
- `src/components/primitives/LinkButton/LinkButton.vue`
- `src/router/pages/LegalDocumentRoute.vue`
- `src/features/auth/VerifyEmailPanel/VerifyEmailPanel.vue`
- `src/components/primitives/ErrorSupportHint/ErrorSupportHint.vue`
- `src/router/pages/LoginRoute.vue`
- `src/features/loans/LoanListView.vue`
- `src/features/wallet/WalletAllocationReverseDialog.vue`
- `src/features/loans/LoanScheduleView.vue`
- `src/features/portal/MyWalletPage.vue`
- `src/components/patterns/ErrorShell/ErrorShell.vue`
- `src/features/wallet/WalletDetailView.vue`
- `src/features/portal/MyLoanDetailPage.vue`
- `src/features/governance/GovernanceBodyListView.vue`
- `src/components/composites/MetaList/MetaList.vue`
- `src/features/portal/MyShareholdingsPage.vue`
- `src/features/files/FileDetailView/FileDetailView.vue`
- `src/features/auth/RegisterForm/RegisterForm.vue`
- `src/features/loans/LoanStatementView.vue`
- `src/features/loans/LoanWriteOffForm.vue`
- `src/features/loans/LoanDetailView.vue`
- `src/features/governance/GovernanceBodyDetailView.vue`
- `src/features/portal/MyLoansPage.vue`
- `src/features/wallet/WalletStatementView.vue`
- `src/features/auth/ForgotPasswordForm/ForgotPasswordForm.vue`
- `src/features/loans/LoanRejectForm.vue`
- `src/features/loans/LoanApproveForm.vue`
- `src/components/composites/ErrorHeader/ErrorHeader.vue`
- `src/features/wallet/WalletOpenForm.vue`
- `src/features/loans/LoanDisburseForm.vue`
- `src/components/patterns/DetailCard/DetailCard.vue`
- `src/features/wallet/WalletAdjustForm.vue`
- `src/features/auth/VerifyEmailResend/VerifyEmailResend.vue`
- `src/features/auth/ResetPasswordForm/ResetPasswordForm.vue`
- `src/features/shareholders/CertificateCancelDialog/CertificateCancelDialog.vue`
- `src/features/loans/LoanCollateralForm.vue`
- `src/components/composites/LoadingState/LoadingState.vue`
- `src/components/primitives/BaseAvatar/BaseAvatar.vue`
- `public/favicon.svg`
- `src/features/wallet/WalletPaymentForm.vue`
- `src/features/files/FilesListView/FilesListView.vue`
- `src/components/primitives/GhostButton/GhostButton.vue`
- `src/features/loans/LoanCollateralReleaseForm.vue`
- `src/features/loans/LoanRestructureForm.vue`
- `src/features/loans/LoanApplicationForm.vue`
- `src/features/wallet/WalletObligationForm.vue`
- `src/features/wallet/WalletAllocationForm.vue`
- `src/components/primitives/DangerButton/DangerButton.vue`
- `src/components/patterns/ErrorPage/ErrorPage.vue`
- `src/components/primitives/BaseDivider/BaseDivider.vue`
- `src/components/primitives/SecondaryButton/SecondaryButton.vue`
- `src/layouts/AppShell/NotificationsBell/NotificationsBell.vue`
- `src/components/composites/ConfirmDialog/ConfirmDialog.vue`
- `src/features/shareholders/ShareholderForm/ShareholderForm.vue`
- `src/features/shareholders/ShareholderDetailView/ShareholderDetailView.vue`
- `src/features/shareholders/ShareholderListView/ShareholderListView.vue`
- `src/features/shareholders/HoldingEntryForm/HoldingEntryForm.vue`
- `src/features/shareholders/ShareClassListView/ShareClassListView.vue`
- `src/features/shareholders/CertificateIssueForm/CertificateIssueForm.vue`
- `tests/design-system-theme-contract.test.mjs`
- `tests/brand-identity-contract.test.mjs`

### App search `mases-space` — 77 distinct paths
- `src/router/pages/ErrorRoute.vue`
- `src/features/people/PeopleDirectoryView.vue`
- `src/router/pages/AdminAuditSecurityRoute.vue`
- `src/router/pages/PublicVerificationRoute.vue`
- `src/router/pages/AdminPublicContentRoute.vue`
- `src/features/dashboard/DashboardView.vue`
- `src/components/patterns/Timeline/Timeline.vue`
- `src/components/patterns/ErrorShell/ErrorShell.vue`
- `src/components/primitives/BaseBadge/BaseBadge.vue`
- `src/features/governance/GovernanceOverviewView.vue`
- `src/components/primitives/BaseAvatar/BaseAvatar.vue`
- `src/features/portal/PortalHomePage.vue`
- `src/styles/tokens.css`
- `src/components/primitives/BaseDivider/BaseDivider.vue`
- `src/features/people/PeopleProfileView.vue`
- `src/router/pages/LegalCenterRoute.vue`
- `src/components/patterns/FilePreview/FilePreview.vue`
- `src/features/executive-overview/ExecutiveOverviewView.vue`
- `src/features/wallet/WalletAllocationReverseDialog.vue`
- `src/layouts/AppShell/NotificationsBell/NotificationsBell.vue`
- `src/features/loans/LoanRepaymentForm.vue`
- `src/components/composites/MetaList/MetaList.vue`
- `src/features/loans/LoanProductListView.vue`
- `src/layouts/AppShell/AppHeaderNavigation/AppHeaderNavigation.vue`
- `src/features/auth/AuthLayout/AuthLayout.vue`
- `src/features/wallet/WalletOpenForm.vue`
- `src/components/patterns/ErrorPage/ErrorPage.vue`
- `src/features/wallet/WalletListView.vue`
- `src/features/loans/LoanRejectForm.vue`
- `src/features/loans/LoanApproveForm.vue`
- `src/components/composites/LoadingState/LoadingState.vue`
- `src/features/loans/LoanDisburseForm.vue`
- `src/features/portal/MyWalletPage.vue`
- `src/router/pages/LegalDocumentRoute.vue`
- `src/features/wallet/WalletAdjustForm.vue`
- `src/features/wallet/WalletStatementView.vue`
- `src/components/patterns/DetailCard/DetailCard.vue`
- `src/features/loans/LoanListView.vue`
- `src/features/auth/ForgotPasswordForm/ForgotPasswordForm.vue`
- `src/features/auth/VerifyEmailResend/VerifyEmailResend.vue`
- `src/features/portal/MyLoanDetailPage.vue`
- `src/features/loans/LoanWriteOffForm.vue`
- `src/features/wallet/WalletDetailView.vue`
- `src/features/portal/MyShareholdingsPage.vue`
- `src/features/loans/LoanScheduleView.vue`
- `src/features/wallet/WalletPaymentForm.vue`
- `src/features/wallet/WalletObligationForm.vue`
- `src/features/loans/LoanStatementView.vue`
- `src/features/shareholders/CertificateCancelDialog/CertificateCancelDialog.vue`
- `src/features/wallet/WalletAllocationForm.vue`
- `src/features/governance/GovernancePeopleDirectoryView.vue`
- `src/features/files/FileDetailView/FileDetailView.vue`
- `src/features/loans/LoanDetailView.vue`
- `src/features/loans/LoanApplicationForm.vue`
- `src/features/loans/LoanCollateralForm.vue`
- `src/features/loans/LoanRestructureForm.vue`
- `src/features/loans/LoanCollateralReleaseForm.vue`
- `src/features/auth/RegisterForm/RegisterForm.vue`
- `src/features/portal/MyLoansPage.vue`
- `src/features/governance/GovernanceBodyListView.vue`
- `src/features/portal/MyCertificatesPage.vue`
- `src/components/composites/ErrorHeader/ErrorHeader.vue`
- `src/features/governance/GovernanceBodyDetailView.vue`
- `src/features/auth/VerifyEmailPanel/VerifyEmailPanel.vue`
- `src/features/files/FilesListView/FilesListView.vue`
- `src/features/shareholders/ShareholderForm/ShareholderForm.vue`
- `src/features/auth/ResetPasswordForm/ResetPasswordForm.vue`
- `src/features/governance/GovernancePersonProfileView.vue`
- `src/components/primitives/BaseLabel/BaseLabel.vue`
- `src/features/shareholders/HoldingEntryForm/HoldingEntryForm.vue`
- `src/features/shareholders/CertificateIssueForm/CertificateIssueForm.vue`
- `src/features/shareholders/ShareholderDetailView/ShareholderDetailView.vue`
- `src/components/patterns/DownloadButton/DownloadButton.vue`
- `src/components/primitives/ErrorSupportHint/ErrorSupportHint.vue`
- `src/features/shareholders/ShareholderListView/ShareholderListView.vue`
- `src/features/shareholders/ShareClassListView/ShareClassListView.vue`
- `tests/brand-identity-contract.test.mjs`

### App search `mases-font` — 79 distinct paths
- `src/styles/themes.css`
- `src/router/pages/ForgotPasswordRoute.vue`
- `src/features/portal/PortalHomePage.vue`
- `src/router/pages/ResetPasswordRoute.vue`
- `src/features/people/PeopleDirectoryView.vue`
- `src/router/pages/PublicVerificationRoute.vue`
- `src/router/pages/VerifyEmailResendRoute.vue`
- `src/router/pages/VerifyEmailRoute.vue`
- `src/features/governance/GovernanceOverviewView.vue`
- `src/styles/tokens.css`
- `src/router/pages/AdminPublicContentRoute.vue`
- `src/router/pages/LegalCenterRoute.vue`
- `src/features/executive-overview/ExecutiveOverviewView.vue`
- `src/router/pages/RegisterRoute.vue`
- `src/features/dashboard/DashboardView.vue`
- `src/components/composites/LoadingState/LoadingState.vue`
- `src/features/loans/LoanScheduleView.vue`
- `src/features/portal/MyWalletPage.vue`
- `src/components/patterns/Timeline/Timeline.vue`
- `src/components/patterns/FilePreview/FilePreview.vue`
- `src/router/pages/LoginRoute.vue`
- `src/components/composites/MetaList/MetaList.vue`
- `src/features/loans/LoanProductListView.vue`
- `src/features/portal/MyLoanDetailPage.vue`
- `src/components/composites/ErrorHeader/ErrorHeader.vue`
- `src/components/primitives/BaseAvatar/BaseAvatar.vue`
- `src/components/primitives/BaseLabel/BaseLabel.vue`
- `src/features/loans/LoanStatementView.vue`
- `src/router/pages/LegalDocumentRoute.vue`
- `src/router/pages/AdminAuditSecurityRoute.vue`
- `src/components/patterns/DetailCard/DetailCard.vue`
- `src/router/pages/ErrorRoute.vue`
- `src/components/primitives/BaseBadge/BaseBadge.vue`
- `src/features/wallet/WalletDetailView.vue`
- `src/features/wallet/WalletStatementView.vue`
- `src/features/loans/LoanRepaymentForm.vue`
- `src/layouts/AppShell/AppHeaderNavigation/AppHeaderNavigation.vue`
- `src/features/loans/LoanDetailView.vue`
- `src/features/people/PeopleProfileView.vue`
- `src/features/files/FileDetailView/FileDetailView.vue`
- `src/features/wallet/WalletListView.vue`
- `src/features/wallet/WalletAllocationReverseDialog.vue`
- `src/features/governance/GovernanceBodyDetailView.vue`
- `src/features/loans/LoanListView.vue`
- `src/features/loans/LoanWriteOffForm.vue`
- `src/features/portal/MyLoansPage.vue`
- `src/features/auth/AuthLayout/AuthLayout.vue`
- `src/features/loans/LoanRejectForm.vue`
- `src/features/loans/LoanApproveForm.vue`
- `src/features/loans/LoanCollateralForm.vue`
- `src/features/auth/VerifyEmailPanel/VerifyEmailPanel.vue`
- `src/features/wallet/WalletOpenForm.vue`
- `src/features/loans/LoanDisburseForm.vue`
- `src/features/portal/MyShareholdingsPage.vue`
- `src/features/wallet/WalletAdjustForm.vue`
- `src/features/auth/VerifyEmailResend/VerifyEmailResend.vue`
- `src/features/auth/ResetPasswordForm/ResetPasswordForm.vue`
- `src/features/auth/ForgotPasswordForm/ForgotPasswordForm.vue`
- `src/features/shareholders/CertificateCancelDialog/CertificateCancelDialog.vue`
- `src/features/wallet/WalletPaymentForm.vue`
- `src/features/auth/RegisterForm/RegisterForm.vue`
- `src/features/loans/LoanCollateralReleaseForm.vue`
- `src/features/loans/LoanRestructureForm.vue`
- `src/features/loans/LoanApplicationForm.vue`
- `src/features/wallet/WalletObligationForm.vue`
- `src/features/wallet/WalletAllocationForm.vue`
- `src/features/governance/GovernanceBodyListView.vue`
- `src/components/patterns/ErrorShell/ErrorShell.vue`
- `src/components/primitives/ErrorSupportHint/ErrorSupportHint.vue`
- `src/components/composites/ConfirmDialog/ConfirmDialog.vue`
- `src/features/files/FilesListView/FilesListView.vue`
- `src/features/shareholders/ShareholderForm/ShareholderForm.vue`
- `src/layouts/AppShell/NotificationsBell/NotificationsBell.vue`
- `src/features/shareholders/ShareholderDetailView/ShareholderDetailView.vue`
- `src/features/shareholders/ShareClassListView/ShareClassListView.vue`
- `src/features/shareholders/HoldingEntryForm/HoldingEntryForm.vue`
- `src/features/shareholders/ShareholderListView/ShareholderListView.vue`
- `src/features/shareholders/CertificateIssueForm/CertificateIssueForm.vue`
- `tests/design-system-theme-contract.test.mjs`

### App search `mases.locale` — 2 distinct paths
- `src/stores/locale.ts`
- `docs/ARNA_BRAND_IDENTITY_CONTRACT_A3.md`

### App search `mases.tenant_id` — 2 distinct paths
- `src/stores/tenant.ts`
- `tests/brand-identity-contract.test.mjs`

### App search `mases.auth_token` — 2 distinct paths
- `src/core/bootstrap.ts`
- `tests/brand-identity-contract.test.mjs`

### App search `mases.auth.remembered_identifier` — 2 distinct paths
- `src/features/auth/LoginForm/LoginForm.vue`
- `tests/brand-identity-contract.test.mjs`

**Scope implication:** if the Owner's “no use of MASES” requirement includes all active product identifiers, the App's CSS custom properties and browser-storage fallback layer are part of the coordinated A3 scope. If the requirement is limited to the API Core PHP namespace/path, these App references are external compatibility dependencies to preserve and document, not silently rename.

## 10. Deployment and external-consumer boundary

- The API deployment checklist is `docs/operations/A5-ETHIOTELECOM-PLESK-DEPLOYMENT-CHECKLIST.md`. It records that Plesk File Manager access was unavailable at the time of that record and prior Plesk Git deployment timed out.
- No live production `.env`, published config copy, Composer vendor autoload cache, Laravel config/provider cache, PHP-FPM/OPcache state, or running queue worker state was accessible.
- No external PHP consumer of `maale-arna/mases-api` was found in the connected App repository or the connected-account namespace search. This does **not** prove absence in unconnected repositories, local clones, vendor deployments, third-party integrations or hosting environments.
- No database table/column rename is proposed. Database migration execution, seeding, production DB inspection and data changes were not performed.

## 11. A2 disposition and remaining closure gates

**Disposition: A2 PARTIAL / OPEN — connected-repository path and reference inventory recorded; full closure is not yet evidence-supported.**

Open items before A2 can be marked VERIFIED-CLOSED:
1. Run a deterministic whole-repository content scan at the exact frozen SHA (not just indexed code search) and classify every occurrence of old namespace, path, helper, config, environment, alias, cookie/session, seeder and test identifier. The connected code-search result for the broad namespace query is capped at 100, so it is not an exhaustive occurrence count.
2. Confirm whether the no-MASES requirement covers the App's active CSS token namespace and browser-storage compatibility fallbacks, or only the API Core PHP namespace/path.
3. Inventory the live Plesk `.env`, published configuration, deployment scripts and runtime caches/workers during an authorized read-only access window. Confirm whether `SANCTUM_TOKEN_PREFIX` or custom environment variables use old prefixes.
4. Confirm whether any unconnected repositories, local installations, vendor packages or external clients consume the old Composer package, PHP namespace, cookie/session key or API contracts.
5. Freeze A1 canonical target and migration policy, including treatment of historical/archival documents and any one-time compatibility migration.

**A3 NOT STARTED. A4 NOT RUN. A5 NOT AUTHORIZED.** No source or runtime changes were made.

## 12. Namespace search coverage by area

Additional repository-scoped namespace searches were run to partition the broad `MaaleArna MASES Core` query. Results below are distinct paths returned by each query; they overlap and must not be summed. A result count of 100 means the connector search cap was reached, not that the namespace occurs in exactly 100 files.

| Namespace area query | Distinct paths returned | Search cap reached? |
|---|---:|---|
| Auth | 100 | Yes |
| Authorization | 100 | Yes |
| Tenancy | 100 | Yes |
| Audit | 100 | Yes |
| Calendar | 86 | No |
| Localization | 55 | No |
| Providers | 22 | No |
| Policy | 100 | Yes |
| Exceptions | 67 | No |
| Security | 66 | No |
| Files | 67 | No |
| Domains / Finance | 100 | Yes |
| Domains / Governance | 100 | Yes |
| Domains / Loan | 100 | Yes |
| Domains / Shareholder | 100 | Yes |
| Domains / Wallet | 100 | Yes |
| Domains / Documents | 62 | No |
| Domains / People | 48 | No |
| Domains / Organization | 32 | No |
| Domains / FileManagement | 14 | No |

This confirms broad namespace fan-out across Core capabilities and domain layers. It does **not** provide a complete line-level inventory because several partitions reached the 100-result cap and code search is not a deterministic full-text scan. The exact 642-path Core source move-set is listed in §1.1; a full-content scan remains an A2 closure gate.

### Active localization contract confirmation

The API's `LocalizationService` defines and reads/writes both `mases_locale` session and cookie keys. `LocalizationMiddleware` injects that service and calls `getCurrentLocale()` / `setLocale()`; `bootstrap/app.php` registers that middleware globally. This is an active API runtime contract, not only a comment or dormant provider artifact. The separate `LocaleManager` / `mases_localization` cluster remains dormant because its provider is not registered.


## 13. Owner scope decision — 2026-11-27

The Owner confirmed that the no-MASES requirement applies across the integrated ARNA product, including the App's active CSS token namespace and browser-storage compatibility fallbacks. The Owner further directed that all applicable folder names, file names, namespaces, and necessary functions be renamed.

### Frozen naming and scope decision (A1)

- Core source root: `arna_core/src/`
- PHP namespace root: `MaaleArna\\Arna\\Core\\`
- Composer package: `maale-arna/arna-api`
- Composer PSR-4: `"MaaleArna\\\\Arna\\\\Core\\\\": "arna_core/src/"`
- Core config file/key: `arna_core/src/Config/arna.php` / `arna.*`
- All active Core provider class names and registrations use the canonical namespace.
- All active helper functions, global aliases, service-container aliases, publish tags, environment variables, seeder/command/factory names, test names/references, scripts and CI path references must be renamed to ARNA-prefixed or otherwise neutral names.
- All API/App browser storage keys, locale session/cookie keys and CSS custom properties using the MASES prefix must be renamed to ARNA equivalents; update readers, writers, migration fallbacks and contract tests so the final active product has no MASES compatibility fallback.
- Rename all tracked folder and file path components that carry the MASES name. Preserve neutral domain/file names that do not carry MASES; do not gratuitously rename unrelated business-domain names.
- Historical decision records, commit history and archival evidence are not rewritten or falsified. Active documentation and operational instructions must use ARNA naming; historical records may retain MASES only where required to accurately describe the past.
- Preserve database schema/table/column names unless separate evidence and explicit approval establish that a database identifier itself is a MASES-branded application identifier. No database migration or data rewrite is authorized by this naming decision.

**A1 naming/scope decision: OWNER-APPROVED / FROZEN.**

### Exact path-name inventory

A fresh recursive Git tree check at the frozen baselines returned `truncated=false`:

| Repository | Tracked files | Tracked file paths containing MASES | Directory paths containing MASES |
|---|---:|---:|---:|
| `akefadaka-git/arna-api` | 1,046 | 645 | 214 |
| `akefadaka-git/arna-app` | 493 | 0 | 0 |

The 645 API file paths comprise:
- 642 files below `mases_core/src/` (the complete Core source move-set).
- Three additional files outside that root: `app/Console/Commands/MasesRectorVerifyCommand.php`, `database/factories/MaaleArna/MASES/Core/Authorization/Models/PermissionFactory.php`, and `database/seeders/MasesCoreSeeder.php`.

The App has no tracked path component containing MASES at its recorded baseline; its remaining work is content-level identifiers, tokens, keys and documentation, not path renames.

### A2 and implementation boundary

This Owner decision freezes the intended target and broad rename scope. It does **not** mark A2 complete and does not authorize bypassing A2. A2 remains open until deterministic content-level reconciliation, runtime/deployment consumer assessment, and external consumer disposition are evidence-backed. A3 implementation has not started. No source changes, file moves, test edits, CI edits, deployment changes or database changes have been made by this decision.


## 14. A2 reconciliation decision and Owner deployment boundary — 2026-11-27

### Owner-provided operational decision

The Owner has explicitly stated that there is no useful deployed content to preserve and that the Owner will perform a fresh deployment using Plesk's native GitHub deployment feature. This is recorded as an Owner-provided decision, not as independently inspected production evidence.

Consequences for this A2:
- Live Plesk inspection, live `.env` review, PHP-FPM/cache/queue/OPcache inspection and deployment rehearsal are removed from the A2 closure critical path.
- The Owner owns the later fresh deployment. No deployment is authorized for this assessment.
- No production configuration, credentials, database contents or live runtime state were inspected or changed.
- This decision does not prove that no third-party clients or unconnected consumers exist.

### Repository consumer boundary

- **Known in-scope repository consumer:** `akefadaka-git/arna-app`, which integrates with `akefadaka-git/arna-api`.
- The App does not import the API's PHP namespace or Composer package. It does use API locale behavior and browser-storage keys, and has CSS token references that are part of the approved rename scope.
- **Unconnected external consumers:** UNKNOWN / NOT ESTABLISHED. Public repository search and connected-repository review cannot prove absence of private clients, local installations or integrations outside the connected repositories.
- Given the Owner's fresh-deployment decision, no live deployed legacy consumer is required to be migrated by this A2. The external-consumer uncertainty is bounded and recorded, not silently treated as zero.

### Deterministic content scan status

A full deterministic archive-based content scan of both frozen repositories was **NOT COMPLETED** in this assessment. The previously recorded recursive tree inventories are complete at the stated SHAs, but targeted GitHub code search is capped/selective and does not substitute for a full blob scan. Attempts to retrieve the exact GitHub ZIP archives through the available retrieval path were unsuccessful. Therefore:
- Exact path inventories and all previously recorded targeted findings remain valid as scoped evidence.
- Line-level exhaustive counts and complete active-vs-historical classification across every tracked text blob are **MISSING / NOT VERIFIED**.
- The existing findings identify substantial active references that A3 must cover, but cannot support a claim of zero unclassified active references.
- No source blobs were modified, and no tests/builds/workflows/migrations were run during A2.

### Formal A2 closure decision

**DECISION: A2 NOT CLOSED — PARTIAL / OPEN.**

Rationale: the Owner's fresh-deployment decision resolves the previously stated live-runtime inspection gate, and the known in-scope repository consumer is identified. However, the required deterministic full-content reconciliation was not completed. Closing A2 as VERIFIED-CLOSED would overstate the evidence.

| Gate | Decision |
|---|---|
| Frozen API/App baselines and recursive path trees | VERIFIED |
| Owner-approved canonical naming scope (A1) | VERIFIED / FROZEN |
| Known in-scope repository consumer (`arna-app`) | VERIFIED |
| Unconnected consumers | UNKNOWN; bounded and documented |
| Live Plesk/runtime reconciliation | NOT REQUIRED by Owner decision |
| Deterministic full-content scan of both repositories | NOT COMPLETED |
| A2 closure | **NOT CLOSED** |
| A3 implementation | **NOT AUTHORIZED TO START until A2 is closed** |
| A4 verification | NOT RUN |
| A5 deployment | Owner responsibility; not part of this authorization |

### Remaining A2 action

Complete the archive/blob-level deterministic scan at the exact frozen API and App SHAs, classify every occurrence by path and line as active code/config/test/CI, dormant code, documentation/history, generated/binary or other, and reconcile any previously unseen active references. Then issue a new evidence-backed A2 closure decision. Until then, preserve the A3 gate.


## 15. Further frozen-baseline content-search findings — 2026-11-27

A second targeted content-search pass was run against the exact frozen commit links. The GitHub code-search endpoint returned its 100-result maximum for each of these broad queries:

| Repository / query | Results returned | Interpretation |
|---|---:|---|
| arna-api / MASES | 100 | At least 100 matching result entries; endpoint cap prevents total count |
| arna-app / MASES | 100 | At least 100 matching result entries; endpoint cap prevents total count |
| arna-app / mase_app | 100 | At least 100 matching result entries; endpoint cap prevents total count |

These are result-entry counts, not unique occurrence or total line counts. They must not be added together because queries overlap.

### Newly surfaced App reference classes

The App search results reveal additional legacy content beyond the previously recorded storage keys and CSS variables:
- Many current component barrel files contain MASES Phase 2 UI headers and mase_app/... source-path comments.
- Feature and layout files, including authentication forms, shareholder feature components, AppShell components and launcher service, also contain mase_app references.
- .env.workspace and .env.admin contain legacy path/identity comments or values that require line-level review.
- A user-visible title string in src/router/pages/VerifyEmailRoute.vue still names MASES.
- A feature component src/features/loans/LoanRejectForm.vue contains a MASES product header.

These results are not automatically classified as historical/archival. Each must be reviewed in context during the complete scan and either updated as active product identity or explicitly classified as historical documentation/commentary where justified.

### API confirmation

The API broad search independently returned 100 entries, including direct active references such as:
- mases_core/src/Config/mases.php and the Core config namespace.
- database/seeders/MasesCoreSeeder.php.
- app/Console/Commands/MasesRectorVerifyCommand.php.
- mases_core/src/Providers/MasesCoreServiceProvider.php.
- mases_core/src/Support/Helpers.php.
- Namespace declarations/imports throughout Core source.
- Active locale/session identifiers and environment variables.
- Workflow and static-analysis path references.

A small direct blob sample was also fetched from the API tree. The first 12 checked blobs contained multiple workflow path filters and PHP lint commands referencing mases_core/; the next 8 checked blobs included further workflow hits. This is a sample only, not a full-repository scan.

### Scan method limitation and A2 disposition

Both frozen recursive trees are complete and non-truncated (API: 1,046 blobs; App: 493 blobs). The GitHub code-search results are capped at 100; the available blob-fetch connector requires one request per blob and its per-execution call ceiling prevents fetching all 1,539 blobs in one scan operation. A complete blob-by-blob line inventory has therefore NOT BEEN COMPLETED.

**A2 remains PARTIAL / OPEN.** No deterministic full-content scan or exhaustive line-level classification is claimed. The additional App results expand the reconciliation scope and must be included in A3 planning only after A2 is closed. No A3 work is authorized by this addendum.


## 16. Supplemental exact-frozen-SHA blob inspection — 2026-11-27

A further controlled retrieval pass fetched and inspected **39 individual Git blobs** by blob SHA from the two frozen recursive trees: **26 API blobs** at `9c0ce83afcc084101fb14e6e55d54e1d953c2ffe` and **13 App blobs** at `71bed2158894e3ce9cf507e8bf70492ac1f9dfd4`. The repository tree counts remain API 1,046 blobs and App 493 blobs, both with `truncated=false`.

### API findings confirmed directly at the frozen SHA

| File | Directly observed references | Reconciliation classification |
|---|---|---|
| `composer.json` | Package `maale-arna/mases-api`; MASES product description; Composer helper autoload path under `mases_core/src`; PSR-4 namespace `MaaleArna\\MASES\\Core\\` | Active package/autoload identifiers; rename required |
| `bootstrap/app.php` | Legacy Core imports, MASES bootstrap comments, and global `mases_*` helper declarations | Active bootstrap symbols plus comments; rename active symbols and current-product comments |
| `bootstrap/providers.php` | Legacy provider namespace/path/config/publish-tag documentation; comments identify LocalizationServiceProvider and MasesCoreServiceProvider as not registered | Registry comments require reconciliation; do not activate dormant providers |
| `mases_core/src/Providers/CoreServiceProvider.php` | Legacy namespace/imports and Core provider integration | Active provider and integration path; rename required |
| `mases_core/src/Config/mases.php` | `MASES_APP_NAME`, `MASES_RATE_LIMIT`, `MASES_DEFAULT_LOCALE`, other MASES environment names; `mases.*` configuration model; MASES application labels | Active config/environment identifiers; rename required |
| `mases_core/src/Support/Helpers.php` | Global `mases_*` helpers, old namespace imports, `config('mases.*')` references | Active helper/config contract; rename required |
| `database/seeders/MasesCoreSeeder.php` | Class name, old namespace imports, MASES messages | Active seeder identity; rename required with call sites |
| `config/auth.php` | Old Core User model namespace | Active model binding; rename required |
| `phpunit.xml` | Old Core source/test/coverage/helper paths, old AUTH_MODEL, MASES environment keys | Active test-harness configuration; rename required |
| `rector.php`, `app/Console/Commands/MasesRectorVerifyCommand.php`, `scripts/phase0-authorization-audit.py` | Old Core paths, namespace, command identity and audit-tool references | Active developer/verification tooling; rename required |
| `mases_core/src/Localization/Services/LocalizationService.php` | `mases_locale` session and cookie names; `mases.localization.timezone`; old namespace | Active runtime localization contract; rename required |
| `mases_core/src/Providers/LocalizationServiceProvider.php`, `mases_core/src/Localization/LocaleManager.php` | Old namespace, `mases.localization`, `mases_locale`, and separate `mases_localization` config | Legacy/dormant cluster; retain distinction from the active LocalizationService runtime and verify all references before retirement |
| `app/Http/Controllers/LocalePreferenceController.php` | Old Core User model import | Active controller dependency; rename required |
| `config/app.php` | `MASES_ENABLE_DEMO_SEEDING` | Active environment key; rename required |

### App findings confirmed directly at the frozen SHA

| File | Directly observed references | Reconciliation classification |
|---|---|---|
| `.env.admin`, `.env.workspace` | `mase_app/...` file-path comments | Non-runtime comments; update current repository/path identity where these are current operational templates |
| `src/styles/tokens.css` | MASES Phase 2 identity and `mase_app/...` header comments; legacy `--mases-*` token definitions and ARNA aliases | Active CSS token substrate plus stale provenance comments; remove old token definitions/aliases and update active consumers per frozen A1 |
| `src/router/pages/VerifyEmailRoute.vue` | User-visible document title includes MASES; MASES app-name/title comments; `--mases-*` token references | Active product title and styling references; rename required |
| `src/features/loans/LoanRejectForm.vue` | MASES component header/provenance and active `--mases-*` CSS variable references | Active component styling; update tokens and current-product metadata |
| `tests/brand-identity-contract.test.mjs` | Explicit expectations for legacy `mases.locale`, `mases.tenant_id`, `mases.auth_token`, remembered-identifier fallbacks and ARNA-to-MASES CSS aliases | Tests currently preserve behavior contrary to frozen A1; rewrite assertions for no active compatibility fallback |
| `tests/design-system-theme-contract.test.mjs` | Legacy `--mases-*` token inventory | Test contract must be migrated to canonical ARNA tokens |
| `src/core/api/client.ts` | MASES-era deployment/protocol commentary and `mases_locale` references in explanatory text | Documentation/commentary within source; verify against actual runtime behavior before classifying as an active client cookie dependency |
| `src/stores/locale.ts` | `LEGACY_STORAGE_KEY = 'mases.locale'`; explanatory migration-fallback comments | Active legacy browser-storage fallback; remove under frozen A1 |
| `src/stores/tenant.ts` | `LEGACY_KEY = 'mases.tenant_id'`; explanatory migration-fallback comments | Active legacy browser-storage fallback; remove under frozen A1 |
| `src/stores/auth.ts`, `src/features/auth/api.ts`, `src/features/auth/service.ts` | MASES-era component/path/provenance comments; no active MASES storage key was found in the inspected matching lines of these files | Update current-product/path comments; do not infer active behavior from comments alone |
| `src/features/locale/api.ts` | No MASES/mase_app match in the fetched blob | No matching occurrence in this file |

### Retrieval method limitation and closure decision

The 39 blob inspections above are direct evidence from exact frozen commit blob SHAs, but they are a targeted sample selected from high-risk files—not a scan of all 1,539 blobs. The recursive tree endpoint supplies paths and blob SHAs, while the connected GitHub blob action retrieves one blob per call. The available connector does not expose a bulk repository archive download into the analysis runtime. GitHub code search is not an acceptable substitute for the frozen-SHA scan: its search endpoint is not commit-pinned by the attempted `ref:<SHA>` qualifier, and its indexed result counts changed during pagination. Those moving search results are excluded from frozen-baseline occurrence counts.

Accordingly:
- **Direct blob coverage:** 39 / 1,539 blobs (2.53%) — targeted, not random or exhaustive.
- **API:** 26 / 1,046 blobs inspected.
- **App:** 13 / 493 blobs inspected.
- **Full deterministic content scan:** NOT COMPLETED.
- **Line-level classification of all matching and non-matching text blobs:** NOT COMPLETED.
- **A2:** remains **PARTIAL / OPEN — NOT VERIFIED-CLOSED**.
- **A3:** NOT STARTED. The A1 gate still prohibits implementation until the full scan is completed, reconciled and A2 is formally closed.

No source, test, CI, migration, database, deployment or production changes were made by this supplemental inspection.


## 17. Complete deterministic content scan and reconciliation — 2026-11-27

The complete scan is recorded in [ARNA-CORE-NSM-001-A2-DETERMINISTIC-SCAN-RECONCILIATION-2026-11-27.md](ARNA-CORE-NSM-001-A2-DETERMINISTIC-SCAN-RECONCILIATION-2026-11-27.md).

### 17.1 Frozen-SHA integrity

| Repository | Frozen SHA | Expected blobs | Scanned blobs | Text | Binary | Content matches | Path-hit files |
|---|---|---:|---:|---:|---:|---:|---:|
| arna-api | `9c0ce83afcc084101fb14e6e55d54e1d953c2ffe` | 1,046 | 1,046 | 1,045 | 1 | 4,842 | 645 |
| arna-app | `71bed2158894e3ce9cf507e8bf70492ac1f9dfd4` | 493 | 493 | 490 | 3 | 4,407 | 0 |
| **Total** | — | **1,539** | **1,539** | **1,535** | **4** | **9,249** | **645** |

Both checked-out HEAD values exactly matched the frozen SHAs. All 1,539 tracked blobs were read with `git cat-file --batch`; the recursive tree counts matched exactly. Four binary files were inventoried and none contained a matching token. The scan emitted **9,896 occurrence rows**: 9,249 content matches and 647 path-name matches. There are 645 unique API file paths with identity matches and 214 unique API directory paths containing the identity. The App has no tracked path-name matches.

The API path move-set is 642 files under `mases_core/src/`; the three additional paths are:
- `app/Console/Commands/MasesRectorVerifyCommand.php`
- `database/factories/MaaleArna/MASES/Core/Authorization/Models/PermissionFactory.php`
- `database/seeders/MasesCoreSeeder.php`

### 17.2 Reconciliation

All 9,896 occurrence rows have been assigned one primary reconciliation class in the derived line-level file `reconciled-occurrences.csv`. The full tracked-file inventory, occurrence inventory, file-level rollup and scan summary are available in the GitHub Actions artifact identified below.

API findings are reconciled into Core tree/namespace migration, helpers and environment identifiers, configuration/container aliases, localization session/cookie keys, provider/seeder/command identities, 27 CI workflows, 153 test/test-harness files, developer tooling, current documentation, historical documentation and active comments/user-facing text.

App findings are reconciled into CSS token definitions/consumers, four browser-storage fallback keys, stale `mase_app/` path/provenance comments, component/brand comments, user-visible titles, three test contracts, current documentation and historical/superseded documentation.

Historical reports and dated audit evidence are retained. Current technical/operational documentation and current product identity are to be updated. The separate unregistered provider/localization cluster remains dormant; it must not be activated by this rename.

### 17.3 Database and external-consumer boundary

No MASES-prefixed table or column identifier was observed in the scanned migration content. Matching references in database migration files are comments, PHP namespace references or Core source paths. No schema/data change or migration execution is authorized.

Supplemental public-owner code searches for the legacy PHP namespace, `mases_core`, `mases_locale` and `mase_app` returned only the two in-scope repositories. This is corroborative indexed search, not proof that private/local/unconnected consumers do not exist. Those consumers remain UNKNOWN / NOT ESTABLISHED. The Owner's fresh-deployment decision bounds the operational exposure and remains the deployment responsibility.

### 17.4 Formal A2 closure

**A2: VERIFIED-CLOSED for the defined frozen API/App repository scope.** Closure is supported by exact-SHA verification, full blob coverage, full path/content inventory, 9,896 classified occurrence rows, explicit active-vs-historical reconciliation and recorded residual external-consumer uncertainty.

**A3 is eligible to begin on isolated API and App branches from the frozen SHAs.** No merge, main modification, database migration/schema/data change, production change or deployment is authorized by A2 closure. A4 and A5 remain separate gates.

### 17.5 Reproducible evidence

- Workflow run: https://github.com/akefadaka-git/arna-api/actions/runs/45975141734
- Scan-only branch: `audit/arna-a2-frozen-content-scan-20261127`
- Workflow commit: `d0e8eccd944988a596b065d3f4f175ad14ce60dc`
- Artifact ID: `15187345405`, name `arna-a2-frozen-content-scan-20261127`, 314,364 bytes
- Artifact SHA-256: `46c7d28c52d9fec755ddb178f8a580e75af14379e6b3cf3d077574f785a693c4`
- Artifact expiry: 2026-12-04 09:36:48 UTC

Section 16's partial-scan disposition is retained as the historically accurate state before this complete run; this section supersedes that disposition.
