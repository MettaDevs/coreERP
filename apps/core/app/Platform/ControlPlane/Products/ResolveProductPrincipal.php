<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Products;

use App\Platform\ControlPlane\Models\ProductCustomer;
use App\Platform\Identity\Models\ExternalIdentity;
use App\Platform\Identity\Models\User;
use Illuminate\Support\Str;

final class ResolveProductPrincipal
{
    public function handle(ProductCustomer $customer): User
    {
        $customer = ProductCustomer::query()->lockForUpdate()->findOrFail($customer->id);
        abort_unless($customer->email_verified_at !== null && is_string($customer->verified_email), 422, 'Verifikasi email sebelum membuat organisasi.');
        $identity = ExternalIdentity::query()->where('issuer', $customer->issuer)->where('subject', $customer->subject)->lockForUpdate()->first();

        if ($identity !== null) {
            $principal = User::query()->findOrFail($identity->user_id);
            abort_if($customer->user_id !== null && (int) $customer->user_id !== $principal->id, 409, 'Tautan akun tidak cocok. Hubungi pengelola.');
            abort_unless($principal->account_type === User::PERSON, 409, 'Identitas ini tidak dapat dipakai untuk akun seseorang.');
        } else {
            abort_if(ExternalIdentity::onlyTrashed()->where('issuer', $customer->issuer)->where('subject', $customer->subject)->exists(), 409, 'Tautan identitas telah dicabut. Hubungi pengelola untuk menautkannya kembali.');
            abort_if($customer->user_id !== null, 409, 'Tautan identitas tidak tersedia. Hubungi pengelola.');
            $email = Str::lower($customer->verified_email);
            abort_if(User::query()->whereRaw('lower(email) = ?', [$email])->exists(), 409, 'Email ini sudah dipakai akun lain. Masuk ke akun tersebut dan tautkan SSO terlebih dahulu.');
            $principal = User::create(['name' => $customer->name, 'email' => $email, 'password' => Str::random(64)]);
            ExternalIdentity::create([
                'user_id' => $principal->id,
                'issuer' => $customer->issuer,
                'subject' => $customer->subject,
                'email_at_link' => $customer->verified_email,
            ]);
        }

        if ($customer->user_id === null) {
            $customer->forceFill(['user_id' => $principal->id])->save();
        }

        return $principal;
    }
}
