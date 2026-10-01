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
     * Creates the `password_reset_tokens` table used by Laravel's built‑in
     * password reset functionality. When a user requests a password reset,
     * a hashed token is stored here along with their email address. This
     * token is later validated when the user submits the reset form.
     *
     * ይህ ማይግሬሽን ላራቬል አብሮ የተሰራውን የይለፍ ቃል ማስጀመር ተግባር የሚያገለግል
     * `password_reset_tokens` ሠንጠረዥ ይፈጥራል። ተጠቃሚ የይለፍ ቃል ማስጀመር ሲጠይቅ፣
     * የተጠቃሚው ኢሜይል እና የተመስጠረ ቶከን እዚህ ይቀመጣሉ። ይህ ቶከን በኋላ
     * ተጠቃሚው ቅጹን ሲያስገባ ይረጋገጣል።
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            // The email address of the user requesting the reset.
            // This column is the primary key, so there is at most one
            // active reset token per email address.
            $table->string('email', 255)
                  ->primary()
                  ->comment('User email (primary key)');

            // The hashed reset token.
            $table->string('token', 255)
                  ->comment('Hashed password reset token');

            // Timestamp when the token was created.
            // Laravel uses this to enforce token expiration.
            $table->timestamp('created_at')
                  ->nullable()
                  ->comment('Token creation timestamp');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};