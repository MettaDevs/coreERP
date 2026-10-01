<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tujuh kolom akun baru pada posting group: pelepasan aset (penjualan dan pemusnahan), penurunan nilai
 * (write-down), dan kenaikan nilai (appreciation). Padanannya field `FA Posting Group` Business Central:
 *
 * | Kolom | BC |
 * | --- | --- |
 * | `write_down_account_id` | 4 Write-Down Account |
 * | `write_down_expense_account_id` | 26 Write-Down Expense Acc. |
 * | `appreciation_account_id` | 5 Appreciation Account |
 * | `appreciation_offset_account_id` | 27 Appreciation Bal. Account |
 * | `disposal_proceeds_account_id` | 30 Sales Bal. Acc. |
 * | `disposal_gain_account_id` | 14 Gains Acc. on Disposal |
 * | `disposal_loss_account_id` | 15 Losses Acc. on Disposal |
 *
 * Akun "on Disposal" BC untuk harga perolehan, akumulasi, write-down, dan appreciation (field 8–11) tidak
 * dibuat: jurnal pelepasan membalik saldo ke akun yang sama dengan yang dulu menerimanya, seperti contoh
 * pengisian BC yang menyamakan keduanya. Semua kolom boleh kosong; posting yang membutuhkannya tertahan di
 * Core (K-18) sementara dokumennya tetap tersimpan.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'write_down_account_id',
        'write_down_expense_account_id',
        'appreciation_account_id',
        'appreciation_offset_account_id',
        'disposal_proceeds_account_id',
        'disposal_gain_account_id',
        'disposal_loss_account_id',
    ];

    public function up(): void
    {
        Schema::table('aset_m_posting_group', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                $table->ulid($column)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('aset_m_posting_group', function (Blueprint $table): void {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
