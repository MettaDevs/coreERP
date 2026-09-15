<?php

declare(strict_types=1);

namespace ControlPlane\Customers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Melahirkan satu pelanggan baru — dan tidak satu baris pun ditulisnya sendiri.
 *
 * Aksi ini memanggil Core lewat HTTP, lalu meneruskan jawabannya. Alasannya bukan kerapian
 * lapisan: yang tahu cara menjalankan migration module, membaca registry module, dan menyemai data
 * awal hanyalah Core. Menyalin `RegisterBusiness` ke konsol ini berarti dua salinan alur pembuatan
 * tenant, dan yang menyimpang di antara keduanya adalah rantai izin — tempat paling mahal untuk
 * menyimpan perbedaan yang tidak disengaja.
 *
 * Karena itu seluruh isi kelas ini adalah penanganan kegagalan. Jalur berhasilnya empat baris;
 * sisanya memastikan kegagalan sampai ke layar sebagai kalimat yang menyebut apa yang harus
 * diperbaiki, bukan sebagai halaman 500 yang menelan sebabnya.
 */
final class CreateCustomer
{
    /**
     * @param  list<string>  $apps  Id app yang dibeli. Ketersediaannya diputuskan Core, bukan di sini.
     * @param  'production'|'demo'|'none'  $firstEnvironment  Jenis tempat kerja pertamanya.
     * @param  ?string  $firstEnvironmentExpiresAt  Wajib untuk demo, diabaikan selainnya.
     * @param  'provider'|'client_server'  $firstEnvironmentHosting  Tempat produksinya berjalan; selain produksi diabaikan.
     * @return array{tenant_id: string, environment_id: ?string, email: string, temporary_password: string}
     *
     * @throws CustomerRejected Core menjawab, dan jawabannya "tidak".
     * @throws CoreUnreachable Permintaannya tidak sampai pada jawaban yang dapat dipakai.
     */
    public function __invoke(
        string $legalName,
        string $adminName,
        string $adminEmail,
        array $apps,
        string $firstEnvironment = 'production',
        ?string $firstEnvironmentExpiresAt = null,
        string $firstEnvironmentHosting = 'provider',
    ): array {
        $endpoint = $this->endpoint();

        try {
            $response = Http::withToken((string) config('core.token'))
                ->acceptJson()
                ->timeout(max(1, (int) config('core.timeout')))
                ->post($endpoint, [
                    'legal_name' => $legalName,
                    'admin_name' => $adminName,
                    'admin_email' => $adminEmail,
                    'app_ids' => $apps,
                    'first_environment' => $firstEnvironment,
                    // Dibuang ketika kosong, bukan dikirim null. Aturan Core memakai
                    // `exclude_unless`, dan kunci yang hadir bernilai null tetap dianggap hadir.
                    ...($firstEnvironmentExpiresAt === null
                        ? []
                        : ['first_environment_expires_at' => $firstEnvironmentExpiresAt]),
                    // Hanya dikirim untuk produksi di server klien. Tanpa field ini Core melahirkan
                    // lingkungan di server kita, persis seperti sebelum pilihan ini ada — jadi
                    // permintaan untuk jalur lama tetap byte yang sama dengan kemarin.
                    ...($firstEnvironment === 'production' && $firstEnvironmentHosting === 'client_server'
                        ? ['first_environment_hosting' => 'client_server']
                        : []),
                ]);
        } catch (ConnectionException $disconnected) {
            // Sengaja tidak ditelan dan tidak dipercantik. Sebab yang paling sering adalah
            // `COREERP_URL` salah setel, dan satu-satunya cara operator dapat melihatnya adalah
            // kalau alamat yang dicoba ikut tertulis.
            throw new CoreUnreachable(
                'Core tidak menjawab di '.$endpoint.'. Periksa COREERP_URL di konsol ini dan pastikan '
                .'runtime Core memang hidup di alamat itu. Pesan aslinya: '.$disconnected->getMessage(),
                previous: $disconnected,
            );
        }

        if ($response->failed()) {
            throw $this->rejectionReason($response, $endpoint);
        }

        return $this->readResponse($response, $endpoint);
    }

    private function endpoint(): string
    {
        return rtrim((string) config('core.base_url'), '/').'/api/internal/v1/tenants';
    }

