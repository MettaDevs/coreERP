<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Models;

use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Query bernama dari penjelajah, padanan analisis tersimpan di *Data analysis mode* Business Central (KA-21).
 * Aturan berbaginya sama dengan dasbor: pribadi milik pemiliknya, `shared` terlihat setiap pemegang hak melihat
 * dasbor, dan dijalankan sebagai yang melihat.
 *
 * `code` unik per tenant dan tidak berubah sesudah dibuat, karena publikasi dan feed OData (fase 2) menunjuk
 * query ini dengan kode itu.
 *
 * @property string $id
 * @property string $tenant_id
 * @property int $user_id
 * @property string $code
 * @property string $name
 * @property ?string $description
 * @property bool $shared
 * @property string $dataset_code
 * @property int $dataset_version
 * @property array<string, mixed> $query
 * @property int $version
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 * @property-read ?User $user
 */
#[DataClassification(DataClass::CustomerContent)]
class SavedQuery extends Model
{
    use BindsWithinActiveTenant, HasUlids, SoftDeletes;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'user_id' => DataClass::EndUserPseudonymousIdentifiers,
        'name' => DataClass::CustomerContent,
    ];

    protected $table = 'analytics_saved_queries';

    protected $fillable = ['tenant_id', 'user_id', 'code', 'name', 'description', 'shared', 'dataset_code', 'dataset_version', 'query'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'shared' => 'boolean',
            'dataset_version' => 'integer',
            'query' => 'array',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
