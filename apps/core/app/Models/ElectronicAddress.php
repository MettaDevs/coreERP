<?php

namespace App\Models;

use App\Support\Modules\Contracts\DataClass;
use App\Support\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kontak elektronik pada satu tempat: email, telepon, WhatsApp, fax, atau URL. Menempel ke tempat, bukan
 * ke party, seperti Dynamics 365: telepon cabang melekat pada cabangnya, dan kontak pada tempat yang dipakai
 * bersama ikut terbagi. WhatsApp jenis tersendiri, bukan telepon berlabel, karena kop dan dokumen
 * menampilkannya terpisah dari nomor telepon kantor.
 *
 * @property string $location_id
 * @property string $type
 * @property string $value
 * @property bool $is_primary
 * @property int $version
 */
#[DataClassification(DataClass::CustomerContent)]
class ElectronicAddress extends Model
{
    use HasUlids, SoftDeletes;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'value' => DataClass::EndUserIdentifiableInformation,
    ];

    public const TYPES = ['email', 'phone', 'whatsapp', 'fax', 'url'];

    protected $fillable = ['tenant_id', 'location_id', 'type', 'value', 'purpose', 'is_primary'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
