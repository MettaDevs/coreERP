<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ControlPlane\OwnedByControlPlane;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Subjek `subject` dari penerbit `issuer` adalah user ini." Alasan bentuknya di migration
 * `create_sso_tables`.
 *
 * @property string $id
 * @property int $user_id
 * @property string $issuer
 * @property string $subject
 * @property ?string $email_at_link
 */
class ExternalIdentity extends Model
{
    use HasUlids;
    use OwnedByControlPlane;

    protected $table = 'external_identities';

    protected $fillable = ['user_id', 'issuer', 'subject', 'email_at_link'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
