<?php

use App\Platform\Modules\Contracts\ChangeLogDefaults;
use Illuminate\Database\Migrations\Migration;

/**
 * Setelan bawaan log perubahan permintaan pemeliharaan: siapa mengubah deskripsi, mengajukan,
 * memutuskan, atau mengarsipkan permintaan. Hanya kolom yang terbaca tanpa penerjemah nilai.
 *
 * Migration, bukan seeder, supaya bawaan ini sampai ke tenant yang sudah memasang module.
 */
return new class extends Migration
{
    public function up(): void
    {
        ChangeLogDefaults::register('aset_tr_permintaan_pemeliharaan', 'Permintaan pemeliharaan', [
            'deskripsi' => 'Deskripsi',
            'status' => 'Status',
            'alasan_penolakan' => 'Alasan penolakan',
            'deleted_at' => 'Diarsipkan',
        ]);
    }

    /** Bawaannya dimatikan, bukan dihapus: tenant mungkin sudah punya riwayat yang menyebut nama field-nya. */
    public function down(): void
    {
        ChangeLogDefaults::disable('aset_tr_permintaan_pemeliharaan');
    }
};
