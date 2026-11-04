<?php

declare(strict_types=1);

namespace App\Platform\Identity\Support;

use App\Platform\Access\Support\ContextCache;
use Illuminate\Auth\EloquentUserProvider;

/**
 * Penyedia pengguna `people` yang memulihkan pengguna sesi lewat {@see ContextCache}.
 *
 * Hanya `retrieveById` yang lewat cache, karena itulah yang dipanggil guard sesi pada setiap
 * permintaan. Login dengan kata sandi, remember-me, dan reset kata sandi tetap membaca database seperti
 * sebelumnya. Selama cache mati, method ini sama persis dengan milik induknya.
 */
final class ContextCachedUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier)
    {
        return app(ContextCache::class)->rememberModel(
            'user|'.$this->model.'|'.$identifier,
            fn () => parent::retrieveById($identifier),
        );
    }
}
