<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalEntity extends Model
{
    protected $primaryKey = 'organization_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['organization_id', 'tenant_id', 'company_code', 'country_code', 'fiscal_calendar_id'];

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
