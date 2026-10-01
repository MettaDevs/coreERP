<?php

use App\Platform\Modules\Contracts\ChangeLogDefaults;
use Illuminate\Database\Migrations\Migration;

/**
 * Setelan bawaan log perubahan register aset (TODO analisa gap BC 2.8): field yang menjawab "siapa
 * memindahkan, mengganti status, atau mengarsipkan aset ini", dicatat saat aset dibuat dan saat diubah.
 * Tenant dapat menggantinya di layar Riwayat perubahan. Lihat {@see ChangeLogDefaults}.
 *
 * Migration, bukan seeder, supaya bawaan ini sampai ke tenant yang sudah memasang module.
 */
return new class extends Migration
{
    public function up(): void
    {
        ChangeLogDefaults::register('aset_tr_aset', 'Aset', [
            'nama' => 'Nama',
            'lifecycle_state' => 'Status',
            'lokasi_aset_id' => 'Lokasi',
            'responsible_org_unit_id' => 'Unit penanggung jawab',
            'kondisi_aset_id' => 'Kondisi',
            'deleted_at' => 'Diarsipkan',
        ]);
    }

    /** Bawaannya dimatikan, bukan dihapus: tenant mungkin sudah punya riwayat yang menyebut nama field-nya. */
    public function down(): void
    {
        ChangeLogDefaults::disable('aset_tr_aset');
    }
};
