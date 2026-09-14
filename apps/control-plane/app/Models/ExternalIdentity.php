<?php

declare(strict_types=1);

namespace ControlPlane\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * "Subjek `subject` dari penerbit `issuer` adalah user ini" — tabel `external_identities` milik Core.
 *
 * Satu tabel untuk kedua aplikasi, dan itu disengaja. Penerbit dan subjeknya sama bagi Core dan
 * konsol, karena yang diidentifikasi orangnya, bukan aplikasinya; menghubungkan SSO sekali di salah
 * satu sisi berlaku di sisi yang lain. Aturan kapan baris ini boleh lahir juga sama — tidak pernah
 * lewat email — dan alasannya ditulis di migration `create_sso_tables` milik Core.
 *
 * @property string $id
 * @property int $user_id
 * @property string $issuer
 * @property string $subject
 * @property ?string $email_at_link
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ExternalIdentity extends Model
{
    use HasUlids;

    protected $table = 'external_identities';

    protected $fillable = ['user_id', 'issuer', 'subject', 'email_at_link'];
}
