<?php

namespace App\Platform\Integration\Models;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Katalog scope sistem luar. Pendaftaran scope tidak membuat endpoint atau memberi akses dengan sendirinya. */
#[DataClassification(DataClass::SystemMetadata)]
class IntegrationScope extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'name'];

    /** @return array<string, string> */
    public static function options(): array
    {
        return self::query()->orderBy('code')->pluck('name', 'code')->all();
    }
}
