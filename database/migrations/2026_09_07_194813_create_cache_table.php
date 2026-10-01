<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the cache and cache_locks tables used by Laravel's cache
     * system. These tables are internal and do not contain user‑facing
     * translatable content; therefore, no Amharic/English columns are needed.
     *
     * ይህ ማይግሬሽን በ Laravel መሸጎጫ ሥርዓት የሚጠቀሙትን `cache` እና `cache_locks`
     * ሠንጠረዦችን ይፈጥራል። እነዚህ ሠንጠረዦች ውስጣዊ ናቸው፤ ስለዚህ ተጠቃሚ‑ተኮር የትርጉም ዓምዶች አያስፈልጉም።
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key', 255)->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key', 255)->primary();
            $table->string('owner', 255);
            $table->bigInteger('expiration')->index();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};