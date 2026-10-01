<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\Insurance;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Polis asuransi; padanan *Insurance* Business Central (kartu polis).
 *
 * Nomor polis adalah data organisasi, bukan data pribadi. Penanggung disimpan sebagai id vendor Core
 * dan namanya dibaca ulang saat ditampilkan.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $creation_key
 * @property string $kode
 * @property string $legal_entity_id
 * @property string $nama
 * @property string $nomor_polis
 * @property ?string $jenis_asuransi_id
 * @property ?string $vendor_id
 * @property Carbon $berlaku_mulai
 * @property ?Carbon $berlaku_sampai
 * @property string $premi_tahunan
 * @property string $nilai_pertanggungan
 * @property bool $diblokir
 * @property ?string $keterangan
 * @property int $version
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class InsurancePolicy extends Model
{
    use BelongsToTenant;
    use HasUlids, SoftDeletes;

    /** @var array<string, DataClass> Nama polis, bukan nama orang. */
    public const COLUMN_CLASSIFICATION = [
        'nama' => DataClass::CustomerContent,
    ];

    protected $table = 'aset_m_polis_asuransi';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'legal_entity_id', 'nama', 'nomor_polis', 'jenis_asuransi_id',
        'vendor_id', 'berlaku_mulai', 'berlaku_sampai', 'premi_tahunan', 'nilai_pertanggungan', 'diblokir',
        'keterangan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'berlaku_mulai' => 'date',
            'berlaku_sampai' => 'date',
            'premi_tahunan' => 'decimal:2',
            'nilai_pertanggungan' => 'decimal:2',
            'diblokir' => 'boolean',
            'version' => 'integer',
        ];
    }

    /** @return HasMany<InsuranceCoverage, $this> */
    public function coverages(): HasMany
    {
        return $this->hasMany(InsuranceCoverage::class, 'polis_asuransi_id');
    }
}