    /**
     * Menerjemahkan penolakan Core menjadi kalimat yang menyebut apa yang harus diperbaiki.
     *
     * Dua status dijawab dengan kalimat kita sendiri, bukan dengan kalimat Core. 401 dan 403 di
     * jalur ini hampir selalu berarti satu hal — kuncinya tidak cocok — sementara jawaban bawaan
     * Laravel untuk keduanya berbunyi "Unauthenticated.", yang tidak memberi tahu siapa pun bahwa
     * yang harus disunting adalah `CONTROL_PLANE_TOKEN` di berkas env konsol ini.
     */
    private function rejectionReason(Response $response, string $endpoint): CustomerRejected
    {
        $status = $response->status();

        if ($status === 401 || $status === 403) {
            return new CustomerRejected(
                'Core menolak kunci konsol ini (HTTP '.$status.' dari '.$endpoint.'). Nilai '
                .'CONTROL_PLANE_TOKEN di sini harus sama persis dengan yang diperiksa Core.',
            );
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];

        $fieldErrors = $this->fieldErrors($payload['errors'] ?? null);
        $message = isset($payload['message']) && is_string($payload['message']) && $payload['message'] !== ''
            ? $payload['message']
            : '';

        if ($message === '' && $fieldErrors === []) {
            // Penolakan tanpa sebab tetap harus terbaca. Statusnya disebut karena itulah
            // satu-satunya keterangan yang benar-benar ada.
            $message = 'Core menolak permintaannya dengan HTTP '.$status.' dari '.$endpoint
                .', tanpa menyebut alasan.';
        }

        return new CustomerRejected($message, $fieldErrors);
    }

    /**
     * Membersihkan peta galat per isian, dan membuang apa pun yang bentuknya tidak dikenali.
     *
     * Isinya datang dari aplikasi lain lewat jaringan, jadi ia diperlakukan sebagai bahan mentah:
     * kunci yang bukan teks dan pesan yang bukan teks dilewati, bukan dipaksa. Satu bentuk tak
     * terduga tidak boleh menjatuhkan seluruh penerjemahan galat — kalau ia jatuh, yang hilang
     * justru alasan penolakannya.
     *
     * @return array<string, list<string>>
     */
    private function fieldErrors(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $clean = [];

        foreach ($raw as $field => $message) {
            if (! is_string($field)) {
                continue;
            }

            $rows = [];

            foreach (is_array($message) ? $message : [$message] as $item) {
                if (is_string($item) && $item !== '') {
                    $rows[] = $item;
                }
            }

            if ($rows !== []) {
                $clean[$field] = $rows;
            }
        }

        return $clean;
    }

    /**
     * @return array{tenant_id: string, environment_id: ?string, email: string, temporary_password: string}
     */
    private function readResponse(Response $response, string $endpoint): array
    {
        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];

        $tenant = $this->text($payload, 'tenant_id');
        $email = $this->text($payload, 'email');
        $password = $this->text($payload, 'temporary_password');

        if ($tenant === null || $email === null || $password === null) {
            /*
             * Jawaban 2xx yang tidak memuat kata sandi adalah keadaan paling berbahaya di seluruh
             * alur ini: pelanggannya mungkin **sudah lahir** di sisi Core, dan satu-satunya salinan
             * kata sandinya baru saja hilang. Karena itu pesannya tidak boleh berbunyi seperti
             * kegagalan biasa — ia harus menyuruh orangnya memeriksa daftar sebelum mencoba lagi,
             * supaya percobaan kedua tidak melahirkan pelanggan kembar.
             */
            throw new CoreUnreachable(
                'Core menjawab '.$response->status().' dari '.$endpoint.', tetapi jawabannya tidak '
                .'memuat kata sandi sementara. Tenant-nya mungkin sudah terlanjur dibuat: '
                .'periksa daftar tenant sebelum mencoba lagi, dan pastikan COREERP_URL benar-'
                .'benar menunjuk runtime Core.',
            );
        }

        return [
            'tenant_id' => $tenant,
            // Boleh kosong, dan itu bukan data hilang: layar ini tidak membutuhkannya sama sekali.
            // Menggagalkan seluruh pembuatan karena satu id yang tidak dipakai berarti membuang
            // kata sandi yang justru menjadi alasan layar ini ada.
            'environment_id' => $this->text($payload, 'environment_id'),
            'email' => $email,
            'temporary_password' => $password,
        ];
    }

    /** @param array<mixed> $payload */
    private function text(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
