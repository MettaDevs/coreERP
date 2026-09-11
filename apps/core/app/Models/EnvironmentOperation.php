<?php

namespace App\Models;

use App\Support\Pusat\MilikPusat;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu tindakan terhadap sebuah environment, beserta hasilnya.
 *
 * `step` dan `failure_message` adalah alasan tabel ini ada. Tanpa keduanya, sebuah penyalinan atau
 * penyediaan yang gagal hanya meninggalkan environment yang tidak bisa dimasuki, dan operator harus
 * menebak di langkah mana ia berhenti — cacat yang sudah berulang di repo ini.
 *
 * Ia tidak memakai `created_at`/`updated_at`: `started_at` sudah merupakan waktu ia dimulai, dan
 * kolom kedua yang berisi hal yang sama hanya menambah tempat untuk berbeda.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $operation
 * @property string $status
 * @property ?string $step
 * @property ?string $failure_message
 */
class EnvironmentOperation extends Model
{
    use HasUlids;
    use MilikPusat;

    public $timestamps = false;

    protected $fillable = [
        'environment_id',
        'operation',
        'status',
        'requested_by',
        'source_environment_id',
        'step',
        'failure_message',
        'detail',
        'started_at',
        'finished_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }
}
