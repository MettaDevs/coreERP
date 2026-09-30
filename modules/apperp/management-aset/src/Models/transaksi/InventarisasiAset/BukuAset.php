<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset;

use App\Support\Modules\Contracts\DataClass;
use App\Support\Modules\Contracts\DataClassification;
use App\Support\Modules\Contracts\MilikTenant;
use App\Support\Modules\Contracts\TableFields;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Buku penyusutan satu aset: satu baris per pasangan aset dan buku.
 *
 * Kolom uangnya di-cast `decimal:2`, jadi Eloquent memulangkannya sebagai string, bukan
 * float. `closed_on` sengaja tidak ikut `casts()` — ia hanya ditulis lewat query update,
 * tidak pernah dibaca sebagai properti — sehingga tipenya tetap string apa adanya.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $aset_id
 * @property ?string $buku_id
 * @property ?string $depreciation_profile_id
 * @property ?string $alternative_profile_id
 * @property string $book_code
 * @property ?int $useful_life_periods
 * @property ?string $convention
 * @property ?Carbon $depreciation_start_on
 * @property bool $depreciate
 * @property string $round_off_depreciation
 * @property string $acquisition_value
 * @property string $residual_value
 * @property string $accumulated_depreciation
 * @property string $opening_accumulated_depreciation
 * @property int $elapsed_periods_offset
 * @property string $net_book_value
 * @property string $status
 * @property ?string $closed_on
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class BukuAset extends Model
{
    use HasUlids;
    use MilikTenant;

    /**
     * Nama kolom untuk filter tambahan laporan (K-30), padanan Caption field tabel di BC; tipe kolom dibaca
     * dari database. Lihat {@see TableFields}.
     *
     * @var array<string, string>
     */
    public const FIELD_CAPTIONS = [
        'buku_id' => 'Buku penyusutan',
        'book_code' => 'Kode buku',
        'depreciation_profile_id' => 'Profil penyusutan',
        'alternative_profile_id' => 'Profil pengganti',
        'useful_life_periods' => 'Masa manfaat (jumlah periode)',
        'convention' => 'Perlakuan periode pertama',
        'depreciation_start_on' => 'Tanggal mulai penyusutan',
        'depreciate' => 'Disusutkan',
        'round_off_depreciation' => 'Kelipatan pembulatan penyusutan',
        'acquisition_value' => 'Nilai perolehan',
        'residual_value' => 'Nilai sisa',
        'opening_accumulated_depreciation' => 'Akumulasi penyusutan saldo awal',
        'status' => 'Status buku',
        'closed_on' => 'Tanggal buku ditutup',
    ];

    /** @var array<string, array<string, string>> */
    public const FIELD_OPTIONS = [
        'status' => ['active' => 'Aktif', 'closed' => 'Ditutup'],
        // Label sama dengan pilihan di matriks group x buku.
        'convention' => [
            'none' => 'Tanpa penyesuaian',
            'full_month' => 'Bulan perolehan penuh',
            'mid_month_1st' => 'Tengah bulan (awal bulan)',
            'mid_month_15th' => 'Tengah bulan (tanggal 15)',
            'mid_quarter' => 'Tengah kuartal',
            'half_year' => 'Setengah tahun',
            'half_year_start_of_year' => 'Setengah tahun (mulai awal tahun)',
            'half_year_next_year' => 'Setengah tahun (mulai tahun depan)',
        ],
    ];

    /** @var array<string, string> Resource pemilih untuk kolom rujukan. */
    public const FIELD_LOOKUPS = [
        'buku_id' => 'buku-penyusutan',
        'depreciation_profile_id' => 'profil-penyusutan',
        'alternative_profile_id' => 'profil-penyusutan',
    ];

    /** @var array<string, string> Kolom yang sengaja tidak ditawarkan sebagai filter, dengan alasannya. */
    public const FIELD_HIDDEN = [
        'aset_id' => 'Kunci aset pemilik buku; saring lewat bagian Aset.',
        'accumulated_depreciation' => 'Nilai hari ini; laporan penyusutan menghitung akumulasi per periode dari riwayat penyusutan.',
        'net_book_value' => 'Nilai hari ini; laporan penyusutan menghitung nilai buku per periode dari riwayat penyusutan.',
        'elapsed_periods_offset' => 'Angka koreksi internal penghitung umur untuk saldo awal, tidak bermakna sebagai filter.',
    ];

    protected $table = 'aset_tr_buku_aset';

    protected $fillable = [
        'tenant_id', 'aset_id', 'buku_id', 'depreciation_profile_id', 'alternative_profile_id', 'book_code',
        'useful_life_periods', 'convention', 'depreciation_start_on', 'depreciate', 'round_off_depreciation',
        'acquisition_value', 'residual_value', 'accumulated_depreciation',
        'opening_accumulated_depreciation', 'elapsed_periods_offset', 'net_book_value', 'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'acquisition_value' => 'decimal:2',
            'residual_value' => 'decimal:2',
            'accumulated_depreciation' => 'decimal:2',
            'opening_accumulated_depreciation' => 'decimal:2',
            'elapsed_periods_offset' => 'integer',
            'net_book_value' => 'decimal:2',
            'useful_life_periods' => 'integer',
            'depreciation_start_on' => 'date',
            'depreciate' => 'boolean',
            'round_off_depreciation' => 'decimal:2',
        ];
    }
}
