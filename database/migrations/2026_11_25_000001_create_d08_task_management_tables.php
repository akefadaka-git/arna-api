<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('task_scope', 20)->default('personal');
            $table->string('title', 240);
            $table->text('description')->nullable();
            $table->string('status', 24)->default('todo');
            $table->string('priority', 16)->default('normal');
            $table->date('due_on')->nullable();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'due_on'], 'tasks_tenant_status_due_idx');
            $table->index(['tenant_id', 'created_by'], 'tasks_tenant_creator_idx');
            $table->index(['tenant_id', 'assigned_to_user_id'], 'tasks_tenant_assignee_idx');
        });

        Schema::create('task_audit_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->json('changes')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'task_id'], 'task_audit_tenant_task_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_audit_entries');
        Schema::dropIfExists('tasks');
    }
};
