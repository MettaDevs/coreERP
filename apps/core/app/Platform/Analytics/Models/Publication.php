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
 * Publikasi analitik (area 15): query tersimpan yang sengaja dibuka pengguna tenant untuk dibaca sistem di luar
 * CoreERP, padanan entity OData dari query object di Business Central. Klien integrasi bukan anggota tenant,
 * jadi ia tidak pernah menjalankan query bebas (KA-11); ia membaca publikasi, dan publikasi dihitung sebagai
 * **pemiliknya saat ini** (`owner_user_id`), dipersempit saringan terkunci, dengan hak yang diperiksa ulang pada
 * setiap permintaan (`Security\PublicationPrincipal`).
 *
 * Yang dibaca sistem luar adalah salinan query (`dataset_code`, `dataset_version`, `query`) yang diambil dari
 * query tersimpan saat dipublikasikan. Query tersimpan yang kemudian diubah tidak mengubah publikasi sampai
 * pemiliknya menerapkannya: yang keluar selalu benda yang sudah ditinjau pemilik publikasi.
 *
 * `code` unik per tenant dan tidak berubah sesudah dibuat, karena ia bagian alamat sistem luar. Status `active`,
 * `paused` (dihentikan sementara, dapat dilanjutkan), dan `revoked` (dicabut, tidak dapat dihidupkan lagi).
 * Kolom embed milik area 17 dan belum diisi.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property ?string $description
 * @property string $kind
 * @property ?string $saved_query_id
 * @property ?string $dashboard_id
 * @property ?string $dataset_code
 * @property ?int $dataset_version
 * @property ?array<string, mixed> $query
 * @property int $owner_user_id
 * @property string $timezone
 * @property array<string, array<string, string|list<string>>> $locked_filters
 * @property list<string> $client_ids
 * @property list<string> $formats
 * @property list<string> $embed_origins
 * @property list<array<string, mixed>> $embed_parameters
 * @property ?int $min_group_size
 * @property string $status
 * @property ?Carbon $last_used_at
 * @property int $version
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 * @property-read ?User $owner
 * @property-read ?SavedQuery $savedQuery
 */
#[DataClassification(DataClass::CustomerContent)]
class Publication extends Model
{
    use BindsWithinActiveTenant, HasUlids, SoftDeletes;

    public const KIND_QUERY = 'query';

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const REVOKED = 'revoked';

    /** Format baris yang dapat dipilih di area ini; `odata` menyusul bersama feed OData (area 16). */
    public const FORMATS = ['json', 'csv'];

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'owner_user_id' => DataClass::EndUserPseudonymousIdentifiers,
        'name' => DataClass::CustomerContent,
        'client_ids' => DataClass::SystemMetadata,
    ];

    protected $table = 'analytics_publications';

    protected $fillable = [
        'tenant_id', 'code', 'name', 'description', 'kind', 'saved_query_id', 'dataset_code', 'dataset_version', 'query',
        'owner_user_id', 'timezone', 'locked_filters', 'client_ids', 'formats', 'min_group_size', 'status',
    ];

    protected $attributes = [
        'kind' => self::KIND_QUERY,
        'status' => self::ACTIVE,
        'locked_filters' => '{}',
        'client_ids' => '[]',
        'formats' => '["json"]',
        'embed_origins' => '[]',
        'embed_parameters' => '[]',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dataset_version' => 'integer',
            'query' => 'array',
            'owner_user_id' => 'integer',
            'locked_filters' => 'array',
            'client_ids' => 'array',
            'formats' => 'array',
            'embed_origins' => 'array',
            'embed_parameters' => 'array',
            'min_group_size' => 'integer',
            'last_used_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return BelongsTo<SavedQuery, $this> */
    public function savedQuery(): BelongsTo
    {
        return $this->belongsTo(SavedQuery::class);
    }

    public function allowsClient(string $clientId): bool
    {
        return in_array($clientId, $this->client_ids, true);
    }

    /**
     * Saringan terkunci untuk dataset publikasi ini. Saringan disimpan per dataset; yang tersimpan untuk dataset
     * lain tidak pernah berlaku di sini.
     *
     * @return array<string, string|list<string>>
     */
    public function lockedFiltersFor(string $dataset): array
    {
        return $this->locked_filters[$dataset] ?? [];
    }
}
