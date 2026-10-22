<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use MaaleArna\MASES\Core\Domains\Shareholder\Services\ShareholderNumberReconciler;

return new class extends Migration
{
    public function up(): void
    {
        app(ShareholderNumberReconciler::class)->reconcile();
    }

    public function down(): void
    {
        throw new RuntimeException('Shareholder numbering and legacy aliases are permanent identity history.');
    }
};
