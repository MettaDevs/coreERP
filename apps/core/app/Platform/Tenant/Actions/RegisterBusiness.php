<?php

namespace App\Platform\Tenant\Actions;

use App\Platform\Identity\Models\User;
use App\Platform\Tenant\Contracts\TenantRegistrar;
use App\Platform\Tenant\Support\ErpTenantSetup;
use App\Platform\Tenant\Support\TenantCreation;
use App\Platform\Tenant\Support\TenantCreator;
use App\Platform\Tenant\Support\TenantDetails;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RegisterBusiness
{
    public function __construct(private readonly TenantRegistrar $tenants, private readonly BootstrapErpTenant $erp) {}

    /** @param array{name:string,email:string,password?:string,password_hash?:string,business_name:string,app_ids:list<string>,must_change_password?:bool,first_environment?:'production'|'demo'|'none',first_environment_expires_at?:?string,first_environment_hosting?:'provider'|'client_server',tenant_id?:string} $data */
    public function handle(array $data): User
    {
        return $this->register($data, TenantCreator::localRegistration(...))->principal;
    }

    /** @param array{name:string,email:string,password?:string,password_hash?:string,business_name:string,app_ids:list<string>,must_change_password?:bool,first_environment?:'production'|'demo'|'none',first_environment_expires_at?:?string,first_environment_hosting?:'provider'|'client_server',tenant_id?:string} $data */
    public function forOperator(array $data, ?User $operator): TenantCreation
    {
        return $this->register($data, $operator === null
            ? TenantCreator::system(...)
            : fn (User $owner): TenantCreator => TenantCreator::operator($operator, $owner));
    }

    /**
     * @param  array{name:string,email:string,password?:string,password_hash?:string,business_name:string,app_ids:list<string>,must_change_password?:bool,first_environment?:'production'|'demo'|'none',first_environment_expires_at?:?string,first_environment_hosting?:'provider'|'client_server',tenant_id?:string}  $data
     * @param  Closure(User): TenantCreator  $creator
     */
    private function register(array $data, Closure $creator): TenantCreation
    {
        $hashedPassword = $this->hashedPassword($data);

        return DB::transaction(function () use ($data, $hashedPassword, $creator): TenantCreation {
            $user = User::create(['name' => $data['name'], 'email' => Str::lower($data['email']), 'password' => $hashedPassword]);

            if ($data['must_change_password'] ?? false) {
                $user->forceFill(['must_change_password' => true])->save();
            }

            $creation = $this->tenants->handle($creator($user), new TenantDetails(
                $data['business_name'], (string) Str::uuid(), fixedTenantId: $data['tenant_id'] ?? null,
            ));
            $this->erp->handle($creation, new ErpTenantSetup(
                $data['app_ids'], $data['first_environment'] ?? 'production',
                $data['first_environment_hosting'] ?? 'provider', $data['first_environment_expires_at'] ?? null,
            ));

            return $creation;
        });
    }

    /** @param array{password?:string,password_hash?:string} $data */
    private function hashedPassword(array $data): string
    {
        $given = $data['password_hash'] ?? null;

        if ($given === null) {
            if (! isset($data['password'])) {
                throw new InvalidArgumentException('Pendaftaran usaha membutuhkan kata sandi owner, dalam bentuk teks atau hash.');
            }

            return Hash::make($data['password']);
        }

        if (isset($data['password'])) {
            throw new InvalidArgumentException('Kata sandi owner diberikan dua kali — sebagai teks dan sebagai hash. Hanya salah satunya yang boleh dikirim.');
        }

        if (! Hash::isHashed($given)) {
            throw new InvalidArgumentException('Hash kata sandi owner tidak dikenali sebagai hash yang dipakai server ini.');
        }

        // Cast hashed menerima hash lama hanya bila biaya dan algoritmenya sesuai server ini.
        $info = password_get_info($given);
        $cost = $info['options']['cost'] ?? null;

        if (config('hashing.driver') !== 'bcrypt'
            || $info['algoName'] !== 'bcrypt'
            || ! is_int($cost)
            || $cost > (int) config('hashing.bcrypt.rounds', 12)) {
            throw new InvalidArgumentException(sprintf(
                'Hash kata sandi owner tidak sesuai setelan hash server ini: harus bcrypt, dengan biaya paling '
                .'tinggi BCRYPT_ROUNDS=%s (driver hash server: %s). Server ini menolak menyimpannya; samakan '
                .'setelan hash di admin.erp dan di server ini.',
                (string) config('hashing.bcrypt.rounds'),
                (string) config('hashing.driver'),
            ));
        }

        return $given;
    }
}
