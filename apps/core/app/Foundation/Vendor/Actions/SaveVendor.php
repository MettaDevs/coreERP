<?php

declare(strict_types=1);

namespace App\Foundation\Vendor\Actions;

use App\Foundation\NumberSequence\Actions\NumberSequenceService;
use App\Foundation\NumberSequence\Support\CoreNumberSequences;
use App\Foundation\Vendor\Models\Vendor;
use App\Platform\Access\Support\CoreSecurityCatalog;
use App\Platform\AddressBook\Models\Party;
use App\Platform\AddressBook\Models\PartyRoleRegistration;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Organization\Models\Organization;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membuat dan mengubah akun vendor (K-06, TODO 2.2–2.4).
 *
 * Pembuatan menulis tiga hal dalam satu transaksi: party (bila baru), akun vendor bernomor, dan
 * pendaftaran peran `vendor` di buku alamat. Nomor diterbitkan di dalam transaksi yang sama, jadi
 * vendor yang gagal disimpan tidak meninggalkan lompatan nomor.
 */
final class SaveVendor
{
    public function __construct(
        private readonly NumberSequenceService $number,
        private readonly CoreNumberSequences $sequence,
    ) {}

    /**
     * @param  array{legal_entity_id: string, party_id: ?string, party_type: ?string, party_name: ?string, number: ?string, tax_number: ?string, status: string}  $data
     */
    public function create(TenantMembership $actor, array $data, string $creationKey): Vendor
    {
        $this->ensureAdmin($actor);
        $tenant = $actor->tenant_id;

        $retry = Vendor::query()->where('tenant_id', $tenant)->where('creation_key', $creationKey)->first();
        if ($retry !== null) {
            return $retry;
        }

        $legalEntity = Organization::query()
            ->where('tenant_id', $tenant)
            ->where('classification', 'legal_entity')
            ->find($data['legal_entity_id']);
        if ($legalEntity === null) {
            throw ValidationException::withMessages(['legal_entity_id' => 'Pilih entitas legal milik tenant ini.']);
        }

        // Di luar transaksi: bentrokan dua vendor pertama yang bersamaan tidak boleh membatalkan
        // transaksi penyimpanan di bawah.
        $this->sequence->ensure($tenant, Vendor::NUMBER_SEQUENCE);

        try {
            return DB::transaction(function () use ($actor, $tenant, $data, $creationKey, $legalEntity): Vendor {
                $party = $this->party($tenant, $data);

                $alreadyExists = Vendor::query()
                    ->where('tenant_id', $tenant)
                    ->where('legal_entity_id', $legalEntity->id)
                    ->where('party_id', $party->id)
                    ->first();
                if ($alreadyExists !== null) {
                    throw ValidationException::withMessages([
                        'party_id' => sprintf('%s sudah menjadi vendor %s di entitas legal ini.', $party->name, $alreadyExists->number),
                    ]);
                }

                try {
                    $publishedAt = $this->number->issue(
                        ['tenant_id' => $tenant, 'app_id' => CoreNumberSequences::APP_ID, 'legal_entity_id' => $legalEntity->id],
                        Vendor::NUMBER_SEQUENCE,
                        'vendor:'.$creationKey,
                        $data['number'],
                    );
                } catch (ValidationException $failure) {
                    // Nama field milik layanan nomor tidak boleh bocor ke form vendor.
                    throw ValidationException::withMessages([
                        'number' => collect($failure->errors())->flatten()->first() ?? 'Nomor vendor belum dapat diterbitkan.',
                    ]);
                }

                $vendor = Vendor::query()->create([
                    'tenant_id' => $tenant,
                    'legal_entity_id' => $legalEntity->id,
                    'party_id' => $party->id,
                    'number' => $publishedAt['number'],
                    'tax_number' => $data['tax_number'],
                    'status' => $data['status'],
                    'creation_key' => $creationKey,
                    'created_by_user_id' => (string) $actor->user_id,
                ]);

                PartyRoleRegistration::query()->firstOrCreate(
                    [
                        'tenant_id' => $tenant,
                        'party_id' => $party->id,
                        'role_code' => 'vendor',
                        'legal_entity_id' => $legalEntity->id,
                    ],
                    ['owning_app_id' => CoreNumberSequences::APP_ID],
                );

                return $vendor;
            });
        } catch (UniqueConstraintViolationException $conflict) {
            if (str_contains($conflict->getMessage(), 'creation_key')) {
                return Vendor::query()->where('tenant_id', $tenant)->where('creation_key', $creationKey)->firstOrFail();
            }

            throw ValidationException::withMessages([
                'party_id' => 'Vendor yang sama baru saja dibuat dari permintaan lain. Muat ulang daftar.',
            ]);
        }
    }

    /**
     * Nomor dan entitas legal tidak dapat diubah: nomor sudah tertanam di tabel penerjemah pembaca,
     * dan entitas legal menentukan buku mana yang memegang hutangnya. Nama milik party, jadi
     * mengubahnya di sini mengubah nama party di seluruh buku alamat — sama dengan Dynamics 365.
     *
     * Yang diklaim versinya akun vendor, record yang dibuka pengguna ({@see RowVersion}); party ikut
     * terkunci karena ditulis di transaksi yang sama.
     *
     * @param  array{name: string, tax_number: ?string, status: string}  $data
     */
    public function update(TenantMembership $actor, Vendor $vendor, array $data, int $expectedVersion): Vendor
    {
        $this->ensureAdmin($actor);
        if ($vendor->tenant_id !== $actor->tenant_id) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($vendor, $data, $expectedVersion): Vendor {
            RowVersion::claim($vendor, $expectedVersion);
            $vendor->fill(['tax_number' => $data['tax_number'], 'status' => $data['status']])->save();
            $vendor->party->fill(['name' => $data['name'], 'search_name' => Party::searchName($data['name'])])->save();
            // Nama berubah di party, tetapi pembaca yang menyinkronkan vendor menyaring lewat
            // `updated_at` vendor. Disentuh supaya perubahan nama ikut terbawa tarikan berikutnya.
            $vendor->touch();

            return $vendor->refresh();
        });
    }

    /** @param array{party_id: ?string, party_type: ?string, party_name: ?string} $data */
    private function party(string $tenant, array $data): Party
    {
        if ($data['party_id'] !== null) {
            $party = Party::query()->where('tenant_id', $tenant)->find($data['party_id']);
            if ($party === null) {
                throw ValidationException::withMessages(['party_id' => 'Pihak yang dipilih tidak ditemukan di buku alamat tenant ini.']);
            }

            return $party;
        }

        $name = trim((string) $data['party_name']);

        return Party::query()->create([
            'tenant_id' => $tenant,
            'type' => $data['party_type'] ?? 'organization',
            'name' => $name,
            'search_name' => Party::searchName($name),
            'status' => 'active',
        ]);
    }

    private function ensureAdmin(TenantMembership $actor): void
    {
        if (! $actor->hasCorePermission(CoreSecurityCatalog::VENDOR_UPDATE)) {
            throw new AuthorizationException;
        }
    }
}
