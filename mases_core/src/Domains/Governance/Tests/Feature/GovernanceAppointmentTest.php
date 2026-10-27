<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 13 of 31  (REDELIVERY 2)
 * =============================================================================
 *
 * File:            Tests/Feature/GovernanceAppointmentTest.php
 * Delivery type:   Full-file replacement (second patch redelivery)
 *
 * REDELIVERY 2 NOTE
 * -----------------------------------------------------------------------------
 * The first redelivery renamed three private helpers to fix a
 * visibility collision with the platform's base `Tests\TestCase`:
 *
 *     createTenant       →  makeTenant
 *     createUser         →  makeUser
 *     bindTenantContext  →  bindCurrentTenant
 *
 * That fix was correct and is preserved. The class now loads.
 *
 * A SECOND defect surfaced when the class ran: every test failed
 * with `TenantException: Tenant context is required for this
 * request.` from `TenantMembershipMiddleware` at line 158.
 *
 * Root cause: `bindCurrentTenant()` was binding a container key
 * (`mases.current_tenant_id`) that the platform's tenancy
 * middleware chain does not read. `TenantContextMiddleware` —
 * which runs earlier in the pipeline — resolves the tenant from
 * the REQUEST, via the `X-Tenant-ID` header consumed by the
 * platform's `RequestTenantResolver`. No header was being sent,
 * so the middleware chain never established a tenant context, and
 * `TenantMembershipMiddleware` (running after `auth:sanctum`)
 * correctly refused the request.
 *
 * The fix is a one-method change:
 *
 *     private function bindCurrentTenant(Tenant $tenant): void
 *     {
 *         $this->withHeader('X-Tenant-ID', (string) $tenant->id);
 *     }
 *
 * `withHeader()` is Laravel's test-client header setter; it
 * persists on every subsequent request in the same test method
 * (`getJson`, `postJson`, `patchJson`, …). Setting it in `setUp()`
 * means every test inherits the header automatically.
 *
 * The `app()->instance(...)` binding is removed. It had no effect
 * on the request pipeline and, if left in place, would have
 * created a false impression that the test was exercising the
 * platform's real tenant resolver.
 *
 * No other change is made. The 19 test methods, the fixtures, the
 * routes referenced, and the deployment-order dependencies on the
 * routes amendment (G2c file 31 of 31) and the audit listener
 * amendment (G2c file 29 of 31) are unchanged.
 *
 * -----------------------------------------------------------------------------
 * Everything else is byte-for-byte identical to the first
 * redelivery of this file. See that block for the full
 * change-verification content of the original delivery.
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Governance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceAppointment;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceTerm;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceAppointmentService;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * GovernanceAppointmentTest
 *
 * Feature tests for the GovernanceAppointment vertical slice.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Tests\Feature
 * @version 1.0.2
 */
