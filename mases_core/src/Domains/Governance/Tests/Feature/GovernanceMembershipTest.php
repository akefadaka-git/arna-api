<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 26 of 31  (REDELIVERY 2)
 * =============================================================================
 *
 * File:            Tests/Feature/GovernanceMembershipTest.php
 * Delivery type:   Full-file replacement (second patch redelivery)
 *
 * REDELIVERY 2 NOTE
 * -----------------------------------------------------------------------------
 * Same second-patch fix as the sibling
 * `GovernanceAppointmentTest`. The `bindCurrentTenant()` method's
 * body is changed from a container binding to a test-client
 * header:
 *
 *     private function bindCurrentTenant(Tenant $tenant): void
 *     {
 *         $this->withHeader('X-Tenant-ID', (string) $tenant->id);
 *     }
 *
 * See the sibling file's §"REDELIVERY 2 NOTE" for the full root-
 * cause analysis: `TenantContextMiddleware` resolves the tenant
 * from the request's `X-Tenant-ID` header; no header was being
 * sent, so `TenantMembershipMiddleware` refused every request.
 *
 * No other change is made. The 20 test methods, the fixtures, the
 * overlap-invariant tests, the routes referenced, and the
 * deployment-order dependencies on the routes amendment (G2c file
 * 31 of 31) and the audit listener amendment (G2c file 29 of 31)
 * are unchanged.
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
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceTerm;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * GovernanceMembershipTest
 *
 * Feature tests for the GovernanceMembership vertical slice.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Tests\Feature
 * @version 1.0.2
 */
