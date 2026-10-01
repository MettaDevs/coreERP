<?php

use App\Platform\Modules\Contracts\ChangeLogDefaults;
use Illuminate\Database\Migrations\Migration;

/**
 * Setelan bawaan log perubahan pekerja (TODO analisa gap BC 2.8): identitas pekerja dan akun pengguna yang
 * ditautkan, dicatat saat pekerja dibuat dan saat diubah. Tenant dapat menggantinya di layar Riwayat
 * perubahan. Lihat {@see ChangeLogDefaults}.
 *
 * Migration, bukan seeder, supaya bawaan ini sampai ke tenant yang sudah memasang module.
 */
return new class extends Migration
{
    public function up(): void
    {
        ChangeLogDefaults::register('hr_workers', 'Pekerja', [
            'personnel_number' => 'Nomor pegawai',
            'name' => 'Nama',
            'email' => 'Email',
            'core_membership_id' => 'Akun pengguna',
            'deleted_at' => 'Diarsipkan',
        ]);
    }

    /** Bawaannya dimatikan, bukan dihapus: tenant mungkin sudah punya riwayat yang menyebut nama field-nya. */
    public function down(): void
    {
        ChangeLogDefaults::disable('hr_workers');
    }
};