final class GovernanceAppointmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        gc_collect_cycles();

        $this->tenant = $this->makeTenant();
        $this->bindCurrentTenant($this->tenant);

        $this->user = $this->makeUser();

        $this->createGovernancePermission('governance.body.view', 'View governance bodies');
        $this->createGovernancePermission('governance.body.manage', 'Manage governance bodies');

        Sanctum::actingAs($this->user, ['*']);
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();

        parent::tearDown();
    }

    // =========================================================================
    // index
    // =========================================================================

    public function test_index_returns_paginated_list_of_appointments(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();

        $this->createAppointment($body, ['title_en' => 'First']);
        $this->createAppointment($body, ['title_en' => 'Second']);
        $this->createAppointment($body, ['title_en' => 'Third']);

        $response = $this->getJson(
            route('api.governance.appointments.index', [
                'governanceBody' => $body->id,
            ]),
        );

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(3, 'data');
        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('meta.per_page', 25);

        gc_collect_cycles();
    }

    public function test_index_requires_view_permission(): void
    {
        gc_collect_cycles();

        $this->user->permissions()->detach();

        $body = $this->createGovernanceBody();

        $response = $this->getJson(
            route('api.governance.appointments.index', [
                'governanceBody' => $body->id,
            ]),
        );

        $response->assertStatus(403);

        gc_collect_cycles();
    }

    public function test_index_returns_empty_list_when_body_has_no_appointments(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();

        $response = $this->getJson(
            route('api.governance.appointments.index', [
                'governanceBody' => $body->id,
            ]),
        );

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
        $response->assertJsonPath('meta.total', 0);

        gc_collect_cycles();
    }

    public function test_index_404s_for_cross_tenant_body(): void
    {
        gc_collect_cycles();

        $otherTenant = $this->makeTenant('other', 'other.example.test');
        $foreignBody = GovernanceBody::create([
            'tenant_id'   => $otherTenant->id,
            'code'        => 'FOREIGN',
            'body_type'   => 'board',
            'name_en'     => 'Foreign Body',
            'is_active'   => true,
        ]);

        $response = $this->getJson(
            route('api.governance.appointments.index', [
                'governanceBody' => $foreignBody->id,
            ]),
        );

        $response->assertStatus(404);

        gc_collect_cycles();
    }

    public function test_second_active_primary_appointment_for_position_is_rejected(): void
    {
        $body = $this->createGovernanceBody();
        $position = $this->createGovernancePosition($body);
        $first = $this->createAppointment($body, ['governance_position_id' => $position->id]);
        $second = $this->createAppointment($body, [
            'governance_position_id' => $position->id,
            'instrument_reference' => 'RES-2026-001',
        ]);

        $service = app(GovernanceAppointmentService::class);
        $service->activate($first);

        try {
            $service->activate($second);
            $this->fail('A second active primary appointment for one position must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('governance_position_id', $exception->errors());
        }

        $this->assertSame('active', $first->fresh()->status);
        $this->assertSame('draft', $second->fresh()->status);
    }

    public function test_future_primary_successor_is_allowed_after_incumbent_end_date(): void
    {
        $body = $this->createGovernanceBody();
        $position = $this->createGovernancePosition($body);
        $first = $this->createAppointment($body, [
            'governance_position_id' => $position->id,
            'effective_date' => '2026-09-18',
        ]);
        $second = $this->createAppointment($body, [
            'governance_position_id' => $position->id,
            'effective_date' => '2027-01-01',
            'instrument_reference' => 'RES-2027-001',
        ]);

        $service = app(GovernanceAppointmentService::class);
        $service->activate($first);
        $service->update($first, ['effective_until' => '2026-12-31']);

        $service->activate($second);

        $this->assertSame('active', $first->fresh()->status);
        $this->assertSame('2026-12-31', $first->fresh()->effective_until->toDateString());
        $this->assertSame('active', $second->fresh()->status);
    }

    public function test_acting_appointment_is_bounded_by_active_primary_and_does_not_conflict_as_primary(): void
    {
        $body = $this->createGovernanceBody();
        $position = $this->createGovernancePosition($body);
        $primary = $this->createAppointment($body, [
            'governance_position_id' => $position->id,
            'effective_date' => '2026-09-18',
            'effective_until' => '2027-12-31',
            'appointment_role' => 'primary',
        ]);
        $acting = $this->createAppointment($body, [
            'governance_position_id' => $position->id,
            'effective_date' => '2026-10-01',
            'effective_until' => '2026-10-31',
            'appointment_role' => 'acting',
            'acting_for_appointment_id' => $primary->id,
            'instrument_reference' => 'RES-ACTING-001',
        ]);

        $service = app(GovernanceAppointmentService::class);
        $service->activate($primary);
        $service->activate($acting);

        $this->assertSame('active', $primary->fresh()->status);
        $this->assertSame('active', $acting->fresh()->status);
        $this->assertSame('acting', $acting->fresh()->appointment_role);
        $this->assertSame($primary->id, $acting->fresh()->acting_for_appointment_id);
    }

    public function test_acting_appointment_cannot_outlast_its_substantive_primary(): void
    {
        $body = $this->createGovernanceBody();
        $position = $this->createGovernancePosition($body);
        $primary = $this->createAppointment($body, [
            'governance_position_id' => $position->id,
            'effective_date' => '2026-09-18',
            'effective_until' => '2026-10-15',
            'appointment_role' => 'primary',
        ]);
        $acting = $this->createAppointment($body, [
            'governance_position_id' => $position->id,
            'effective_date' => '2026-10-01',
            'effective_until' => '2026-10-16',
            'appointment_role' => 'acting',
            'acting_for_appointment_id' => $primary->id,
        ]);

        $service = app(GovernanceAppointmentService::class);
        $service->activate($primary);

        try {
            $service->activate($acting);
            $this->fail('An acting assignment cannot outlast its substantive primary appointment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('effective_until', $exception->errors());
        }

        $this->assertSame('draft', $acting->fresh()->status);
    }

    public function test_primary_appointment_rejects_end_date_before_start_date(): void
    {
        $body = $this->createGovernanceBody();
        $position = $this->createGovernancePosition($body);
        $appointment = $this->createAppointment($body, [
            'governance_position_id' => $position->id,
            'effective_date' => '2026-10-10',
            'effective_until' => '2026-10-09',
        ]);

        $this->expectException(ValidationException::class);
        app(GovernanceAppointmentService::class)->activate($appointment);
    }

    // =========================================================================
    // store
    // =========================================================================

    public function test_store_creates_appointment_with_valid_payload(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();

        $payload = [
            'instrument_type'      => 'resolution',
            'instrument_reference' => 'RES-2026-042',
            'instrument_date'      => '2026-09-10',
            'effective_date'       => '2026-09-18',
            'appointment_mode'     => 'elected',
            'title_am'             => 'የቦርድ ሹመት',
            'title_en'             => 'Board Appointment',
        ];

        $response = $this->postJson(
            route('api.governance.appointments.store', [
                'governanceBody' => $body->id,
            ]),
            $payload,
        );

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.instrument_type', 'resolution');
        $response->assertJsonPath('data.appointment_mode', 'elected');
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.effective_date', '2026-09-18');

        $this->assertDatabaseHas('governance_appointments', [
            'tenant_id'          => $this->tenant->id,
            'governance_body_id' => $body->id,
            'instrument_type'    => 'resolution',
            'appointment_mode'   => 'elected',
            'status'             => 'draft',
        ]);

        gc_collect_cycles();
    }

    public function test_store_forces_status_to_draft(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();

        $response = $this->postJson(
            route('api.governance.appointments.store', [
                'governanceBody' => $body->id,
            ]),
            [
                'instrument_type'  => 'by_law',
                'effective_date'   => '2026-09-18',
                'appointment_mode' => 'ex_officio',
                'status'           => 'active',
            ],
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('governance_appointments', [
            'governance_body_id' => $body->id,
            'status'             => 'draft',
        ]);

        gc_collect_cycles();
    }

    public function test_store_merges_body_id_from_url(): void
    {
        gc_collect_cycles();

        $bodyA = $this->createGovernanceBody(['code' => 'A']);
        $bodyB = $this->createGovernanceBody(['code' => 'B']);

        $response = $this->postJson(
            route('api.governance.appointments.store', [
                'governanceBody' => $bodyA->id,
            ]),
            [
                'governance_body_id' => $bodyB->id,
                'instrument_type'    => 'resolution',
                'effective_date'     => '2026-09-18',
                'appointment_mode'   => 'elected',
            ],
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.governance_body_id', $bodyA->id);

        $this->assertDatabaseHas('governance_appointments', [
            'governance_body_id' => $bodyA->id,
        ]);
        $this->assertDatabaseMissing('governance_appointments', [
            'governance_body_id' => $bodyB->id,
        ]);

        gc_collect_cycles();
    }

    public function test_store_rejects_invalid_instrument_type(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();

        $response = $this->postJson(
            route('api.governance.appointments.store', [
                'governanceBody' => $body->id,
            ]),
            [
                'instrument_type'  => 'not_a_real_type',
                'effective_date'   => '2026-09-18',
                'appointment_mode' => 'elected',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['instrument_type']);

        gc_collect_cycles();
    }

    public function test_store_rejects_invalid_appointment_mode(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();

        $response = $this->postJson(
            route('api.governance.appointments.store', [
                'governanceBody' => $body->id,
            ]),
            [
                'instrument_type'  => 'resolution',
                'effective_date'   => '2026-09-18',
                'appointment_mode' => 'by_popular_acclaim',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['appointment_mode']);

        gc_collect_cycles();
    }

    public function test_store_rejects_effective_date_before_instrument_date(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();

        $response = $this->postJson(
            route('api.governance.appointments.store', [
                'governanceBody' => $body->id,
            ]),
            [
                'instrument_type'  => 'resolution',
                'instrument_date'  => '2026-09-18',
                'effective_date'   => '2026-09-01',
                'appointment_mode' => 'elected',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['effective_date']);

        gc_collect_cycles();
    }

    public function test_store_rejects_position_from_different_body(): void
    {
        gc_collect_cycles();

        $bodyA = $this->createGovernanceBody(['code' => 'A']);
        $bodyB = $this->createGovernanceBody(['code' => 'B']);
        $positionOnB = $this->createGovernancePosition($bodyB);

        $response = $this->postJson(
            route('api.governance.appointments.store', [
                'governanceBody' => $bodyA->id,
            ]),
            [
                'governance_position_id' => $positionOnB->id,
                'instrument_type'        => 'resolution',
                'effective_date'         => '2026-09-18',
                'appointment_mode'       => 'elected',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['governance_position_id']);

        gc_collect_cycles();
    }

    public function test_store_rejects_term_from_different_body(): void
    {
        gc_collect_cycles();

        $bodyA = $this->createGovernanceBody(['code' => 'A']);
        $bodyB = $this->createGovernanceBody(['code' => 'B']);
        $termOnB = $this->createGovernanceTerm($bodyB);

        $response = $this->postJson(
            route('api.governance.appointments.store', [
                'governanceBody' => $bodyA->id,
            ]),
            [
                'governance_term_id' => $termOnB->id,
                'instrument_type'    => 'resolution',
                'effective_date'     => '2026-09-18',
                'appointment_mode'   => 'elected',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['governance_term_id']);

        gc_collect_cycles();
    }

    // =========================================================================
    // show
    // =========================================================================

    public function test_show_returns_single_appointment_with_relations(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();
        $position = $this->createGovernancePosition($body);
        $term = $this->createGovernanceTerm($body);

        $appointment = $this->createAppointment($body, [
            'governance_position_id' => $position->id,
            'governance_term_id'     => $term->id,
            'title_en'               => 'Show Test',
        ]);

        $response = $this->getJson(
            route('api.governance.appointments.show', [
                'governanceAppointment' => $appointment->id,
            ]),
        );

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $appointment->id);
        $response->assertJsonPath('data.title_en', 'Show Test');
        $response->assertJsonPath('data.body.id', $body->id);
        $response->assertJsonPath('data.position.id', $position->id);
        $response->assertJsonPath('data.term.id', $term->id);

        gc_collect_cycles();
    }

    public function test_show_404s_for_cross_tenant_appointment(): void
    {
        gc_collect_cycles();

        $otherTenant = $this->makeTenant('other', 'other.example.test');
        $foreignBody = GovernanceBody::create([
            'tenant_id' => $otherTenant->id,
            'code'      => 'FOREIGN',
            'body_type' => 'board',
            'name_en'   => 'Foreign Body',
            'is_active' => true,
        ]);
        $foreignAppointment = GovernanceAppointment::create([
            'tenant_id'          => $otherTenant->id,
            'governance_body_id' => $foreignBody->id,
            'instrument_type'    => 'resolution',
            'effective_date'     => '2026-09-18',
            'appointment_mode'   => 'elected',
            'status'             => 'draft',
        ]);

        $response = $this->getJson(
            route('api.governance.appointments.show', [
                'governanceAppointment' => $foreignAppointment->id,
            ]),
        );

        $response->assertStatus(404);

        gc_collect_cycles();
    }

    // =========================================================================
    // update
    // =========================================================================

    public function test_update_modifies_mutable_fields(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();
        $appointment = $this->createAppointment($body, [
            'title_en' => 'Original Title',
            'notes_en' => 'Original Notes',
        ]);

        $response = $this->patchJson(
            route('api.governance.appointments.update', [
                'governanceAppointment' => $appointment->id,
            ]),
            [
                'title_en' => 'Updated Title',
                'notes_en' => 'Updated Notes',
            ],
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.title_en', 'Updated Title');
        $response->assertJsonPath('data.notes_en', 'Updated Notes');

        $this->assertDatabaseHas('governance_appointments', [
            'id'       => $appointment->id,
            'title_en' => 'Updated Title',
            'notes_en' => 'Updated Notes',
        ]);

        gc_collect_cycles();
    }

    public function test_update_rejects_body_id_change(): void
    {
        gc_collect_cycles();

        $bodyA = $this->createGovernanceBody(['code' => 'A']);
        $bodyB = $this->createGovernanceBody(['code' => 'B']);

        $appointment = $this->createAppointment($bodyA);

        $response = $this->patchJson(
            route('api.governance.appointments.update', [
                'governanceAppointment' => $appointment->id,
            ]),
            [
                'governance_body_id' => $bodyB->id,
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['governance_body_id']);

        $this->assertDatabaseHas('governance_appointments', [
            'id'                 => $appointment->id,
            'governance_body_id' => $bodyA->id,
        ]);

        gc_collect_cycles();
    }

    public function test_update_rejects_status_change(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();
        $appointment = $this->createAppointment($body, ['status' => 'draft']);

        $response = $this->patchJson(
            route('api.governance.appointments.update', [
                'governanceAppointment' => $appointment->id,
            ]),
            [
                'status' => 'active',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('governance_appointments', [
            'id'     => $appointment->id,
            'status' => 'draft',
        ]);

        gc_collect_cycles();
    }

    // =========================================================================
    // RP-02 â€” cross-tenant update coverage  (RP-02-A3-COVERAGE-ADDED)
    // =========================================================================
    //
    // These three tests assert the RP-02 security property for the
    // appointment update endpoint: a cross-tenant id is rejected by
    // the FormRequest's authorize() with 404 RESOURCE_NOT_FOUND, the
    // withValidator() cross-field check never runs for a cross-tenant
    // id, and the externally observable envelope is indistinguishable
    // from the nonexistent-id case.

    public function test_update_404s_for_cross_tenant_appointment(): void
    {
        gc_collect_cycles();

        $otherTenant = $this->makeTenant('other', 'other.example.test');
        $foreignBody = GovernanceBody::create([
            'tenant_id' => $otherTenant->id,
            'code'      => 'FOREIGN',
            'body_type' => 'board',
            'name_en'   => 'Foreign Body',
            'is_active' => true,
        ]);
        $foreignAppointment = GovernanceAppointment::create([
            'tenant_id'          => $otherTenant->id,
            'governance_body_id' => $foreignBody->id,
            'instrument_type'    => 'resolution',
            'effective_date'     => '2026-09-18',
            'appointment_mode'   => 'elected',
            'status'             => 'draft',
        ]);

        $response = $this->patchJson(
            route('api.governance.appointments.update', [
                'governanceAppointment' => $foreignAppointment->id,
            ]),
            ['title_en' => 'Attempted Cross-Tenant Update'],
        );

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');

        gc_collect_cycles();
    }

    public function test_update_cross_tenant_with_cross_field_violation_still_404s(): void
    {
        gc_collect_cycles();

        $otherTenant = $this->makeTenant('other', 'other.example.test');
        $foreignBody = GovernanceBody::create([
            'tenant_id' => $otherTenant->id,
            'code'      => 'FOREIGN',
            'body_type' => 'board',
            'name_en'   => 'Foreign Body',
            'is_active' => true,
        ]);
        $foreignAppointment = GovernanceAppointment::create([
            'tenant_id'          => $otherTenant->id,
            'governance_body_id' => $foreignBody->id,
            'instrument_type'    => 'resolution',
            'effective_date'     => '2026-09-18',
            'appointment_mode'   => 'elected',
            'status'             => 'draft',
        ]);

        // If authorize() did not run first, the payload below would be
        // merged with the foreign row's state (effective_date
        // 2026-09-18) and the withValidator() cross-field check
        // (effective_date >= instrument_date) would fire, producing a
        // 422 whose validation errors disclose the cross-tenant row's
        // date columns. RP-02 requires authorize() to throw before
        // withValidator() runs.
        $response = $this->patchJson(
            route('api.governance.appointments.update', [
                'governanceAppointment' => $foreignAppointment->id,
            ]),
            [
                'instrument_date' => '2027-01-01',
                'title_en'        => 'Attempted Cross-Tenant Update',
            ],
        );

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');

        gc_collect_cycles();
    }

    public function test_update_cross_tenant_and_nonexistent_produce_equivalent_envelopes(): void
    {
        gc_collect_cycles();

        $otherTenant = $this->makeTenant('other', 'other.example.test');
        $foreignBody = GovernanceBody::create([
            'tenant_id' => $otherTenant->id,
            'code'      => 'FOREIGN',
            'body_type' => 'board',
            'name_en'   => 'Foreign Body',
            'is_active' => true,
        ]);
        $foreignAppointment = GovernanceAppointment::create([
            'tenant_id'          => $otherTenant->id,
            'governance_body_id' => $foreignBody->id,
            'instrument_type'    => 'resolution',
            'effective_date'     => '2026-09-18',
            'appointment_mode'   => 'elected',
            'status'             => 'draft',
        ]);

        $payload = ['title_en' => 'Attempted Update'];

        $crossTenantResponse = $this->patchJson(
            route('api.governance.appointments.update', [
                'governanceAppointment' => $foreignAppointment->id,
            ]),
            $payload,
        );

        $nonexistentResponse = $this->patchJson(
            route('api.governance.appointments.update', [
                'governanceAppointment' => 999999,
            ]),
            $payload,
        );

        $crossTenantResponse->assertStatus(404);
        $nonexistentResponse->assertStatus(404);

        // Strip only fields that are nondeterministic by design
        // (request_id and timestamp). Every other field, at every
        // depth, must be identical for the two cases. If this test
        // fails, the renderer may be disclosing case-specific context
        // (e.g. the model id) â€” a finding to surface, not a test bug.
        $strip = function (array $payload) use (&$strip): array {
            foreach ($payload as $key => $value) {
                if ($key === 'request_id' || $key === 'timestamp') {
                    unset($payload[$key]);
                    continue;
                }
                if (is_array($value)) {
                    $payload[$key] = $strip($value);
                }
            }
            return $payload;
        };

        $this->assertSame(
            $strip($crossTenantResponse->json()),
            $strip($nonexistentResponse->json()),
        );

        gc_collect_cycles();
    }
    // =========================================================================
    // RP-01 — audit before/after value assertion  (RP-01-A3-COVERAGE-ADDED)
    // =========================================================================
    //
    // Asserts that the audit row for this update records the actual
    // pre-update and post-update title_en values, and that they differ.
    // The pre-existing audit test only asserts that a row exists.

    public function test_update_records_distinct_old_and_new_title_in_audit(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();
        $appointment = $this->createAppointment($body, [
            'title_en' => 'Original Title',
        ]);

        DB::table('audit_logs')->truncate();

        $this->patchJson(
            route('api.governance.appointments.update', [
                'governanceAppointment' => $appointment->id,
            ]),
            ['title_en' => 'Updated Title'],
        )->assertStatus(200);

        $row = DB::table('audit_logs')
            ->where('action',        'governance.appointment.updated')
            ->where('resource_type', 'GovernanceAppointment')
            ->where('resource_id',   (string) $appointment->id)
            ->where('tenant_id',     $this->tenant->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull(
            $row,
            'No audit row matched {action=governance.appointment.updated, resource_type=GovernanceAppointment, resource_id=' . $appointment->id . ', tenant_id=' . $this->tenant->id . '}. Either the listener did not fire, or resource_id is stored in a form that does not match a string cast of the model id.'
        );

        $old = $this->decodeAuditJson($row->old_values);
        $new = $this->decodeAuditJson($row->new_values);

        $this->assertArrayHasKey('title_en', $old, 'old_values must contain title_en.');
        $this->assertArrayHasKey('title_en', $new, 'new_values must contain title_en.');

        $this->assertSame('Original Title', $old['title_en'], 'RP-01: old_values.title_en must be the pre-update value.');
        $this->assertSame('Updated Title',  $new['title_en'], 'RP-01: new_values.title_en must be the post-update value.');

        $this->assertNotSame(
            $old['title_en'],
            $new['title_en'],
            'RP-01: old_values.title_en and new_values.title_en must differ, not be identical.'
        );

        gc_collect_cycles();
    }

    /**
     * Decode an audit JSON column value. Accepts either a JSON string
     * or an already-cast array. Distinguishes malformed JSON from a
     * valid JSON null via json_last_error().
     *
     * @param  mixed $raw
     * @return array<string, mixed>
     */
    private function decodeAuditJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw)) {
            $this->fail('Audit column value is neither string nor array. Type: ' . get_debug_type($raw));
        }

        $decoded = json_decode($raw, true);

        $this->assertSame(
            JSON_ERROR_NONE,
            json_last_error(),
            sprintf(
                'Audit column is not valid JSON. json_last_error=%d (%s). Raw: %s',
                json_last_error(),
                json_last_error_msg(),
                $raw
            ),
        );

        $this->assertIsArray(
            $decoded,
            'Decoded audit column is not an array. Raw: ' . $raw,
        );

        return $decoded;
    }
    // =========================================================================
    // Audit
    // =========================================================================

    public function test_create_writes_audit_row(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();

        $this->postJson(
            route('api.governance.appointments.store', [
                'governanceBody' => $body->id,
            ]),
            [
                'instrument_type'  => 'resolution',
                'effective_date'   => '2026-09-18',
                'appointment_mode' => 'elected',
            ],
        )->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id'     => $this->tenant->id,
            'action'        => 'governance.appointment.registered',
            'resource_type' => 'GovernanceAppointment',
        ]);

        gc_collect_cycles();
    }

    public function test_update_writes_audit_row(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();
        $appointment = $this->createAppointment($body);

        DB::table('audit_logs')->truncate();

        $this->patchJson(
            route('api.governance.appointments.update', [
                'governanceAppointment' => $appointment->id,
            ]),
            ['title_en' => 'Audited Update'],
        )->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id'     => $this->tenant->id,
            'action'        => 'governance.appointment.updated',
            'resource_type' => 'GovernanceAppointment',
        ]);

        gc_collect_cycles();
    }

    // =========================================================================
    // Fixture helpers
    // =========================================================================

    private function makeTenant(string $slug = 'main', string $domain = 'main.example.test'): Tenant
    {
        return Tenant::create([
            'name_am'   => 'ዋና',
            'name_en'   => 'Main',
            'slug'      => $slug,
            'domain'    => $domain,
            'is_active' => true,
        ]);
    }

    private function makeUser(): User
    {
        $user = User::create([
            'first_name_en'     => 'Test',
            'father_name_en'    => 'User',
            'email'             => 'test@appointments.example.test',
            'password'          => bcrypt('password'),
            'locale'            => 'am',
            'is_active'         => true,
            'default_tenant_id' => $this->tenant->id,
        ]);

        $user->tenants()->attach($this->tenant->id, ['joined_at' => now()]);

        return $user;
    }

    /**
     * Bind the given tenant as the current request's tenant context.
     *
     * REDELIVERY 2: the previous body set a container key
     * (`mases.current_tenant_id`) that the platform's tenancy
     * middleware chain does not read. The platform resolves the
     * tenant from the `X-Tenant-ID` request header
     * (`RequestTenantResolver`). Setting the header on the test
     * client makes every subsequent request in this test method
     * carry it.
     *
     * @param  Tenant $tenant
     * @return void
     */
    private function bindCurrentTenant(Tenant $tenant): void
    {
        $this->withHeader('X-Tenant-ID', (string) $tenant->id);
    }

    private function createGovernancePermission(string $slug, string $name): Permission
    {
        $permission = Permission::create([
            'slug'           => $slug,
            'name_am'        => $name,
            'name_en'        => $name,
            'description_am' => $name,
            'description_en' => $name,
            'category'       => 'governance',
            'scope'          => 'tenant',
            'tenant_id'      => null,
            'application_id' => null,
            'is_active'      => true,
            'is_system'      => true,
        ]);

        $this->user->permissions()->attach($permission->id, [
            'assigned_at' => now(),
        ]);

        return $permission;
    }

    private function createGovernanceBody(array $overrides = []): GovernanceBody
    {
        return GovernanceBody::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'code'      => 'BOARD',
            'body_type' => 'board',
            'name_am'   => 'ቦርድ',
            'name_en'   => 'Board',
            'is_active' => true,
        ], $overrides));
    }

    private function createGovernancePosition(GovernanceBody $body, array $overrides = []): GovernancePosition
    {
        return GovernancePosition::create(array_merge([
            'tenant_id'          => $this->tenant->id,
            'governance_body_id' => $body->id,
            'code'               => 'CHAIR',
            'name_am'            => 'ሰብሳቢ',
            'name_en'            => 'Chairperson',
            'is_voting'          => true,
            'is_officer'         => true,
            'sort_order'         => 0,
            'is_active'          => true,
        ], $overrides));
    }

    private function createGovernanceTerm(GovernanceBody $body, array $overrides = []): GovernanceTerm
    {
        return GovernanceTerm::create(array_merge([
            'tenant_id'          => $this->tenant->id,
            'governance_body_id' => $body->id,
            'label_am'           => 'የ2018-2021 ዘመን',
            'label_en'           => 'Term 2026-2029',
            'effective_from'     => '2026-09-18',
            'effective_until'    => null,
            'is_active'          => true,
        ], $overrides));
    }

    private function createAppointment(GovernanceBody $body, array $overrides = []): GovernanceAppointment
    {
        return GovernanceAppointment::create(array_merge([
            'tenant_id'            => $this->tenant->id,
            'governance_body_id'   => $body->id,
            'instrument_type'      => 'resolution',
            'instrument_reference' => 'RES-2026-000',
            'instrument_date'      => '2026-09-10',
            'effective_date'       => '2026-09-18',
            'appointment_mode'     => 'elected',
            'status'               => 'draft',
            'title_am'             => 'ሹመት',
            'title_en'             => 'Appointment',
        ], $overrides));
    }
}