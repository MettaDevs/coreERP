<?php

declare(strict_types=1);

namespace PusatAdmin\Models;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Akun yang sama dengan akun Core, dibaca dari tabel yang sama.
 *
 * Model ini sengaja tidak punya `$fillable`. Konsol operator tidak pernah membuat maupun menyunting
 * akun — itu tetap urusan Core, dan memindahkannya ke sini adalah keputusan yang belum diambil
 * siapa pun. Tanpa `$fillable`, setiap percobaan `create()` atau `fill()` akan gagal seketika alih-
 * alih berhasil diam-diam.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 */
class User extends Authenticatable
{
    protected $table = 'users';

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /** @return HasOne<ProviderAccess, $this> */
    public function aksesProvider(): HasOne
    {
        return $this->hasOne(ProviderAccess::class, 'user_id');
    }

    public function operator(): bool
    {
        return $this->aksesProvider()->where('role', 'provider_admin')->exists();
    }
}
