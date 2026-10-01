<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Posting group aset: akun yang dipakai jurnal aset satu group, per tanggal berlaku (TODO 8.1).
 * Padanan *FA Posting Groups* Business Central. Bukan master berkode: identitasnya pasangan
 * group aset dan `effective_from`.
 *
 * Setiap kolom akun menyimpan id daftar akun referensi milik Core (`AccountDirectory`), bukan nomornya,
 * supaya impor ulang daftar akun yang mengganti nomor atau nama tidak memutus pemetaan (K-05).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $group_aset_id
 * @property Carbon $effective_from
 * @property ?string $acquisition_account_id
 * @property ?string $accumulated_depreciation_account_id
 * @property ?string $depreciation_expense_account_id
 * @property ?string $payable_account_id
 * @property ?string $clearing_account_id
 * @property ?string $input_vat_account_id
 * @property ?string $opening_balance_offset_account_id
 * @property ?string $grant_offset_account_id
 * @property ?string $write_down_account_id
 * @property ?string $write_down_expense_account_id
 * @property ?string $appreciation_account_id
 * @property ?string $appreciation_offset_account_id
 * @property ?string $disposal_proceeds_account_id
 * @property ?string $disposal_gain_account_id
 * @property ?string $disposal_loss_account_id
 * @property int $version
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 */
#[DataClassification(DataClass::CustomerContent)]
class AssetPostingGroup extends Model
{
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    /**
     * Lima belas akun, urut seperti kolom matriks, dengan label yang dipakai layar dan pesan masalah
     * posting ("Group KENDARAAN belum punya akun beban penyusutan"). Tujuh yang terakhir dipakai
     * penyesuaian nilai dan pelepasan aset; padanan field BC-nya ada di migration
     * `2026_10_01_100000_add_disposal_and_revaluation_accounts_to_aset_posting_group`.
     */
    public const ACCOUNTS = [
        'acquisition_account_id' => 'Harga perolehan',
        'accumulated_depreciation_account_id' => 'Akumulasi penyusutan',
        'depreciation_expense_account_id' => 'Beban penyusutan',
        'payable_account_id' => 'Lawan hutang',
        'clearing_account_id' => 'Perantara',
        'input_vat_account_id' => 'PPN Masukan',
        'opening_balance_offset_account_id' => 'Penyeimbang saldo awal',
        'grant_offset_account_id' => 'Lawan hibah',
        'write_down_account_id' => 'Akumulasi penurunan nilai',
        'write_down_expense_account_id' => 'Beban penurunan nilai',
        'appreciation_account_id' => 'Kenaikan nilai aset',
        'appreciation_offset_account_id' => 'Lawan kenaikan nilai',
        'disposal_proceeds_account_id' => 'Hasil penjualan aset',
        'disposal_gain_account_id' => 'Laba pelepasan',
        'disposal_loss_account_id' => 'Rugi pelepasan',
    ];

    /**
     * Akun yang dipakai setiap group yang asetnya diterima dan disusutkan, sehingga selnya ditandai
     * merah selama kosong (K-22). Lawan hutang termasuk karena mode bawaan entitas legal adalah
     * `direct_payable` (K-10).
     *
     * Akun lainnya hanya dipakai keadaan tertentu, dan tanda merah untuk keadaan yang tidak
     * pernah terjadi di sebuah tenant hanya melatih orang mengabaikan tanda itu:
     * - perantara hanya untuk entitas legal bermode `clearing`;
     * - PPN Masukan hanya untuk penerimaan yang membawa PPN (K-11);
     * - penyeimbang saldo awal hanya untuk saldo awal saat cutover (K-13);
     * - lawan hibah hanya untuk penerimaan hibah;
     * - akun penurunan dan kenaikan nilai hanya untuk tenant yang menyesuaikan nilai aset;
     * - akun pelepasan hanya saat aset dijual atau dimusnahkan.
     *
     * Posting yang membutuhkan akun kosong tetap tertahan di Core dengan jalan pintas ke layar ini.
     */
    public const REQUIRED_ACCOUNTS = [
        'acquisition_account_id',
        'accumulated_depreciation_account_id',
        'depreciation_expense_account_id',
        'payable_account_id',
    ];

    protected $table = 'aset_m_posting_group';

    protected $fillable = [
        'tenant_id', 'group_aset_id', 'effective_from',
        'acquisition_account_id', 'accumulated_depreciation_account_id', 'depreciation_expense_account_id',
        'payable_account_id', 'clearing_account_id', 'input_vat_account_id', 'opening_balance_offset_account_id',
        'grant_offset_account_id', 'write_down_account_id', 'write_down_expense_account_id',
        'appreciation_account_id', 'appreciation_offset_account_id', 'disposal_proceeds_account_id',
        'disposal_gain_account_id', 'disposal_loss_account_id',
    ];

    protected function casts(): array
    {
        return ['effective_from' => 'date'];
    }

    /** @return BelongsTo<GroupAset, $this> */
    public function groupAset(): BelongsTo
    {
        return $this->belongsTo(GroupAset::class, 'group_aset_id');
    }

    /**
     * Akun wajib yang masih kosong, urut seperti kolom matriks.
     *
     * @return list<string>
     */
    public function missingRequiredAccounts(): array
    {
        return array_values(array_filter(
            self::REQUIRED_ACCOUNTS,
            fn (string $column): bool => $this->getAttribute($column) === null,
        ));
    }
}
