<?php

declare(strict_types=1);

namespace Modules\Apperp\ContohA\Models;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Penjualan barang, bahan uji engine analitik. Katalog field-nya ditulis seperti model module sungguhan
 * (K-30), karena dataset membacanya lewat `fieldsFromModel()`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $barang_id
 * @property string $legal_entity_id
 * @property string $org_unit_id
 * @property string $status
 * @property string $nilai
 * @property string $currency_code
 */
#[DataClassification(DataClass::CustomerContent)]
final class Penjualan extends Model
{
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'nama_pembeli' => DataClass::EndUserIdentifiableInformation,
        'dicatat_oleh_user_id' => DataClass::EndUserPseudonymousIdentifiers,
    ];

    public const FIELD_CAPTIONS = [
        'barang_id' => 'Barang',
        'org_unit_id' => 'Unit kerja',
        'status' => 'Status',
        'nilai' => 'Nilai',
        'currency_code' => 'Mata uang',
        'tanggal' => 'Tanggal',
        'dicatat_pada' => 'Dicatat pada',
        'dibayar_pada' => 'Dibayar pada',
        'nama_pembeli' => 'Nama pembeli',
    ];

    public const FIELD_OPTIONS = [
        'status' => ['draf' => 'Draf', 'terbit' => 'Terbit', 'batal' => 'Batal'],
    ];

    public const FIELD_LOOKUPS = [
        'barang_id' => 'barang',
        'org_unit_id' => 'reference-data/unit-kerja',
    ];

    public const FIELD_HIDDEN = [
        'legal_entity_id' => 'Entitas legal dipilih lewat workspace.',
        'dicatat_oleh_user_id' => 'Dinyatakan dataset sebagai dimensi bersama pengguna.',
        'keterangan' => 'Catatan bebas, tidak bermakna sebagai pengelompok.',
    ];

    protected $table = 'contoh_a_tr_penjualan';

    protected $fillable = [
        'tenant_id', 'barang_id', 'legal_entity_id', 'org_unit_id', 'status', 'nilai', 'currency_code',
        'tanggal', 'dicatat_pada', 'dibayar_pada', 'dicatat_oleh_user_id', 'nama_pembeli', 'keterangan',
    ];
}
