<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Data acuan dapat dipasang ulang setelah database test dikosongkan. */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'gender' => ['female' => 'Perempuan', 'male' => 'Laki-laki', 'unspecified' => 'Tidak disebutkan'],
            'marital_status' => ['single' => 'Belum menikah', 'married' => 'Menikah', 'divorced' => 'Cerai', 'widowed' => 'Pasangan meninggal'],
            'abc_code' => ['A' => 'A', 'B' => 'B', 'C' => 'C'],
            'language' => ['id' => 'Bahasa Indonesia', 'en' => 'English'],
            'role' => ['vendor' => 'Vendor', 'customer' => 'Pelanggan', 'worker' => 'Pegawai', 'contact' => 'Kontak', 'prospect' => 'Calon pelanggan', 'competitor' => 'Pesaing', 'applicant' => 'Pelamar'],
        ] as $category => $values) {
            foreach ($values as $code => $name) {
                DB::table('party_reference_values')->insertOrIgnore(['category' => $category, 'code' => $code, 'name' => $name]);
            }
        }
        foreach (['person' => 'Perorangan', 'organization' => 'Organisasi', 'legal_entity' => 'Entitas legal', 'operating_unit' => 'Unit operasional', 'team' => 'Tim'] as $code => $name) {
            DB::table('party_types')->where('code', $code)->update(['name' => $name]);
        }

        foreach ([
            ['contact', 'Kontak dari', 'Memiliki kontak', 'person', 'organization'],
            ['subsidiary', 'Anak perusahaan dari', 'Induk perusahaan dari', 'organization', 'organization'],
            ['branch', 'Cabang dari', 'Memiliki cabang', 'organization', 'organization'],
        ] as [$code, $name, $inverse, $from, $to]) {
            DB::table('party_relationship_types')->insertOrIgnore(['code' => $code, 'name' => $name, 'inverse_name' => $inverse, 'from_type' => $from, 'to_type' => $to]);
        }
    }

    public function down(): void
    {
        // Mundur image tetap mempertahankan data acuan.
    }
};
