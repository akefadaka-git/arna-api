<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_dependencies', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignUuid('depends_on_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'task_id', 'depends_on_task_id'], 'task_dependencies_unique_edge');
            $table->index(['tenant_id', 'depends_on_task_id'], 'task_dependencies_reverse_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_dependencies');
    }
};
