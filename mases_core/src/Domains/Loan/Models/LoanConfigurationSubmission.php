<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;

final class LoanConfigurationSubmission extends Model
{
    protected $table = 'loan_configuration_submissions';

    protected $fillable = [
        'tenant_id', 'revision_product_id', 'previous_policy_version_id', 'created_by', 'reviewed_by', 'published_product_id',
        'published_policy_version_id', 'status', 'configuration',
        'submitted_at', 'reviewed_at', 'review_note', 'activated_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'revision_product_id' => 'integer',
        'previous_policy_version_id' => 'integer',
        'created_by' => 'integer',
        'reviewed_by' => 'integer',
        'published_product_id' => 'integer',
        'published_policy_version_id' => 'integer',
        'configuration' => 'array',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'activated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function publishedProduct(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'published_product_id');
    }

    public function publishedPolicy(): BelongsTo
    {
        return $this->belongsTo(VersionedPolicy::class, 'published_policy_version_id');
    }
}
