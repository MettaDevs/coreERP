<?php

namespace App\Support\Reporting;

use App\Models\Organization;
use App\Support\AddressBook\OrganizationAddressBook;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Identitas cetak organisasi: nama pada kop, baris induk, nomor registrasi, footer, dan
 * logo berposisi. Alamat dan kontak BUKAN miliknya: keduanya dibaca dari buku alamat
 * party organisasi (bagian Alamat dan Informasi kontak), sehingga alamat kantor hanya
 * pernah diubah di satu tempat. Core menyuntikkan gabungannya ke setiap dataset
 * laporan sebagai placeholder `kop.*`, dan layout mana pun menampilkan kop yang sama
 * tanpa app atau pembuat layout perlu tahu dari mana datanya.
 *
 * Ini yang membuat unggah layout menjadi jalur langka: mengganti logo, alamat, atau
 * teks footer tidak menyentuh layout.
 */
final class PrintIdentityStore
{
    public const POSITIONS = ['kiri', 'tengah', 'kanan'];

    public const MAX_LOGOS = 4;

    /** Lebar logo bawaan pada dokumen, dalam milimeter. */
    public const DEFAULT_WIDTH_MM = 25;

    public function __construct(private readonly OrganizationAddressBook $addressBook) {}

    /** @return array<string, mixed>|null */
    public function get(string $tenantId, string $organizationId): ?array
    {
        $row = DB::table('print_identities')->where(['tenant_id' => $tenantId, 'organization_id' => $organizationId])->first();

        return $row ? $this->present($row) : null;
    }

    /**
     * Identitas yang berlaku untuk satu dokumen: operating unit yang punya identitas
     * sendiri menang atas legal entity-nya. Tanpa identitas tersimpan, nama organisasi
     * tetap tersedia supaya kop tidak pernah kosong sama sekali.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(string $tenantId, ?string $legalEntityId, ?string $orgUnitId): ?array
    {
        // Tanpa legal entity pada konteks (workspace belum memilih, atau tenant baru dengan
        // satu perusahaan), satu-satunya legal entity tenant dipakai; lebih dari satu berarti
        // ambigu dan kop dibiarkan kosong daripada memilih sembarang.
        if ($legalEntityId === null && $orgUnitId === null) {
            $legalEntities = Organization::query()->where('tenant_id', $tenantId)->where('classification', 'legal_entity')->limit(2)->pluck('id');
            $legalEntityId = $legalEntities->count() === 1 ? (string) $legalEntities->first() : null;
        }
        foreach (array_filter([$orgUnitId, $legalEntityId]) as $organizationId) {
            $identity = $this->get($tenantId, $organizationId);
            if ($identity !== null) {
                return $identity;
            }
        }
        $organization = $legalEntityId
            ? Organization::query()->where('tenant_id', $tenantId)->whereKey($legalEntityId)->first(['id', 'name'])
            : null;
        if ($organization === null) {
            return null;
        }

        return $this->present((object) [
            'id' => null, 'organization_id' => $organization->id, 'display_name' => $organization->name,
            'parent_lines' => '[]', 'tax_id' => null, 'registration_id' => null, 'footer_text' => null,
            'logos' => '[]', 'updated_at' => null,
        ], $organization->name);
    }

    /**
     * Placeholder teks dan gambar untuk renderer. Gambar dikembalikan sebagai path lokal
     * sementara; pemanggil menghapusnya setelah render.
     *
     * @param  array<string, mixed>|null  $identity
     * @return array{fields: array<string, string|null>, images: array<string, array{path: string, width_mm: int}>}
     */
    public function placeholders(?array $identity): array
    {
        $fields = [];
        foreach ($this->catalog() as $field) {
            if ($field['table'] === null && ! str_starts_with($field['key'], 'kop.logo')) {
                $fields[$field['key']] = null;
            }
        }
        $images = [];
        if ($identity === null) {
            return ['fields' => $fields, 'images' => $images];
        }

        $address = array_values(array_filter($identity['address_lines'], fn (string $line): bool => trim($line) !== ''));
        $fields = [
            ...$fields,
            'kop.nama' => $identity['display_name'],
            'kop.induk' => implode("\n", $identity['parent_lines']),
            'kop.induk_1' => $identity['parent_lines'][0] ?? null,
            'kop.induk_2' => $identity['parent_lines'][1] ?? null,
            'kop.alamat' => implode("\n", $address),
            'kop.alamat_1' => $address[0] ?? null,
            'kop.alamat_2' => $address[1] ?? null,
            'kop.alamat_3' => $address[2] ?? null,
            'kop.alamat_baris' => implode(', ', $address),
            'kop.telepon' => $identity['phone'],
            'kop.whatsapp' => $identity['whatsapp'],
            'kop.fax' => $identity['fax'],
            'kop.email' => $identity['email'],
            'kop.laman' => $identity['website'],
            'kop.npwp' => $identity['tax_id'],
            'kop.nib' => $identity['registration_id'],
            'kop.footer' => $identity['footer_text'],
        ];

        $disk = $this->disk();
        $byPosition = [];
        foreach ($identity['logos'] as $index => $logo) {
            if (! $disk->exists($logo['path'])) {
                continue;
            }
            $temp = tempnam(sys_get_temp_dir(), 'logo-').'.'.pathinfo($logo['path'], PATHINFO_EXTENSION);
            file_put_contents($temp, $disk->get($logo['path']));
            $entry = ['path' => $temp, 'width_mm' => (int) $logo['width_mm']];
            $images['kop.logo_'.($index + 1)] = $entry;
            $byPosition[$logo['position']] ??= $entry;
            $images['kop.logo'] ??= $entry;
        }
        foreach (self::POSITIONS as $position) {
            if (isset($byPosition[$position])) {
                $images['kop.logo_'.$position] = $byPosition[$position];
            }
        }

        return ['fields' => $fields, 'images' => $images];
    }

