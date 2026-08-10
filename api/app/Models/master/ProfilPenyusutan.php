<?php

namespace App\Models\master;

use App\Models\MasterData;

/**
 * Aturan penyusutan yang dapat dipakai ulang lintas aset; padanan "Depreciation profile"
 * di Dynamics 365 F&O. Aturannya hidup di sini, bukan pada group aset: group hanya
 * menunjuk profil mana yang menjadi default.
 */
class ProfilPenyusutan extends MasterData
{
    /**
     * `straight_line_life_remaining` adalah pasangan wajib dari saldo menurun: begitu
     * saldo menurun kalah, penyusutan berpindah ke sini agar aset tetap habis di akhir
     * masa manfaat. Tanpa ia terdaftar, profil penggantinya tidak dapat dibuat.
     */
    public const METHODS = ['straight_line', 'straight_line_life_remaining', 'reducing_balance', 'manual', 'consumption'];

    public const FREQUENCIES = ['monthly', 'quarterly', 'half_yearly', 'yearly'];

    public const YEAR_BASIS = ['calendar', 'fiscal'];

    protected $table = 'm_profil_penyusutan';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif',
        'method', 'frequency', 'year_basis', 'convention',
        'useful_life_periods', 'rate_percent', 'manual_schedule',
    ];

    protected function casts(): array
    {
        return [
            ...parent::casts(),
            // Kolomnya `json` di database dan divalidasi sebagai array. Tanpa cast ini,
            // menyimpan array PHP lewat API melempar di PDO PostgreSQL.
            'manual_schedule' => 'array',
            'useful_life_periods' => 'integer',
            'rate_percent' => 'decimal:4',
        ];
    }
}
