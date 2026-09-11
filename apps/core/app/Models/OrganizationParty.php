<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jembatan organisasi ke party. Legal entity dan operating unit ikut menjadi
 * party karena keduanya punya nama dan alamat yang tercetak pada dokumen resmi.
 */
class OrganizationParty extends Model
{
    protected $primaryKey = 'organization_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['organization_id', 'tenant_id', 'party_id'];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }
}