    /**
     * Placeholder yang tersedia untuk semua laporan, ditampilkan di halaman Layout
     * laporan dan dipakai pemeriksa placeholder saat unggah.
     *
     * @return list<array{key: string, label: string, table: ?string}>
     */
    public function catalog(): array
    {
        $labels = [
            'kop.nama' => 'Nama organisasi pada kop',
            'kop.induk' => 'Baris induk (semua baris)',
            'kop.induk_1' => 'Baris induk pertama',
            'kop.induk_2' => 'Baris induk kedua',
            'kop.alamat' => 'Alamat (semua baris)',
            'kop.alamat_1' => 'Alamat baris 1',
            'kop.alamat_2' => 'Alamat baris 2',
            'kop.alamat_3' => 'Alamat baris 3',
            'kop.alamat_baris' => 'Alamat dalam satu baris',
            'kop.telepon' => 'Telepon',
            'kop.whatsapp' => 'WhatsApp',
            'kop.fax' => 'Faks',
            'kop.email' => 'Email',
            'kop.laman' => 'Laman web',
            'kop.npwp' => 'NPWP',
            'kop.nib' => 'NIB',
            'kop.footer' => 'Teks footer',
            'kop.logo' => 'Logo pertama (gambar)',
            'kop.logo_kiri' => 'Logo posisi kiri (gambar)',
            'kop.logo_tengah' => 'Logo posisi tengah (gambar)',
            'kop.logo_kanan' => 'Logo posisi kanan (gambar)',
        ];
        for ($i = 1; $i <= self::MAX_LOGOS; $i++) {
            $labels["kop.logo_{$i}"] = "Logo ke-{$i} (gambar)";
        }

        return array_map(fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'table' => null], array_keys($labels), $labels);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function save(Organization $organization, array $data): array
    {
        $existing = DB::table('print_identities')->where('organization_id', $organization->id)->first(['id', 'logos']);
        DB::table('print_identities')->updateOrInsert(['organization_id' => $organization->id], [
            ...($existing ? [] : ['id' => (string) Str::ulid(), 'logos' => '[]', 'created_at' => now()]),
            'tenant_id' => $organization->tenant_id,
            'display_name' => ($data['display_name'] ?? null) ?: null,
            'parent_lines' => json_encode(array_values(array_filter($data['parent_lines'] ?? [], fn ($line) => is_string($line) && trim($line) !== '')), JSON_THROW_ON_ERROR),
            'tax_id' => ($data['tax_id'] ?? null) ?: null,
            'registration_id' => ($data['registration_id'] ?? null) ?: null,
            'footer_text' => ($data['footer_text'] ?? null) ?: null,
            'updated_at' => now(),
        ]);

        return $this->get($organization->tenant_id, $organization->id);
    }