final class GovernanceMembershipTest extends TestCase
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

    public function test_index_returns_paginated_list_of_memberships(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();
        $position = $this->createGovernancePosition($body);

        $this->createMembership($body, $position, ['member_name_en' => 'First']);
        $this->createMembership($body, $position, ['member_name_en' => 'Second']);
        $this->createMembership($body, $position, ['member_name_en' => 'Third']);

        $response = $this->getJson(
            route('api.governance.memberships.index', [
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
            route('api.governance.memberships.index', [
                'governanceBody' => $body->id,
            ]),
        );

        $response->assertStatus(403);

        gc_collect_cycles();
    }

    public function test_index_returns_empty_list_when_body_has_no_memberships(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();

        $response = $this->getJson(
            route('api.governance.memberships.index', [
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
            'tenant_id' => $otherTenant->id,
            'code'      => 'FOREIGN',
            'body_type' => 'board',
            'name_en'   => 'Foreign Body',
            'is_active' => true,
        ]);

        $response = $this->getJson(
            route('api.governance.memberships.index', [
                'governanceBody' => $foreignBody->id,
            ]),
        );

        $response->assertStatus(404);

        gc_collect_cycles();
    }

    // =========================================================================
    // store
    // =========================================================================

    public function test_store_creates_membership_with_valid_payload(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();
        $position = $this->createGovernancePosition($body);
        $term = $this->createGovernanceTerm($body);

        $payload = [
            'governance_position_id' => $position->id,
            'governance_term_id'     => $term->id,
            'user_id'                => $this->user->id,
            'start_date'             => '2026-09-18',
            'end_date'               => '2029-09-17',
            'is_ex_officio'          => false,
        ];

        $response = $this->postJson(
            route('api.governance.memberships.store', [
                'governanceBody' => $body->id,
            ]),
            $payload,
        );

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', 'active');
        $response->assertJsonPath('data.governance_body_id', $body->id);
        $response->assertJsonPath('data.governance_position_id', $position->id);
        $response->assertJsonPath('data.user_id', $this->user->id);

        $this->assertDatabaseHas('governance_memberships', [
            'tenant_id'              => $this->tenant->id,
            'governance_body_id'     => $body->id,
            'governance_position_id' => $position->id,
            'user_id'                => $this->user->id,
            'status'                 => 'active',
        ]);

        gc_collect_cycles();
    }

    public function test_store_forces_status_to_active(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();
        $position = $this->createGovernancePosition($body);

        $response = $this->postJson(
            route('api.governance.memberships.store', [
                'governanceBody' => $body->id,
            ]),
            [
                'governance_position_id' => $position->id,
                'user_id'                => $this->user->id,
                'start_date'             => '2026-09-18',
                'status'                 => 'ended',
            ],
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('governance_memberships', [
            'governance_body_id' => $body->id,
            'status'             => 'active',
        ]);

        gc_collect_cycles();
    }

    public function test_store_merges_body_id_from_url(): void
    {
        gc_collect_cycles();

        $bodyA = $this->createGovernanceBody(['code' => 'A']);
        $bodyB = $this->createGovernanceBody(['code' => 'B']);
        $positionOnA = $this->createGovernancePosition($bodyA);

        $response = $this->postJson(
            route('api.governance.memberships.store', [
                'governanceBody' => $bodyA->id,
            ]),
            [
                'governance_body_id'     => $bodyB->id,
                'governance_position_id' => $positionOnA->id,
                'user_id'                => $this->user->id,
                'start_date'             => '2026-09-18',
            ],
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.governance_body_id', $bodyA->id);

        $this->assertDatabaseHas('governance_memberships', [
            'governance_body_id' => $bodyA->id,
        ]);

        gc_collect_cycles();
    }

    public function test_store_rejects_payload_with_no_member_identity(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();
        $position = $this->createGovernancePosition($body);

        $response = $this->postJson(
            route('api.governance.memberships.store', [
                'governanceBody' => $body->id,
            ]),
            [
                'governance_position_id' => $position->id,
                'start_date'             => '2026-09-18',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['member_name_am']);

        gc_collect_cycles();
    }

    public function test_store_rejects_position_from_different_body(): void
    {
        gc_collect_cycles();

        $bodyA = $this->createGovernanceBody(['code' => 'A']);
        $bodyB = $this->createGovernanceBody(['code' => 'B']);
        $positionOnB = $this->createGovernancePosition($bodyB);

        $response = $this->postJson(
            route('api.governance.memberships.store', [
                'governanceBody' => $bodyA->id,
            ]),
            [
                'governance_position_id' => $positionOnB->id,
                'user_id'                => $this->user->id,
                'start_date'             => '2026-09-18',
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
        $positionOnA = $this->createGovernancePosition($bodyA);
        $termOnB = $this->createGovernanceTerm($bodyB);

        $response = $this->postJson(
            route('api.governance.memberships.store', [
                'governanceBody' => $bodyA->id,
            ]),
            [
                'governance_position_id' => $positionOnA->id,
                'governance_term_id'     => $termOnB->id,
                'user_id'                => $this->user->id,
                'start_date'             => '2026-09-18',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['governance_term_id']);

        gc_collect_cycles();
    }

    public function test_store_rejects_inverted_date_range(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();
        $position = $this->createGovernancePosition($body);

        $response = $this->postJson(
            route('api.governance.memberships.store', [
                'governanceBody' => $body->id,
            ]),
            [
                'governance_position_id' => $position->id,
                'user_id'                => $this->user->id,
                'start_date'             => '2026-09-18',
                'end_date'               => '2026-09-01',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['end_date']);

        gc_collect_cycles();
    }

    public function test_store_rejects_overlapping_membership(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();
        $position = $this->createGovernancePosition($body);

        $this->createMembership($body, $position, [
            'user_id'    => $this->user->id,
            'start_date' => '2026-09-01',
            'end_date'   => '2027-08-31',
        ]);

        $response = $this->postJson(
            route('api.governance.memberships.store', [
                'governanceBody' => $body->id,
            ]),
            [
                'governance_position_id' => $position->id,
                'user_id'                => $this->user->id,
                'start_date'             => '2027-01-01',
                'end_date'               => '2027-12-31',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['start_date']);

        $this->assertDatabaseCount('governance_memberships', 1);

        gc_collect_cycles();
    }

    public function test_store_rejects_overlapping_primary_occupancy_for_a_different_person(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();
        $position = $this->createGovernancePosition($body);

        $this->createMembership($body, $position, [
            'user_id'    => $this->user->id,
            'start_date' => '2026-09-01',
            'end_date'   => '2027-08-31',
        ]);

        $response = $this->postJson(
            route('api.governance.memberships.store', [
                'governanceBody' => $body->id,
            ]),
            [
                'governance_position_id' => $position->id,
                'member_name_en'         => 'Different Primary Person',
                'start_date'             => '2027-01-01',
                'end_date'               => '2027-12-31',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['start_date']);
        $this->assertDatabaseCount('governance_memberships', 1);

        gc_collect_cycles();
    }

    public function test_store_allows_non_overlapping_membership(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();
        $position = $this->createGovernancePosition($body);

        $this->createMembership($body, $position, [
            'user_id'    => $this->user->id,
            'start_date' => '2026-09-01',
            'end_date'   => '2026-12-31',
        ]);

        $response = $this->postJson(
            route('api.governance.memberships.store', [
                'governanceBody' => $body->id,
            ]),
            [
                'governance_position_id' => $position->id,
                'user_id'                => $this->user->id,
                'start_date'             => '2027-01-01',
                'end_date'               => '2027-12-31',
            ],
        );

        $response->assertStatus(201);
        $this->assertDatabaseCount('governance_memberships', 2);

        gc_collect_cycles();
    }

    // =========================================================================
    // show
    // =========================================================================

    public function test_show_returns_single_membership_with_relations(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();
        $position = $this->createGovernancePosition($body);
        $term = $this->createGovernanceTerm($body);
        $appointment = $this->createAppointment($body);

        $membership = $this->createMembership($body, $position, [
            'governance_term_id'        => $term->id,
            'governance_appointment_id' => $appointment->id,
            'user_id'                   => $this->user->id,
            'member_name_en'            => 'Show Test',
        ]);

        $response = $this->getJson(
            route('api.governance.memberships.show', [
                'governanceMembership' => $membership->id,
            ]),
        );

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $membership->id);
        $response->assertJsonPath('data.member_name_en', 'Show Test');
        $response->assertJsonPath('data.body.id', $body->id);
        $response->assertJsonPath('data.position.id', $position->id);
        $response->assertJsonPath('data.term.id', $term->id);
        $response->assertJsonPath('data.appointment.id', $appointment->id);
        $response->assertJsonPath('data.user.id', $this->user->id);

        gc_collect_cycles();
    }

    public function test_show_404s_for_cross_tenant_membership(): void
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
        $foreignPosition = GovernancePosition::create([
            'tenant_id'          => $otherTenant->id,
            'governance_body_id' => $foreignBody->id,
            'code'               => 'CHAIR',
            'name_en'            => 'Chairperson',
            'is_voting'          => true,
            'is_officer'         => true,
            'sort_order'         => 0,
            'is_active'          => true,
        ]);
        $foreignMembership = GovernanceMembership::create([
            'tenant_id'              => $otherTenant->id,
            'governance_body_id'     => $foreignBody->id,
            'governance_position_id' => $foreignPosition->id,
            'member_name_en'         => 'Foreign Member',
            'start_date'             => '2026-09-18',
            'status'                 => 'active',
            'is_ex_officio'          => false,
        ]);

        $response = $this->getJson(
            route('api.governance.memberships.show', [
                'governanceMembership' => $foreignMembership->id,
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
        $position = $this->createGovernancePosition($body);

        $membership = $this->createMembership($body, $position, [
            'user_id'        => $this->user->id,
            'member_name_en' => 'Original Name',
            'start_date'     => '2026-09-18',
            'end_date'       => '2027-09-17',
        ]);

        $response = $this->patchJson(
            route('api.governance.memberships.update', [
                'governanceMembership' => $membership->id,
            ]),
            [
                'member_name_en' => 'Updated Name',
                'end_date'       => '2027-12-31',
            ],
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.member_name_en', 'Updated Name');
        $response->assertJsonPath('data.end_date', '2027-12-31');

        $this->assertDatabaseHas('governance_memberships', [
            'id'             => $membership->id,
            'member_name_en' => 'Updated Name',
        ]);

        gc_collect_cycles();
    }

    public function test_update_rejects_body_id_change(): void
    {
        gc_collect_cycles();

        $bodyA = $this->createGovernanceBody(['code' => 'A']);
        $bodyB = $this->createGovernanceBody(['code' => 'B']);
        $positionOnA = $this->createGovernancePosition($bodyA);

        $membership = $this->createMembership($bodyA, $positionOnA, [
            'user_id' => $this->user->id,
        ]);

        $response = $this->patchJson(
            route('api.governance.memberships.update', [
                'governanceMembership' => $membership->id,
            ]),
            [
                'governance_body_id' => $bodyB->id,
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['governance_body_id']);

        $this->assertDatabaseHas('governance_memberships', [
            'id'                 => $membership->id,
            'governance_body_id' => $bodyA->id,
        ]);

        gc_collect_cycles();
    }

    public function test_update_rejects_status_change(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();
        $position = $this->createGovernancePosition($body);

        $membership = $this->createMembership($body, $position, [
            'user_id' => $this->user->id,
            'status'  => 'active',
        ]);

        $response = $this->patchJson(
            route('api.governance.memberships.update', [
                'governanceMembership' => $membership->id,
            ]),
            [
                'status' => 'ended',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('governance_memberships', [
            'id'     => $membership->id,
            'status' => 'active',
        ]);

        gc_collect_cycles();
    }

    // =========================================================================
    // RP-02 â€” cross-tenant update coverage  (RP-02-A3-COVERAGE-ADDED)
    // =========================================================================
    //
    // Sibling of the appointment additions. Same three properties,
    // membership endpoint. The membership cross-field rule exercised
    // here is the date-ordering invariant (end_date >= start_date).

    public function test_update_404s_for_cross_tenant_membership(): void
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
        $foreignPosition = GovernancePosition::create([
            'tenant_id'          => $otherTenant->id,
            'governance_body_id' => $foreignBody->id,
            'code'               => 'CHAIR',
            'name_en'            => 'Chairperson',
            'is_voting'          => true,
            'is_officer'         => true,
            'sort_order'         => 0,
            'is_active'          => true,
        ]);
        $foreignMembership = GovernanceMembership::create([
            'tenant_id'              => $otherTenant->id,
            'governance_body_id'     => $foreignBody->id,
            'governance_position_id' => $foreignPosition->id,
            'member_name_en'         => 'Foreign Member',
            'start_date'             => '2026-09-18',
            'status'                 => 'active',
            'is_ex_officio'          => false,
        ]);

        $response = $this->patchJson(
            route('api.governance.memberships.update', [
                'governanceMembership' => $foreignMembership->id,
            ]),
            ['member_name_en' => 'Attempted Cross-Tenant Update'],
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
        $foreignPosition = GovernancePosition::create([
            'tenant_id'          => $otherTenant->id,
            'governance_body_id' => $foreignBody->id,
            'code'               => 'CHAIR',
            'name_en'            => 'Chairperson',
            'is_voting'          => true,
            'is_officer'         => true,
            'sort_order'         => 0,
            'is_active'          => true,
        ]);
        $foreignMembership = GovernanceMembership::create([
            'tenant_id'              => $otherTenant->id,
            'governance_body_id'     => $foreignBody->id,
            'governance_position_id' => $foreignPosition->id,
            'member_name_en'         => 'Foreign Member',
            'start_date'             => '2026-09-18',
            'status'                 => 'active',
            'is_ex_officio'          => false,
        ]);

        // If authorize() did not run first, the payload below would
        // merge with the foreign row (start_date 2026-09-18) and the
        // withValidator() date-ordering check (end_date >= start_date)
        // would fire, producing a 422 that discloses the cross-tenant
        // row's start_date. RP-02 requires authorize() to throw before
        // withValidator() runs.
        $response = $this->patchJson(
            route('api.governance.memberships.update', [
                'governanceMembership' => $foreignMembership->id,
            ]),
            [
                'end_date' => '2025-01-01',
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
        $foreignPosition = GovernancePosition::create([
            'tenant_id'          => $otherTenant->id,
            'governance_body_id' => $foreignBody->id,
            'code'               => 'CHAIR',
            'name_en'            => 'Chairperson',
            'is_voting'          => true,
            'is_officer'         => true,
            'sort_order'         => 0,
            'is_active'          => true,
        ]);
        $foreignMembership = GovernanceMembership::create([
            'tenant_id'              => $otherTenant->id,
            'governance_body_id'     => $foreignBody->id,
            'governance_position_id' => $foreignPosition->id,
            'member_name_en'         => 'Foreign Member',
            'start_date'             => '2026-09-18',
            'status'                 => 'active',
            'is_ex_officio'          => false,
        ]);

        $payload = ['member_name_en' => 'Attempted Update'];

        $crossTenantResponse = $this->patchJson(
            route('api.governance.memberships.update', [
                'governanceMembership' => $foreignMembership->id,
            ]),
            $payload,
        );

        $nonexistentResponse = $this->patchJson(
            route('api.governance.memberships.update', [
                'governanceMembership' => 999999,
            ]),
            $payload,
        );

        $crossTenantResponse->assertStatus(404);
        $nonexistentResponse->assertStatus(404);

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
    // pre-update and post-update member_name_en values, and that they
    // differ. The pre-existing audit test only asserts that a row exists.

    public function test_update_records_distinct_old_and_new_member_name_in_audit(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();
        $position = $this->createGovernancePosition($body);

        $membership = $this->createMembership($body, $position, [
            'user_id'        => $this->user->id,
            'member_name_en' => 'Original Name',
        ]);

        DB::table('audit_logs')->truncate();

        $this->patchJson(
            route('api.governance.memberships.update', [
                'governanceMembership' => $membership->id,
            ]),
            ['member_name_en' => 'Updated Name'],
        )->assertStatus(200);

        $row = DB::table('audit_logs')
            ->where('action',        'governance.membership.updated')
            ->where('resource_type', 'GovernanceMembership')
            ->where('resource_id',   (string) $membership->id)
            ->where('tenant_id',     $this->tenant->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull(
            $row,
            'No audit row matched {action=governance.membership.updated, resource_type=GovernanceMembership, resource_id=' . $membership->id . ', tenant_id=' . $this->tenant->id . '}. Either the listener did not fire, or resource_id is stored in a form that does not match a string cast of the model id.'
        );

        $old = $this->decodeAuditJson($row->old_values);
        $new = $this->decodeAuditJson($row->new_values);

        $this->assertArrayHasKey('member_name_en', $old, 'old_values must contain member_name_en.');
        $this->assertArrayHasKey('member_name_en', $new, 'new_values must contain member_name_en.');

        $this->assertSame('Original Name', $old['member_name_en'], 'RP-01: old_values.member_name_en must be the pre-update value.');
        $this->assertSame('Updated Name',  $new['member_name_en'], 'RP-01: new_values.member_name_en must be the post-update value.');

        $this->assertNotSame(
            $old['member_name_en'],
            $new['member_name_en'],
            'RP-01: old_values.member_name_en and new_values.member_name_en must differ, not be identical.'
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
        $position = $this->createGovernancePosition($body);

        $this->postJson(
            route('api.governance.memberships.store', [
                'governanceBody' => $body->id,
            ]),
            [
                'governance_position_id' => $position->id,
                'user_id'                => $this->user->id,
                'start_date'             => '2026-09-18',
            ],
        )->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id'     => $this->tenant->id,
            'action'        => 'governance.membership.registered',
            'resource_type' => 'GovernanceMembership',
        ]);

        gc_collect_cycles();
    }

    public function test_update_writes_audit_row(): void
    {
        gc_collect_cycles();

        $body = $this->createGovernanceBody();
        $position = $this->createGovernancePosition($body);

        $membership = $this->createMembership($body, $position, [
            'user_id' => $this->user->id,
        ]);

        DB::table('audit_logs')->truncate();

        $this->patchJson(
            route('api.governance.memberships.update', [
                'governanceMembership' => $membership->id,
            ]),
            ['member_name_en' => 'Audited Update'],
        )->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id'     => $this->tenant->id,
            'action'        => 'governance.membership.updated',
            'resource_type' => 'GovernanceMembership',
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
            'email'             => 'test@memberships.example.test',
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
     * REDELIVERY 2: same fix as the sibling appointment test — the
     * platform resolves the tenant from the `X-Tenant-ID` request
     * header, not from a container key.
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

    private function createMembership(
        GovernanceBody $body,
        GovernancePosition $position,
        array $overrides = [],
    ): GovernanceMembership {
        return GovernanceMembership::create(array_merge([
            'tenant_id'              => $this->tenant->id,
            'governance_body_id'     => $body->id,
            'governance_position_id' => $position->id,
            'member_name_am'         => null,
            'member_name_en'         => 'Test Member',
            'external_reference'     => null,
            'user_id'                => null,
            'start_date'             => '2026-09-18',
            'end_date'               => null,
            'status'                 => 'active',
            'is_ex_officio'          => false,
            'ended_reason'           => null,
        ], $overrides));
    }
}