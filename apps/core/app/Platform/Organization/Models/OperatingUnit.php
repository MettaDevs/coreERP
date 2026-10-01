<?php

namespace App\Platform\Organization\Models;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $organization_id
 * @property ?string $tenant_id
 * @property string $type
 * @property ?string $number
 */
#[DataClassification(DataClass::CustomerContent)]
class OperatingUnit extends Model
{
    /**
     * Bentuk nomor unit: huruf besar dan angka, dipisah tanda hubung tunggal. Tanpa spasi.
     *
     * Nomor ini dikirim ke aplikasi finance sebagai nilai dimensi dan disimpan di tabel penerjemah
     * mereka. Spasi, huruf kecil, dan tanda hubung di ujung adalah sumber ketidakcocokan yang tidak
     * terlihat mata, jadi ditolak di sini, bukan dirapikan diam-diam di sisi pembaca.
     */
    public const NUMBER_FORMAT = '/^[A-Z0-9]+(-[A-Z0-9]+)*$/';

    public const NUMBER_LENGTH = 30;

    protected $primaryKey = 'organization_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['organization_id', 'tenant_id', 'type', 'number'];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
