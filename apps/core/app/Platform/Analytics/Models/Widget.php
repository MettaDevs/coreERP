<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Models;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Satu tile, grafik, tabel, atau teks di satu dasbor. `query` adalah query analitik yang sudah divalidasi
 * saat disimpan (`WidgetDefinition`): query biasa memakai bentuk ringkas `POST api/v1/analytics/query`, sedangkan
 * widget gabungan menyimpan dua query ringkas beserta versi dataset masing-masing. `visual` adalah cara menggambarnya,
 * aturannya per jenis widget di `docs/todo/analitik/dasbor-dan-visual.md`.
 *
 * Widget teks tidak punya dataset maupun query. `dataset_version` adalah versi dataset saat query disimpan:
 * kunci yang sesudahnya diganti nama module dipetakan saat dibaca (`StoredQuery`), dan kunci yang hilang
 * membuat widget berstatus `field_removed`, bukan galat 500. `cache_ttl_seconds` diteruskan ke cache hasil area 9.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $dashboard_id
 * @property string $title
 * @property string $type
 * @property ?string $dataset_code
 * @property ?int $dataset_version
 * @property ?array<string, mixed> $query
 * @property array<string, mixed> $visual
 * @property ?int $cache_ttl_seconds
 * @property int $version
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 * @property-read ?Dashboard $dashboard
 */
#[DataClassification(DataClass::CustomerContent)]
class Widget extends Model
{
    use BindsWithinActiveTenant, HasUlids, SoftDeletes;

    /** Jenis widget yang dapat disimpan; `blend` menambah gabungan lintas dataset (area 14). */
    public const TYPES = ['kpi', 'bar', 'column', 'line', 'area', 'donut', 'table', 'text', 'blend'];

    protected $table = 'analytics_widgets';

    protected $fillable = ['tenant_id', 'dashboard_id', 'title', 'type', 'dataset_code', 'dataset_version', 'query', 'visual', 'cache_ttl_seconds'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dataset_version' => 'integer',
            'query' => 'array',
            'visual' => 'array',
            'cache_ttl_seconds' => 'integer',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Dashboard, $this> */
    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }
}
