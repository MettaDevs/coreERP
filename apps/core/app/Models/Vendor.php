<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Akun vendor: satu party yang memegang peran `vendor` pada satu entitas legal (K-06).
 *
 * Nama, alamat, dan kontak vendor milik party-nya di buku alamat, bukan kolom di sini. Mengganti
 * nama party berarti mengganti nama vendor di setiap entitas legal sekaligus, persis seperti Global
 * Address Book Dynamics 365. Posting finance yang sudah terbit menyimpan salinan nomor dan nama pada
 * saat terbit, jadi riwayatnya tidak ikut berubah.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $legal_entity_id
 * @property string $party_id
 * @property string $number
 * @property ?string $tax_number
 * @property string $status
 * @property ?string $creation_key
 * @property ?string $created_by_user_id
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Party $party
 */
class Vendor extends Model
{
    use HasUlids;

    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    /** Kode referensi number sequence milik Core untuk nomor vendor. */
    public const NUMBER_SEQUENCE = 'core.vendor';

    protected $fillable = [
        'tenant_id', 'legal_entity_id', 'party_id', 'number', 'tax_number', 'status',
        'creation_key', 'created_by_user_id',
    ];

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
