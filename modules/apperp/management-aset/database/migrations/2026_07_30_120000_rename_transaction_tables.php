<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        't_aset' => 'tr_penerimaan_aset',
        't_aset_penempatan' => 'tr_penempatan_aset',
        't_buku_aset' => 'tr_buku_aset',
        't_penyusutan_periode' => 'tr_penyusutan_aset',
        't_export_penyusutan' => 'tr_export_penyusutan',
        't_dokumen_siklus_aset' => 'tr_dokumen_siklus_aset',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $from => $to) {
            Schema::rename($from, $to);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES, true) as $from => $to) {
            Schema::rename($to, $from);
        }
    }
};
