<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Apperp\ManagementAset\Models\MasterData;

/**
 * Buku penyusutan; padanan "Book" di Dynamics 365 F&O. Satu aset dapat memiliki
 * beberapa buku sekaligus — lazimnya satu komersial dan satu fiskal — dan tiap buku
 * melacak nilai aset secara mandiri dengan aturannya sendiri.
 *
 * Kolom di bawah adalah tambahan atas bentuk dasar master; bentuk dasarnya disebutkan
 * pada `MasterData`. `round_off_depreciation` di-cast `decimal:2`, jadi Eloquent
 * memulangkannya sebagai string dan bukan float.
 *
 * `posting_layer` satu-satunya saklar posting (K-15): buku `none` tidak pernah di-post ke aplikasi
 * finance. Saklar lama `export_to_backoffice` sudah dibuang (TODO 8.4.3).
 *
 * @property string $posting_layer
 * @property string $round_off_depreciation
 * @property ?string $depreciation_profile_id
 * @property ?string $alternative_profile_id
 */
class BukuPenyusutan extends MasterData
{
    /** Lapisan pembukuan; `none` berarti buku memorandum. */
    public const POSTING_LAYERS = ['current', 'operations', 'tax', self::POSTING_LAYER_NONE];

    /**
     * Buku memorandum: dihitung dan dilaporkan, tetapi tidak pernah di-post. Padanan "Post to
     * general ledger = No" di F&O, yang dengan sendirinya menjadikan posting layer *None*.
     */
    public const POSTING_LAYER_NONE = 'none';

    /**
     * Delapan konvensi yang sama dengan F&O. Konvensi menentukan kapan penyusutan
     * dimulai pada tahun pertama, dihitung dari tanggal aset mulai digunakan.
     */
    public const CONVENTIONS = [
        'none',
        'full_month',
        'half_year',
        'mid_quarter',
        'mid_month_1st',
        'mid_month_15th',
        'half_year_start_of_year',
        'half_year_next_year',
    ];

    protected $table = 'aset_m_buku_penyusutan';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif',
        'posting_layer', 'round_off_depreciation',
        'depreciation_profile_id', 'alternative_profile_id',
    ];

    protected function casts(): array
    {
        return [...parent::casts(),
            'round_off_depreciation' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<ProfilPenyusutan, $this> */
    public function profil(): BelongsTo
    {
        return $this->belongsTo(ProfilPenyusutan::class, 'depreciation_profile_id');
    }

    /** @return BelongsTo<ProfilPenyusutan, $this> */
    public function profilAlternatif(): BelongsTo
    {
        return $this->belongsTo(ProfilPenyusutan::class, 'alternative_profile_id');
    }
}
