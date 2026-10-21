<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            if (DB::table('shareholders')->whereNull('shareholder_number')->where('id', '>', 100)->exists()) {
                throw new RuntimeException(
                    'Existing shareholder IDs above 100 require an Owner-approved mapping before numbering can continue.'
                );
            }

            $now = now();
            $holders = DB::table('shareholders')
                ->whereBetween('id', [1, 100])
                ->orderBy('id')
                ->get(['id', 'tenant_id', 'shareholder_number']);

            foreach ($holders as $holder) {
                $id = (int) $holder->id;
                $tenantId = (int) $holder->tenant_id;
                $number = 'SH-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
                $legacy = 'SH-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);

                if ($holder->shareholder_number !== null && $holder->shareholder_number !== $number) {
                    throw new RuntimeException("Conflicting shareholder number on record {$id}.");
                }

                $otherOwner = DB::table('shareholders')
                    ->where('tenant_id', $tenantId)
                    ->where('shareholder_number', $number)
                    ->where('id', '<>', $id)
                    ->exists();

                if ($otherOwner) {
                    throw new RuntimeException("{$number} is already assigned in tenant {$tenantId}.");
                }

                if ($holder->shareholder_number === null) {
                    DB::table('shareholders')->where('id', $id)->update([
                        'shareholder_number' => $number,
                        'updated_at' => $now,
                    ]);
                }

                $reservation = DB::table('shareholder_number_reservations')
                    ->where('tenant_id', $tenantId)
                    ->where(function ($query) use ($id, $number): void {
                        $query->where('number_sequence', $id)->orWhere('shareholder_number', $number);
                    })
                    ->first();

                if ($reservation !== null
                    && ((int) $reservation->number_sequence !== $id
                        || $reservation->shareholder_number !== $number
                        || ($reservation->shareholder_id !== null && (int) $reservation->shareholder_id !== $id))) {
                    throw new RuntimeException("Conflicting reservation for {$number}.");
                }

                if ($reservation === null) {
                    DB::table('shareholder_number_reservations')->insert([
                        'tenant_id' => $tenantId,
                        'number_sequence' => $id,
                        'shareholder_number' => $number,
                        'shareholder_id' => $id,
                        'reserved_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $legacyKey = strtoupper(trim($legacy));
                $alias = DB::table('shareholder_legacy_aliases')
                    ->where('tenant_id', $tenantId)
                    ->where('legacy_number_key', $legacyKey)
                    ->first();

                if ($alias !== null && (int) $alias->shareholder_id !== $id) {
                    throw new RuntimeException("Conflicting legacy alias {$legacy}.");
                }

                if ($alias === null) {
                    DB::table('shareholder_legacy_aliases')->insert([
                        'tenant_id' => $tenantId,
                        'legacy_number' => $legacy,
                        'legacy_number_key' => $legacyKey,
                        'source_system' => 'legacy-numbering-compatibility',
                        'source_record_key' => (string) $id,
                        'shareholder_id' => $id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            foreach (DB::table('shareholders')->select('tenant_id')->distinct()->pluck('tenant_id') as $tenantIdValue) {
                $tenantId = (int) $tenantIdValue;
                $maxReserved = (int) DB::table('shareholder_number_reservations')
                    ->where('tenant_id', $tenantId)->max('number_sequence');
                $maxIssued = 0;

                foreach (DB::table('shareholders')->where('tenant_id', $tenantId)->whereNotNull('shareholder_number')->pluck('shareholder_number') as $issued) {
                    if (!preg_match('/^SH-(\d+)$/i', (string) $issued, $matches)) {
                        throw new RuntimeException("Unknown shareholder-number format in tenant {$tenantId}.");
                    }
                    $maxIssued = max($maxIssued, (int) $matches[1]);
                }

                $next = max($maxReserved, $maxIssued) + 1;
                $sequence = DB::table('shareholder_number_sequences')->where('tenant_id', $tenantId)->first();

                if ($sequence === null) {
                    DB::table('shareholder_number_sequences')->insert([
                        'tenant_id' => $tenantId,
                        'next_number' => max(1, $next),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } elseif ((int) $sequence->next_number < $next) {
                    DB::table('shareholder_number_sequences')->where('tenant_id', $tenantId)->update([
                        'next_number' => $next,
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Shareholder numbering and legacy aliases are permanent identity history.');
    }
};
