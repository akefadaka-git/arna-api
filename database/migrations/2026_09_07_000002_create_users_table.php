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
     * Creates the central `users` table – the identity authority for all
     * MASES users. Users can belong to multiple tenants via
     * `tenant_memberships`. The system is Amharic‑first: user‑visible
     * name fields are stored in both Amharic and English so that the
     * UI can display the appropriate version based on the user's
     * selected locale.
     *
     * ይህ ማይግሬሽን የMASES ማእከላዊ የተጠቃሚዎች ሠንጠረዥ (`users`) ይፈጥራል።
     * ተጠቃሚዎች በ `tenant_memberships` በኩል ከብዙ ተከራዮች ጋር ሊገናኙ ይችላሉ።
     * ስርዓቱ አማርኛ‑ቅድሚያ የሚሰጠው ሲሆን የተጠቃሚ ስም መስኮች በሁለቱም በአማርኛ እና በእንግሊዝኛ
     * ተቀምጠዋል፤ ይህም የተጠቃሚው ምርጫ ቋንቋ ላይ ተመስርቶ ትክክለኛውን ስም ለማሳየት ያስችላል።
     *
     * =============================================================================
     * Current schema is defined by the migration below.
     * @return void
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Identity — primary key
            // -----------------------------------------------------------------
            $table->id();

            // -----------------------------------------------------------------
            // Identity — login identifiers
            // -----------------------------------------------------------------
            /*
             * Username. Nullable and unique.
             *
             * Length 64 is a practical upper bound that accommodates
             * the derived usernames the seeder produces (first name
             * lowercased, letters-only, with a numeric suffix on
             * collision) while staying well below the 255-character
             * limit that would force MySQL to use a prefix index.
             */
            $table->string('username', 64)
                  ->nullable()
                  ->unique()
                  ->comment('Login username (optional, unique)');

            /*
             * Email. Unique and required — the primary identity
             * column for the platform.
             *
             * Length 255 matches the RFC 5321 maximum address
             * length and is the Laravel default.
             */
            $table->string('email', 255)
                  ->unique()
                  ->comment('User email (unique)');

            // -----------------------------------------------------------------
            // Identity — Ethiopian bilingual name components
            // -----------------------------------------------------------------
            /*
             * Six columns, three name components (first, father,
             * grandfather) in two languages each. All nullable —
             * a user may have only one language recorded, and the
             * name accessors fall back gracefully.
             */

            // First name (given name).
            $table->string('first_name_am', 100)
                  ->nullable()
                  ->comment('First name in Amharic');
            $table->string('first_name_en', 100)
                  ->nullable()
                  ->comment('First name in English');

            // Father's name.
            $table->string('father_name_am', 100)
                  ->nullable()
                  ->comment('Father name in Amharic');
            $table->string('father_name_en', 100)
                  ->nullable()
                  ->comment('Father name in English');

            // Grandfather's name.
            $table->string('grandfather_name_am', 100)
                  ->nullable()
                  ->comment('Grandfather name in Amharic');
            $table->string('grandfather_name_en', 100)
                  ->nullable()
                  ->comment('Grandfather name in English');

            // -----------------------------------------------------------------
            // Contact
            // -----------------------------------------------------------------
            /*
             * Phone. Optional. Indexed for the login resolver's
             * phone-based lookup. The index is declared inline here
             * rather than in the index block at the end of the
             * migration so that the intent — "this column is
             * queried on every phone login" — is visible at the
             * column itself.
             */
            $table->string('phone', 20)
                  ->nullable()
                  ->index()
                  ->comment('User phone number');

            /*
             * Address. Optional, free-form. Populated from the
             * shareholder register where available; left NULL for
             * users who do not have one on file.
             */
            $table->string('address', 255)
                  ->nullable()
                  ->comment('Residential address');

            // -----------------------------------------------------------------
            // Tenant association
            // -----------------------------------------------------------------
            /*
             * Optional default tenant. See the file docblock
             * §"Foreign keys" for the on-update and on-delete
             * behaviour.
             */
            $table->foreignId('default_tenant_id')
                  ->nullable()
                  ->constrained('tenants')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('Default tenant (optional)');

            // -----------------------------------------------------------------
            // Authentication
            // -----------------------------------------------------------------
            $table->string('password', 255)
                  ->comment('Hashed password');

            $table->rememberToken()
                  ->comment('Remember me token');

            $table->timestamp('email_verified_at')
                  ->nullable()
                  ->comment('Email verified timestamp');

            // -----------------------------------------------------------------
            // Login and password audit
            // -----------------------------------------------------------------
            /*
             * Timestamp of the most recent successful login.
             * Written by User::recordSuccessfulLogin(), called by
             * the AuthController after authentication succeeds.
             *
             * Nullable: users who have never logged in (a
             * shareholder whose account was created but who has
             * not yet signed in) have NULL here.
             */
            $table->timestamp('last_login_at')
                  ->nullable()
                  ->comment('Last successful login timestamp');

            /*
             * Source IP of the most recent successful login.
             *
             * Length 45 is the maximum length of an IPv6 address in
             * its fully-expanded textual form. IPv4 addresses
             * (maximum 15 characters) fit easily.
             *
             * Stored as a string rather than a native IP type
             * because MySQL's `INET6_ATON` functions are not
             * portable to the SQLite and PostgreSQL drivers the
             * test suite may use. String comparison on indexed
             * columns is also faster than function-wrapped
             * comparison on native types for the anomaly-detection
             * queries this column exists to enable.
             */
            $table->string('last_login_ip', 45)
                  ->nullable()
                  ->comment('Last successful login source IP');

            /*
             * Timestamp of the most recent password change.
             *
             * Deliberately separate from `updated_at`. A password
             * rotation policy reads this column; `updated_at` would
             * be wrong because it moves for any change to the row
             * (an address update, a locale change).
             */
            $table->timestamp('password_changed_at')
                  ->nullable()
                  ->comment('Password change timestamp');

            // -----------------------------------------------------------------
            // Presentation and lifecycle
            // -----------------------------------------------------------------
            /*
             * Locale preference. Length 5 accommodates future
             * region-suffixed tags like "am-ET" and "en-US" in
             * addition to the current two-letter values.
             *
             * Default 'am' matches the MASES Amharic-first policy:
             * a user created without an explicit locale is
             * assumed to prefer Amharic until they change it.
             */
            $table->string('locale', 5)
                  ->nullable()
                  ->default('am')
                  ->comment('Preferred locale');

            $table->boolean('is_active')
                  ->default(true)
                  ->comment('Whether the user is active');

            $table->softDeletes()
                  ->comment('Soft delete timestamp');

            $table->timestamps();

            // -----------------------------------------------------------------
            // Supporting indexes
            // -----------------------------------------------------------------
            /*
             * `username` and `email` carry unique indexes from
             * their column declarations.
             *
             * `phone` carries a non-unique index from its column
             * declaration.
             *
             * The three below are additional lookups the
             * application performs.
             */
            $table->index('default_tenant_id');
            $table->index('is_active');
            $table->index('deleted_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the entire `users` table. Because the table is created
     * from scratch by `up()`, the down path is a single
     * `dropIfExists`. There are no separate indexes or constraints
     * to unwind — dropping the table drops everything on it.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};