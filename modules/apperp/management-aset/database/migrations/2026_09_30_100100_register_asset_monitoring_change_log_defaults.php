<?php

use App\Support\Modules\Contracts\ChangeLogDefaults;
use Illuminate\Database\Migrations\Migration;

/**
 * Setelan bawaan log perubahan dokumen monitoring aset: siapa mengubah tanggal, menyelesaikan, atau
 * mengarsipkan pemeriksaan. Hanya kolom yang terbaca tanpa penerjemah nilai; lokasi dan orang disimpan
 * sebagai ULID, dan riwayat yang menampilkan ULID tidak berguna bagi pembacanya.
 *
 * Migration, bukan seeder, supaya bawaan ini sampai ke tenant yang sudah memasang module.
 */
return new class extends Migration
{
    public function up(): void
    {
        ChangeLogDefaults::register('aset_tr_monitoring_aset', 'Monitoring aset', [
            'tanggal' => 'Tanggal monitoring',
            'status' => 'Status',
            'deleted_at' => 'Diarsipkan',
        ]);
    }

    /** Bawaannya dimatikan, bukan dihapus: tenant mungkin sudah punya riwayat yang menyebut nama field-nya. */
    public function down(): void
    {
        ChangeLogDefaults::disable('aset_tr_monitoring_aset');
    }
};
