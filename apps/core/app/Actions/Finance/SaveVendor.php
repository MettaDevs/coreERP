<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Actions\NumberSequence\NumberSequenceService;
use App\Models\Organization;
use App\Models\Party;
use App\Models\PartyRoleRegistration;
use App\Models\TenantMembership;
use App\Models\Vendor;
use App\Support\Finance\CoreNumberSequences;
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
        private readonly NumberSequenceService $nomor,
        private readonly CoreNumberSequences $urutan,
    ) {}

    /**
     * @param  array{legal_entity_id: string, party_id: ?string, party_type: ?string, party_name: ?string, number: ?string, tax_number: ?string, status: string}  $data
     */
    public function create(TenantMembership $actor, array $data, string $creationKey): Vendor
    {
        $this->pastikanAdmin($actor);
        $tenant = $actor->tenant_id;

        $ulang = Vendor::query()->where('tenant_id', $tenant)->where('creation_key', $creationKey)->first();
        if ($ulang !== null) {
            return $ulang;
        }

        $entitas = Organization::query()
            ->where('tenant_id', $tenant)
            ->where('classification', 'legal_entity')
            ->find($data['legal_entity_id']);
        if ($entitas === null) {
            throw ValidationException::withMessages(['legal_entity_id' => 'Pilih entitas legal milik tenant ini.']);
        }

        // Di luar transaksi: bentrokan dua vendor pertama yang bersamaan tidak boleh membatalkan
        // transaksi penyimpanan di bawah.
        $this->urutan->ensure($tenant, Vendor::NUMBER_SEQUENCE);

        try {
            return DB::transaction(function () use ($actor, $tenant, $data, $creationKey, $entitas): Vendor {
                $party = $this->party($tenant, $data);

                $sudahAda = Vendor::query()
                    ->where('tenant_id', $tenant)
                    ->where('legal_entity_id', $entitas->id)
                    ->where('party_id', $party->id)
                    ->first();
                if ($sudahAda !== null) {
                    throw ValidationException::withMessages([
                        'party_id' => sprintf('%s sudah menjadi vendor %s di entitas legal ini.', $party->name, $sudahAda->number),
                    ]);
                }

                try {
                    $terbit = $this->nomor->issue(
                        ['tenant_id' => $tenant, 'app_id' => CoreNumberSequences::APP_ID, 'legal_entity_id' => $entitas->id],
                        Vendor::NUMBER_SEQUENCE,
                        'vendor:'.$creationKey,
                        $data['number'],
                    );
                } catch (ValidationException $kegagalan) {
                    // Nama field milik layanan nomor tidak boleh bocor ke form vendor.
                    throw ValidationException::withMessages([
                        'number' => collect($kegagalan->errors())->flatten()->first() ?? 'Nomor vendor belum dapat diterbitkan.',
                    ]);
                }

                $vendor = Vendor::query()->create([
                    'tenant_id' => $tenant,
                    'legal_entity_id' => $entitas->id,
                    'party_id' => $party->id,
                    'number' => $terbit['number'],
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
                        'legal_entity_id' => $entitas->id,
                    ],
                    ['owning_app_id' => CoreNumberSequences::APP_ID],
                );

                return $vendor;
            });
        } catch (UniqueConstraintViolationException $bentrok) {
            if (str_contains($bentrok->getMessage(), 'creation_key')) {
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
     * @param  array{name: string, tax_number: ?string, status: string}  $data
     */
    public function update(TenantMembership $actor, Vendor $vendor, array $data): Vendor
    {
        $this->pastikanAdmin($actor);
        if ($vendor->tenant_id !== $actor->tenant_id) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($vendor, $data): Vendor {
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

        $nama = trim((string) $data['party_name']);

        return Party::query()->create([
            'tenant_id' => $tenant,
            'type' => $data['party_type'] ?? 'organization',
            'name' => $nama,
            'search_name' => Party::searchName($nama),
            'status' => 'active',
        ]);
    }

    private function pastikanAdmin(TenantMembership $actor): void
    {
        if (! $actor->canManageAccess()) {
            throw new AuthorizationException;
        }
    }
}
