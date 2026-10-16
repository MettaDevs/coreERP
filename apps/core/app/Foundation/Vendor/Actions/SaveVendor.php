<?php

declare(strict_types=1);

namespace App\Foundation\Vendor\Actions;

use App\Foundation\NumberSequence\Actions\NumberSequenceService;
use App\Foundation\NumberSequence\Support\CoreNumberSequences;
use App\Foundation\Vendor\Models\Vendor;
use App\Platform\Access\Support\CoreSecurityCatalog;
use App\Platform\AddressBook\Actions\SaveParty;
use App\Platform\AddressBook\Events\PartyNameChanged;
use App\Platform\AddressBook\Events\PartyTaxNumberChanged;
use App\Platform\AddressBook\Models\Party;
use App\Platform\AddressBook\Models\PartyRoleRegistration;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Organization\Models\Organization;
use App\Platform\Organization\Models\OrganizationParty;
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
     * @param  array{legal_entity_id: string, party_id: ?string, party_type: ?string, party_name: ?string, number: ?string, tax_number: ?string, status: string, payment_priority?: ?int}  $data
     */
    public function create(TenantMembership $actor, array $data, string $creationKey): Vendor
    {
        $this->ensureAuthorized($actor);
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
        if ($data['party_id'] === null) {
            $this->sequence->ensure($tenant, 'core.party');
        }

        try {
            return DB::transaction(function () use ($actor, $tenant, $data, $creationKey, $legalEntity): Vendor {
                $party = $this->party($tenant, $data, $creationKey);
                if ($data['tax_number'] !== null) {
                    if ($party->tax_number !== null && preg_replace('/\D/', '', $party->tax_number) !== preg_replace('/\D/', '', $data['tax_number'])) {
                        throw ValidationException::withMessages(['tax_number' => 'NPWP berbeda dari pihak yang dipilih. Periksa identitasnya di buku alamat.']);
                    }
                    if ($party->tax_number === null) {
                        $party->forceFill(['tax_number' => $data['tax_number']])->save();
                        event(new PartyTaxNumberChanged($tenant, $party->id, $party->tax_number));
                    }
                }

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
                    'tax_number' => $party->tax_number,
                    'payment_priority' => $data['payment_priority'] ?? null,
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
            if (str_contains($conflict->getMessage(), 'vendors_tenant_number_unique')) {
                throw ValidationException::withMessages([
                    'number' => 'Nomor vendor sudah dipakai. Gunakan nomor lain, termasuk bila perusahaannya berbeda.',
                ]);
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
     * @param  array{name: string, tax_number: ?string, status: string, payment_priority?: ?int}  $data
     */
    public function update(TenantMembership $actor, Vendor $vendor, array $data, int $expectedVersion): Vendor
    {
        $this->ensureAuthorized($actor);
        if ($vendor->tenant_id !== $actor->tenant_id) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($vendor, $data, $expectedVersion): Vendor {
            $party = $vendor->party()->lockForUpdate()->firstOrFail();
            // Direktori juga menulis akun vendor sesudah party; urutan kunci harus sama.
            RowVersion::claim($vendor, $expectedVersion);
            if ($party->name !== $data['name'] && OrganizationParty::query()->where('tenant_id', $vendor->tenant_id)->where('party_id', $party->id)->exists()) {
                throw ValidationException::withMessages(['name' => 'Ubah nama organisasi melalui halaman Organisasi agar identitasnya tetap sama.']);
            }
            $vendor->fill(['tax_number' => $data['tax_number'], 'status' => $data['status'],
                'payment_priority' => array_key_exists('payment_priority', $data) ? $data['payment_priority'] : $vendor->payment_priority])->save();
            $searchName = $party->search_name === Party::searchName($party->name) ? Party::searchName($data['name']) : $party->search_name;
            $party->forceFill(['name' => $data['name'], 'search_name' => $searchName, 'tax_number' => $data['tax_number']])->save();
            if ($party->wasChanged('name')) {
                event(new PartyNameChanged($party->tenant_id, $party->id));
            }
            if ($party->wasChanged('tax_number')) {
                event(new PartyTaxNumberChanged($party->tenant_id, $party->id, $party->tax_number));
            }
            // Nama berubah di party, tetapi pembaca yang menyinkronkan vendor menyaring lewat
            // `updated_at` vendor. Disentuh supaya perubahan nama ikut terbawa tarikan berikutnya.
            $vendor->touch();

            return $vendor->refresh();
        });
    }

    /** @param array{party_id: ?string, party_type: ?string, party_name: ?string} $data */
    private function party(string $tenant, array $data, string $creationKey): Party
    {
        if ($data['party_id'] !== null) {
            $party = Party::query()->where('tenant_id', $tenant)->lockForUpdate()->find($data['party_id']);
            if ($party === null) {
                throw ValidationException::withMessages(['party_id' => 'Pihak yang dipilih tidak ditemukan di buku alamat tenant ini.']);
            }
            if ($party->status !== 'active') {
                throw ValidationException::withMessages(['party_id' => 'Pihak yang dipilih nonaktif. Aktifkan di buku alamat sebelum membuat vendor baru.']);
            }

            return $party;
        }

        $name = trim((string) $data['party_name']);

        return app(SaveParty::class)->create($tenant, [
            'type' => $data['party_type'] ?? 'organization',
            'name' => $name,
            'search_name' => Party::searchName($name),
            'status' => 'active',
        ], 'vendor:'.$creationKey);
    }

    private function ensureAuthorized(TenantMembership $actor): void
    {
        if (! $actor->hasCorePermission(CoreSecurityCatalog::VENDOR_UPDATE)) {
            throw new AuthorizationException;
        }
    }
}
