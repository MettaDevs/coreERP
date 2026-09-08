<?php

namespace App\Models\master;

use App\Models\MasterData;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Buku penyusutan; padanan "Book" di Dynamics 365 F&O. Satu aset dapat memiliki
 * beberapa buku sekaligus — lazimnya satu komersial dan satu fiskal — dan tiap buku
 * melacak nilai aset secara mandiri dengan aturannya sendiri.
 */
class BukuPenyusutan extends MasterData
{
    /** Lapisan pembukuan; `none` berarti buku memorandum. */
    public const POSTING_LAYERS = ['current', 'operations', 'tax', 'none'];

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

    protected $table = 'm_buku_penyusutan';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif',
        'posting_layer', 'export_to_backoffice', 'round_off_depreciation',
        'depreciation_profile_id', 'alternative_profile_id',
    ];

    protected function casts(): array
    {
        return [...parent::casts(),
            'export_to_backoffice' => 'boolean',
            'round_off_depreciation' => 'decimal:2',
        ];
    }

    public function profil(): BelongsTo
    {
        return $this->belongsTo(ProfilPenyusutan::class, 'depreciation_profile_id');
    }

    public function profilAlternatif(): BelongsTo
    {
        return $this->belongsTo(ProfilPenyusutan::class, 'alternative_profile_id');
    }
}
