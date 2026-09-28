<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Setelan feed posting finance satu entitas legal.
 *
 * @property string $legal_entity_id
 * @property string $tenant_id
 * @property bool $enabled
 * @property ?Carbon $cutover_date
 */
class FinancePostingSetting extends Model
{
    protected $primaryKey = 'legal_entity_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['legal_entity_id', 'tenant_id', 'enabled', 'cutover_date'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'cutover_date' => 'date'];
    }
}
