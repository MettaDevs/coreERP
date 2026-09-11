<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use Illuminate\Support\Carbon;
use Modules\Apperp\ManagementAset\Models\MasterData;

/**
 * Aturan penyusutan yang dapat dipakai ulang lintas aset; padanan "Depreciation profile"
 * di Dynamics 365 F&O. Aturannya hidup di sini, bukan pada group aset: group hanya
 * menunjuk profil mana yang menjadi default.
 *
 * Kolom di bawah adalah tambahan atas bentuk dasar master; bentuk dasarnya disebutkan pada
 * `MasterData`. `rate_percent` di-cast `decimal:4`, jadi Eloquent memulangkannya sebagai
 * string dan bukan float. Isi `manual_schedule` dibiarkan `mixed`: kolomnya `json` dan
 * migration tidak menjanjikan bentuk apa pun, sedangkan bentuk yang divalidasi API hanya
 * berlaku untuk baris yang masuk lewat API.
 *
 * @property string $method
 * @property string $frequency
 * @property string $year_basis
 * @property ?string $convention
 * @property ?int $useful_life_periods
 * @property ?string $rate_percent
 * @property ?array<array-key, mixed> $manual_schedule
 * @property ?Carbon $effective_from
 * @property ?Carbon $effective_to
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

    protected $table = 'aset_m_profil_penyusutan';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif',
        'method', 'frequency', 'year_basis', 'convention',
        'useful_life_periods', 'rate_percent', 'manual_schedule',
        'effective_from', 'effective_to',
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
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}
