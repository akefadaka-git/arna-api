<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_meeting_agenda_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->unsignedSmallInteger('item_number');
            $table->string('title_am', 190)->nullable();
            $table->string('title_en', 190)->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['meeting_id', 'item_number'], 'gmai_meeting_item_uq');
            $table->index(['tenant_id', 'meeting_id', 'status'], 'gmai_tenant_meeting_status_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_meeting_agenda_items');
    }
};
