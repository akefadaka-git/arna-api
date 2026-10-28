<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique('people_public_id_unique')
                ->comment('Immutable ARNA-issued UUID; not a login credential or authorization grant');
            $table->string('first_name_am', 100)->nullable();
            $table->string('father_name_am', 100)->nullable();
            $table->string('grandfather_name_am', 100)->nullable();
            $table->string('first_name_en', 100)->nullable();
            $table->string('father_name_en', 100)->nullable();
            $table->string('grandfather_name_en', 100)->nullable();
            $table->foreignId('user_id')->nullable()
                ->unique('people_user_id_unique')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'deleted_at'], 'people_status_deleted_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