    /** @return array<string, mixed> */
    public function addLogo(Organization $organization, UploadedFile $file, string $position, int $widthMm): array
    {
        $identity = $this->get($organization->tenant_id, $organization->id) ?? $this->save($organization, ['display_name' => null]);
        if (count($identity['logos']) >= self::MAX_LOGOS) {
            throw ValidationException::withMessages(['file' => ['Paling banyak '.self::MAX_LOGOS.' logo per organisasi.']]);
        }
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $id = (string) Str::ulid();
        $path = "reporting/identities/{$organization->tenant_id}/{$organization->id}/{$id}.{$extension}";
        $this->disk()->put($path, file_get_contents($file->getRealPath()));

        $logos = [...$identity['logos'], [
            'id' => $id,
            'position' => $position,
            'path' => $path,
            'width_mm' => $widthMm,
            'original_name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
        ]];
        DB::table('print_identities')->where('organization_id', $organization->id)->update([
            'logos' => json_encode($logos, JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ]);

        return $this->get($organization->tenant_id, $organization->id);
    }

    /** @return array<string, mixed> */
    public function updateLogo(Organization $organization, string $logoId, ?string $position, ?int $widthMm): array
    {
        $identity = $this->get($organization->tenant_id, $organization->id);
        abort_if($identity === null, 404);
        $logos = array_map(function (array $logo) use ($logoId, $position, $widthMm): array {
            if ($logo['id'] !== $logoId) {
                return $logo;
            }

            return [...$logo, 'position' => $position ?? $logo['position'], 'width_mm' => $widthMm ?? $logo['width_mm']];
        }, $identity['logos']);
        DB::table('print_identities')->where('organization_id', $organization->id)->update([
            'logos' => json_encode($logos, JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ]);

        return $this->get($organization->tenant_id, $organization->id);
    }

    /** @return array<string, mixed> */
    public function removeLogo(Organization $organization, string $logoId): array
    {
        $identity = $this->get($organization->tenant_id, $organization->id);
        abort_if($identity === null, 404);
        $removed = array_values(array_filter($identity['logos'], fn (array $logo): bool => $logo['id'] === $logoId));
        abort_if($removed === [], 404);
        $logos = array_values(array_filter($identity['logos'], fn (array $logo): bool => $logo['id'] !== $logoId));
        DB::table('print_identities')->where('organization_id', $organization->id)->update([
            'logos' => json_encode($logos, JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ]);
        $this->disk()->delete($removed[0]['path']);

        return $this->get($organization->tenant_id, $organization->id);
    }

    /** @return array{path: string, mime: string}|null */
    public function logoFile(string $tenantId, string $organizationId, string $logoId): ?array
    {
        $identity = $this->get($tenantId, $organizationId);
        foreach ($identity['logos'] ?? [] as $logo) {
            if ($logo['id'] === $logoId && $this->disk()->exists($logo['path'])) {
                return ['path' => $logo['path'], 'mime' => $logo['mime'] ?? 'image/png'];
            }
        }

        return null;
    }

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('reporting.disk'));
    }

    /**
     * Baris tersimpan digabung dengan ringkasan buku alamat, sehingga pembaca (kop,
     * halaman identitas) melihat satu objek utuh tanpa tahu dua sumbernya.
     *
     * @return array<string, mixed>
     */
    private function present(object $row, ?string $fallbackName = null): array
    {
        $organizationName = $fallbackName ?? Organization::query()->whereKey($row->organization_id)->value('name');

        return [
            'organization_id' => $row->organization_id,
            'display_name' => $row->display_name ?: $organizationName,
            'display_name_custom' => $row->display_name,
            'parent_lines' => json_decode($row->parent_lines, true) ?: [],
            ...$this->addressBook->summary($row->organization_id),
            'tax_id' => $row->tax_id,
            'registration_id' => $row->registration_id,
            'footer_text' => $row->footer_text,
            'logos' => json_decode($row->logos, true) ?: [],
            'updated_at' => $row->updated_at,
        ];
    }
}
