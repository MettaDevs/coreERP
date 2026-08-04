<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'api_image',
        'ui_image',
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
