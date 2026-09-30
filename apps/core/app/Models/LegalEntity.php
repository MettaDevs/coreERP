<?php

namespace App\Models;

use App\Support\Modules\Contracts\DataClass;
use App\Support\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $organization_id
 * @property string $company_code
 * @property string $country_code
 * @property string $timezone Zona IANA bawaan pengguna di entitas legal ini yang belum memilih zonanya sendiri (K-10).
 * @property int $version
 */
#[DataClassification(DataClass::CustomerContent)]
class LegalEntity extends Model
{
    protected $primaryKey = 'organization_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['organization_id', 'tenant_id', 'company_code', 'country_code', 'timezone', 'fiscal_calendar_id'];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<FiscalCalendar, $this> */
    public function fiscalCalendar(): BelongsTo
    {
        return $this->belongsTo(FiscalCalendar::class, 'fiscal_calendar_id');
    }
}
