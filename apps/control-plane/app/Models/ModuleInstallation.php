<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Module apa yang terpasang untuk tenant mana.
 *
 * Ini bukan katalog dan bukan entitlement. Katalog berarti platform mengenal produknya;
 * entitlement berarti tenant berhak memakainya; baris di sini berarti migration-nya sudah
 * dijalankan dan module-nya benar-benar ada untuk tenant itu. Layar berlabel "terpasang"
 * wajib membaca tabel ini, tidak boleh menyimpulkannya dari entitlement.
 */
final class ModuleInstallation extends Model
{
    public const STATUS_INSTALLED = 'installed';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_UNINSTALLED = 'uninstalled';

    protected $table = 'core_module_installations';

    /** Kunci utamanya gabungan, jadi tidak ada satu kolom yang bisa disebut primary key. */
    public $incrementing = false;

    protected $primaryKey = null;

    protected $keyType = 'string';

    protected $fillable = [
        'tenant_id',
        'module_id',
        'version',
        'status',
        'seeded_at',
        'installed_at',
        'disabled_at',
        'uninstalled_at',
    ];

    protected $casts = [
        'seeded_at' => 'immutable_datetime',
        'installed_at' => 'immutable_datetime',
        'disabled_at' => 'immutable_datetime',
        'uninstalled_at' => 'immutable_datetime',
    ];

    /** Data awal hanya boleh diisi sekali seumur hidup pemasangan, walau module dipasang ulang. */
    public function sudahDiisiDataAwal(): bool
    {
        return $this->seeded_at !== null;
    }

    public function sedangAktif(): bool
    {
        return $this->status === self::STATUS_INSTALLED;
    }
}
