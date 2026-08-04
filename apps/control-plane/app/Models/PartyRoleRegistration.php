<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan bahwa satu party memegang satu peran pada satu legal entity.
 *
 * Ditulis app pemilik peran lewat API internal. Data bisnis perannya — termin
 * pembayaran pemasok, batas kredit pelanggan — tetap di database app. Registry
 * ini hanya membuat pertanyaan "pemasok ini pelanggan kita juga?" dapat dijawab
 * tanpa query lintas database app.
 */
class PartyRoleRegistration extends Model
{
    use HasUlids;

    public const ROLES = ['customer', 'vendor', 'worker', 'contact', 'prospect', 'competitor', 'applicant'];

    protected $fillable = ['tenant_id', 'party_id', 'role_code', 'legal_entity_id', 'owning_app_id'];

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }
}
