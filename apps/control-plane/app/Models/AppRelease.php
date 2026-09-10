<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan rilis satu app: satu image edisi, bukan lagi sepasang image API dan UI.
 *
 * `api_service`, `ui_service`, dan `database_service` boleh kosong. Ketiganya hanya berarti
 * untuk app yang masih berjalan sebagai container sendiri; edisi satu image tidak punya
 * layanan terpisah untuk disebut namanya.
 */
class AppRelease extends Model
{
    protected $table = 'app_releases';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'app_id',
        'version',
        'manifest_sha256',
        'edition_image',
        'bundle_path',
        'compose_file',
        'compose_project',
        'api_service',
        'ui_service',
        'database_service',
        'status',
    ];

    /** @return BelongsTo<CoreApp, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(CoreApp::class, 'app_id');
    }
}
