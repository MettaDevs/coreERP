<?php

namespace App\Models;

use App\Support\ControlPlane\OwnedByControlPlane;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
 * @property ?int $requested_by
 * @property Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?Carbon $lease_until
 */
class EnvironmentOperation extends Model
{
    use HasUlids;
    use OwnedByControlPlane;

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
        'lease_until',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'started_at' => 'datetime',
            'lease_until' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Siapa yang meminta operasi ini, bila memang ada manusia di baliknya.
     *
     * Kosong berarti penjadwal, dan layar riwayat menuliskannya "Sistem". Membedakan keduanya
     * adalah seluruh guna kolomnya — riwayat yang menamai penjadwal dan manusia dengan kata yang
     * sama menghapus satu-satunya keterangan yang membedakan keduanya.
     *
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
