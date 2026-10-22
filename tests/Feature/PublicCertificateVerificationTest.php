<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareCertificate;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareClass;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class PublicCertificateVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_reference_returns_invalid_without_private_metadata(): void
    {
        $response = $this->postJson('/api/public/document-verifications/verify', [
            'reference' => 'NO-SUCH-CERTIFICATE',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.reference', 'NO-SUCH-CERTIFICATE')
            ->assertJsonPath('data.status', 'invalid')
            ->assertJsonPath('data.documentType', 'Share certificate')
            ->assertJsonPath('data.issuedAt', null)
            ->assertJsonMissingPath('data.shareholder')
            ->assertJsonMissingPath('data.tenant_id')
            ->assertJsonMissingPath('data.id');
    }

    public function test_issued_certificate_can_be_verified_without_authentication(): void
    {
        $tenant = Tenant::create([
            'slug' => 'public-verification-tenant',
            'name_am' => 'የማረጋገጫ ቴናንት',
            'name_en' => 'Public Verification Tenant',
            'domain' => 'public-verification.example.test',
            'is_active' => true,
        ]);

        $shareholder = Shareholder::create([
            'tenant_id' => $tenant->id,
            'shareholder_type' => 'individual',
            'first_name_am' => 'ሙከራ',
            'father_name_am' => 'ሰው',
            'is_active' => true,
        ]);

        $shareClass = ShareClass::create([
            'code' => 'PUBLIC-VERIFY',
            'name_am' => 'የማረጋገጫ ክፍል',
            'name_en' => 'Verification Class',
            'par_value' => '100.0000',
            'currency' => 'ETB',
            'is_active' => true,
        ]);

        $issuedAt = now()->startOfSecond();

        ShareCertificate::create([
            'shareholder_id' => $shareholder->id,
            'share_class_id' => $shareClass->id,
            'certificate_number' => 'SH-CERT-PUBLIC-0001',
            'status' => 'issued',
            'issued_at' => $issuedAt,
        ]);

        $response = $this->postJson('/api/public/document-verifications/verify', [
            'reference' => 'SH-CERT-PUBLIC-0001',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.reference', 'SH-CERT-PUBLIC-0001')
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.documentType', 'Share certificate')
            ->assertJsonPath('data.issuedAt', $issuedAt->toIso8601String())
            ->assertJsonMissingPath('data.shareholder')
            ->assertJsonMissingPath('data.tenant_id')
            ->assertJsonMissingPath('data.id');
    }

    public function test_issued_status_without_issue_timestamp_is_not_verified(): void
    {
        $certificate = $this->createCertificate('SH-CERT-MISSING-ISSUED-AT-0001', 'issued');
        $certificate->issued_at = null;
        $certificate->save();

        $this->postJson('/api/public/document-verifications/verify', [
            'reference' => 'SH-CERT-MISSING-ISSUED-AT-0001',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'invalid')
            ->assertJsonPath('data.issuedAt', null)
            ->assertJsonMissingPath('data.shareholder')
            ->assertJsonMissingPath('data.tenant_id')
            ->assertJsonMissingPath('data.id');
    }

    public function test_cancelled_certificate_is_reported_as_revoked_without_private_metadata(): void
    {
        $tenant = Tenant::create([
            'slug' => 'revoked-verification-tenant',
            'name_am' => 'የተሰረዘ ማረጋገጫ ቴናንት',
            'name_en' => 'Revoked Verification Tenant',
            'domain' => 'revoked-verification.example.test',
            'is_active' => true,
        ]);

        $shareholder = Shareholder::create([
            'tenant_id' => $tenant->id,
            'shareholder_type' => 'individual',
            'first_name_am' => 'ሙከራ',
            'father_name_am' => 'ሰው',
            'is_active' => true,
        ]);

        $shareClass = ShareClass::create([
            'code' => 'PUBLIC-REVOKED',
            'name_am' => 'የተሰረዘ ክፍል',
            'name_en' => 'Revoked Class',
            'par_value' => '100.0000',
            'currency' => 'ETB',
            'is_active' => true,
        ]);

        ShareCertificate::create([
            'shareholder_id' => $shareholder->id,
            'share_class_id' => $shareClass->id,
            'certificate_number' => 'SH-CERT-REVOKED-0001',
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $this->postJson('/api/public/document-verifications/verify', [
            'reference' => 'SH-CERT-REVOKED-0001',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'revoked')
            ->assertJsonPath('data.issuedAt', null)
            ->assertJsonMissingPath('data.shareholder')
            ->assertJsonMissingPath('data.tenant_id')
            ->assertJsonMissingPath('data.id');
    }

    public function test_draft_certificate_is_invalid_because_it_was_never_issued(): void
    {
        $this->createCertificate('SH-CERT-DRAFT-0001', 'draft');

        $this->postJson('/api/public/document-verifications/verify', [
            'reference' => 'SH-CERT-DRAFT-0001',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'invalid')
            ->assertJsonPath('data.issuedAt', null)
            ->assertJsonMissingPath('data.shareholder')
            ->assertJsonMissingPath('data.tenant_id')
            ->assertJsonMissingPath('data.id');
    }

    public function test_replaced_certificate_is_reported_as_revoked(): void
    {
        $replacement = $this->createCertificate('SH-CERT-REPLACEMENT-0002', 'issued');
        $this->createCertificate('SH-CERT-REPLACED-0001', 'replaced', false, $replacement->id);

        $this->postJson('/api/public/document-verifications/verify', [
            'reference' => 'SH-CERT-REPLACED-0001',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'revoked')
            ->assertJsonPath('data.issuedAt', null)
            ->assertJsonMissingPath('data.shareholder')
            ->assertJsonMissingPath('data.tenant_id')
            ->assertJsonMissingPath('data.id');
    }

    public function test_soft_deleted_certificate_cannot_be_reported_as_verified(): void
    {
        $certificate = $this->createCertificate('SH-CERT-DELETED-0001', 'issued');
        $certificate->delete();

        $this->postJson('/api/public/document-verifications/verify', [
            'reference' => 'SH-CERT-DELETED-0001',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'revoked')
            ->assertJsonPath('data.issuedAt', null)
            ->assertJsonMissingPath('data.shareholder')
            ->assertJsonMissingPath('data.tenant_id')
            ->assertJsonMissingPath('data.id');
    }

    public function test_route_enforces_ten_requests_per_minute(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson('/api/public/document-verifications/verify', [
                'reference' => 'UNKNOWN-RATE-LIMIT-REFERENCE',
            ])->assertOk();
        }

        $this->postJson('/api/public/document-verifications/verify', [
            'reference' => 'UNKNOWN-RATE-LIMIT-REFERENCE',
        ])->assertTooManyRequests();
    }

    public function test_reference_is_required_and_bounded(): void
    {
        $this->postJson('/api/public/document-verifications/verify', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->postJson('/api/public/document-verifications/verify', [
            'reference' => str_repeat('X', 65),
        ])->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    private function createCertificate(
        string $number,
        string $status,
        bool $delete = false,
        ?int $replacedById = null,
    ): ShareCertificate {
        $suffix = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $number) ?? $number);

        $tenant = Tenant::create([
            'slug' => 'verification-' . $suffix,
            'name_am' => 'የሙከራ ቴናንት',
            'name_en' => 'Verification Test Tenant',
            'domain' => $suffix . '.example.test',
            'is_active' => true,
        ]);

        $shareholder = Shareholder::create([
            'tenant_id' => $tenant->id,
            'shareholder_type' => 'individual',
            'first_name_am' => 'ሙከራ',
            'father_name_am' => 'ሰው',
            'is_active' => true,
        ]);

        $shareClass = ShareClass::create([
            'code' => 'VERIFY-' . strtoupper(substr(hash('sha256', $number), 0, 12)),
            'name_am' => 'የሙከራ ክፍል',
            'name_en' => 'Verification Test Class',
            'par_value' => '100.0000',
            'currency' => 'ETB',
            'is_active' => true,
        ]);

        $certificate = ShareCertificate::create([
            'shareholder_id' => $shareholder->id,
            'share_class_id' => $shareClass->id,
            'certificate_number' => $number,
            'status' => $status,
            'issued_at' => $status === 'issued' ? now() : null,
            'cancelled_at' => $status === 'cancelled' ? now() : null,
            'replaced_by_id' => $replacedById,
        ]);

        if ($delete) {
            $certificate->delete();
        }

        return $certificate;
    }
}
