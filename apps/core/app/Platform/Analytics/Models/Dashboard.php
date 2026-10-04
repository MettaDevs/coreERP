<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Models;

use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Dasbor analitik: susunan widget milik satu pengguna, padanan Role Center buatan pengguna. Dasbor `shared`
 * terlihat oleh setiap pemegang hak melihat dasbor di tenant, dan widget-nya selalu dihitung sebagai **yang
 * melihat**, bukan pembuatnya (`docs/todo/analitik/keamanan.md`). Membagikan dasbor membagikan susunannya,
 * bukan hak penyusunnya.
 *
 * `layout` hanya memuat letak yang pernah diatur pengguna; widget tanpa letak ditempatkan di bawah oleh
 * `DashboardPresenter`, supaya menambah widget tidak mengubah versi dasbor. `slicers`, `template_code`, dan
 * `template_version` milik fase 2.
 *
 * @property string $id
 * @property string $tenant_id
 * @property int $user_id
 * @property string $name
 * @property ?string $description
 * @property bool $shared
 * @property list<array{widget_id: string, x: int, y: int, w: int, h: int}> $layout
 * @property list<array<string, mixed>> $slicers
 * @property ?string $template_code
 * @property ?int $template_version
 * @property int $version
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 * @property-read ?User $user
 * @property-read Collection<int, Widget> $widgets
 */
#[DataClassification(DataClass::CustomerContent)]
class Dashboard extends Model
{
    use BindsWithinActiveTenant, HasUlids, SoftDeletes;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'user_id' => DataClass::EndUserPseudonymousIdentifiers,
        'name' => DataClass::CustomerContent,
    ];

    protected $table = 'analytics_dashboards';

    protected $fillable = ['tenant_id', 'user_id', 'name', 'description', 'shared', 'layout'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'shared' => 'boolean',
            'layout' => 'array',
            'slicers' => 'array',
            'template_version' => 'integer',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Widget, $this> */
    public function widgets(): HasMany
    {
        return $this->hasMany(Widget::class);
    }
}
