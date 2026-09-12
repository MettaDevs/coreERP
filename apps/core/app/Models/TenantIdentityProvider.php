<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Pusat\MilikPusat;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cara sebuah tenant masuk: kata sandi lokal, penyedia bersama milik kita, atau penyedianya sendiri.
 *
 * **Sisi pusat, dan itu bukan pilihan melainkan definisi.** Setelan ini menjawab "siapa yang boleh
 * masuk", dan jawabannya harus sama untuk seluruh lingkungan milik tenant itu — produksi, sandbox,
 * maupun demo. Menaruhnya di sisi environment berarti sandbox punya daftar penyedia identitasnya
 * sendiri, dan menyuntingnya di sandbox untuk mencobanya akan mengubah cara orang masuk ke
 * produksi — atau lebih buruk, tidak mengubahnya, sehingga dua tempat menjawab berbeda.
 *
 * Model ini sengaja ada meski belum ada satu pun kode yang membacanya. Penjaga
 * `FkMenyeberangBatasTest` menurunkan daftar tabel sisi pusat dari model yang memakai `MilikPusat`,
 * jadi tabel pusat **tanpa** model akan terhitung di sisi yang salah dan foreign key-nya terbaca
 * sebagai pelanggaran batas. Yang menjaga batas hanya dapat melihat apa yang ditandai.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $mode
 * @property ?string $protokol
 * @property bool $aktif
 */
class TenantIdentityProvider extends Model
{
    use HasUlids;
    use MilikPusat;

    protected $table = 'tenant_identity_providers';

    protected $fillable = ['tenant_id', 'mode', 'protokol', 'setelan', 'domain_email', 'aktif'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'setelan' => 'array',
            'domain_email' => 'array',
            'aktif' => 'boolean',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
