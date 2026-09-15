<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\CoreApp;
use App\Models\Environment;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\ControlPlane\TemporaryPassword;
use App\Support\Modules\ModuleManifest;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Throwable;

/**
 * Melahirkan satu-satunya tenant sebuah server on-prem, dengan id yang sudah dicatat admin.erp.
 *
 * Catatan komersial tenant — klien, edisi, masa sewa — tinggal di admin.erp; datanya tinggal di
 * server pelanggan. Keduanya harus menyebut **id yang sama**, supaya laporan, tiket dukungan, dan
 * SSO menunjuk tenant yang sama tanpa tabel penerjemah. Sampai perintah ini ada, tenant on-prem
 * lahir lewat pendaftaran usaha di server itu sendiri dengan id baru, dan tidak ada yang dapat
 * menyambungkannya kembali.
 *
 * Yang dilahirkan dikerjakan {@see RegisterBusiness}, bukan salinannya: dua salinan alur pembuatan
 * tenant adalah dua tempat yang akan menyimpang, dan yang menyimpang di sana rantai izin. Perintah
 * ini hanya menyiapkan masukannya — id, daftar modul, kata sandi owner — lalu membacakan hasilnya.
 *
 * ## Dua pemanggil, dan dua asal kata sandi
 *
 * **Operasi `install` agen** — jalur yang dituju. Agen menjalankannya di dalam container Core sesudah
 * image rilis ditarik dan menyala, dengan `--admin-password-hash-stdin` dan satu `--app` per app yang
 * dibeli.
 * Kata sandi sementara owner dibuat admin.erp dan ditunjukkan sekali kepada operator di sebelah
 * perintah pasangnya; yang menempuh jalan ke server ini hanya hash bcrypt-nya, lewat stdin — bukan
 * lewat argumen, yang terbaca siapa pun dari daftar proses dan tersimpan di log agen. Perintah ini
 * tidak pernah mencetak kata sandi di jalur itu, karena ia memang tidak pernah mengetahuinya.
 *
 * **Pemasangan tangan yang lama** — tanpa `--admin-password-hash-stdin`, kata sandinya dibuat di sini
 * dan dicetak sekali. Jalur ini sengaja **dipertahankan**: subperintah `bootstrap-tenant` agen dan
 * skrip pasang yang sudah beredar di server klien memanggilnya begitu, dan penghapusannya sedang
 * berjalan di cabang lain. Membuangnya dari Core lebih dulu berarti `main` di antara dua penggabungan
 * memuat skrip pasang yang memanggil opsi yang sudah tidak ada. Ia dibuang ketika pemanggil
 * terakhirnya dibuang.
 *
 * ## App yang diberikan
 *
 * Dengan `--app`, persis yang disebut — dan setiap app wajib ada sebagai modul di image ini. Image
 * on-prem dikelola membawa seluruh modul, jadi app yang tidak ada berarti image dan admin.erp tidak
 * sepakat tentang katalognya; memberikannya melahirkan produk yang sudah dibayar dan tidak dapat
 * dibuka. Yang dibeli dan yang boleh dibuka dijaga dua lapis: entitlement yang ditulis di sini, dan
 * lisensi bertanda tangan (`docs/todo/lisensi-mengunci`). Pada jalur operasi `install`
 * (`--admin-password-hash-stdin`), tanpa `--app` berarti **tidak ada app** — tenant yang hanya membeli
 * Core. Pada jalur tangan yang lama, tanpa `--app` berarti seluruh modul di image ini, seperti sebelum
 * opsi itu ada.
 *
 * ## Aman diulang, tetapi hanya untuk owner yang sama
 *
 * Operasi pasang dapat diulang agen, dan skrip pasang dapat dijalankan ulang oleh orang yang tidak
 * tahu apakah langkah ini sudah pernah berhasil. Tenant yang sudah ada dengan id itu **dan owner
 * dengan email yang sama** dijawab "sudah ada" dengan kode keluar nol, tanpa menyentuh apa pun —
 * termasuk tanpa kata sandi baru, karena kata sandi baru berarti admin yang sudah bekerja mendadak
 * tidak dapat masuk.
 *
 * Owner yang berbeda ditolak. Id yang sama dengan owner lain berarti dua catatan di admin.erp yang
 * saling tertukar, atau server yang dipakai ulang untuk tenant lain; menjawabnya "sudah ada" membuat
 * admin.erp menandai pemasangan selesai dan menunjukkan kata sandi yang tidak pernah berlaku di sini.
 */
final class BootstrapSiteTenant extends Command
{
    /**
     * Bentuk hash yang diterima: bcrypt `$2y$`, biaya dua digit, lalu 53 karakter garam dan hash.
     *
     * Hanya `$2y$`. Itu satu-satunya awalan yang dihasilkan `password_hash()` PHP dan satu-satunya yang
     * dikenali `Hash::isHashed()` sebagai bcrypt — `$2a$` dan `$2b$` tidak dikenal, sehingga cast
     * `hashed` milik `User` akan meng-hash-nya ulang sebagai teks polos, dan owner tidak pernah dapat
     * masuk dengan kata sandi yang ditunjukkan admin.erp.
     */
    private const BCRYPT = '/^\$2y\$(0[4-9]|[12][0-9]|3[01])\$[.\/A-Za-z0-9]{53}$/';

    /** Batas baca stdin. Hash bcrypt 60 byte; yang jauh lebih panjang pasti bukan hash. */
    private const STDIN_LIMIT = 1024;

    protected $signature = 'tenant:bootstrap-site
        {--tenant-id= : ULID tenant yang sudah tercatat di admin.erp}
        {--name= : Nama usaha pelanggan}
        {--admin-name= : Nama admin pertama}
        {--admin-email= : Email admin pertama}
        {--admin-password-hash-stdin : Baca hash bcrypt kata sandi sementara admin dari stdin; tanpa ini kata sandinya dibuat di sini dan dicetak}
        {--app=* : App yang dibeli tenant, satu per opsi; tanpa ini seluruh modul di image ini}';

    protected $description = 'Lahirkan tenant server on-prem dengan id yang sudah dicatat admin.erp, beserta admin pertamanya';

    public function handle(RegisterBusiness $registerBusiness, ModuleRegistry $registry): int
    {
        $input = [
            'tenant_id' => $this->stringOption('tenant-id'),
            'name' => $this->stringOption('name'),
            'admin_name' => $this->stringOption('admin-name'),
            'admin_email' => $this->stringOption('admin-email'),
            'apps' => $this->requestedApps(),
        ];

        $validator = Validator::make($input, [
            'tenant_id' => ['required', 'ulid'],
            'name' => ['required', 'string', 'max:255'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
            'apps' => ['array'],
            'apps.*' => ['required', 'string', 'max:80', 'distinct'],
        ], [
            'required' => 'Opsi :attribute wajib diisi.',
            'ulid' => 'Opsi :attribute harus berupa ULID 26 karakter, persis seperti yang tercatat di admin.erp.',
            'email' => 'Opsi :attribute harus berupa alamat email.',
            'max' => 'Opsi :attribute paling panjang :max karakter.',
            'distinct' => 'Opsi --app menyebut app yang sama lebih dari sekali.',
        ], [
            'tenant_id' => '--tenant-id',
            'name' => '--name',
            'admin_name' => '--admin-name',
            'admin_email' => '--admin-email',
            'apps.*' => '--app',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        // Dibaca sebelum apa pun diputuskan, termasuk sebelum jawaban "sudah ada". Agen selalu
        // mengalirkan hash-nya; perintah yang keluar tanpa membaca stdin meninggalkan penulis di
        // ujung pipa yang lain menulis ke pipa yang sudah ditutup.
        $passwordHash = null;

        if ($this->input->getOption('admin-password-hash-stdin') === true) {
            $passwordHash = $this->readPasswordHash();

            if ($passwordHash === null) {
                return self::FAILURE;
            }
        }

        // Huruf kecil karena begitulah `HasUlids` menulis setiap id — di Core maupun di admin.erp.
        // ULID sendiri tidak peka huruf besar, tetapi kolom PostgreSQL peka: `01J…` dan `01j…` akan
        // menjadi dua tenant yang berbeda bagi setiap query yang mencocokkan keduanya.
        $tenantId = Str::lower($input['tenant_id']);
        $email = Str::lower(trim($input['admin_email']));

        $existing = Tenant::query()->find($tenantId);

        if ($existing instanceof Tenant) {
            return $this->answerExisting($existing, $email);
        }

        // Server on-prem melayani satu pelanggan. Tenant kedua di sini berarti id yang tercatat di
        // admin.erp hanya menunjuk sebagian isi server — sambungan yang justru hendak dibuat perintah
        // ini. Ditolak, bukan ditambahkan di sebelahnya.
        $otherTenant = Tenant::query()->first();

        if ($otherTenant instanceof Tenant) {
            $this->error(sprintf(
                'Server ini sudah memiliki tenant lain: %s (%s). Server on-prem hanya melayani satu tenant; periksa id yang diberikan.',
                $otherTenant->id,
                $otherTenant->name,
            ));

            return self::FAILURE;
        }

        // Aturan yang sama dengan kedua pintu lain `RegisterBusiness`: email yang sudah memiliki akun
        // ditolak, tidak digabungkan. Pendaftaran selalu membuat akun baru, dan menggabungkan diam-diam
        // berarti menyerahkan kepemilikan tenant kepada akun yang kata sandinya tidak dibuat di sini.
        if (User::query()->where('email', $email)->exists()) {
            $this->error(sprintf(
                'Email %s sudah memiliki akun di server ini. Pendaftaran usaha tidak memakai akun yang sudah ada; berikan email lain untuk admin pertama.',
                $email,
            ));

            return self::FAILURE;
        }

        $appIds = $this->appsToEntitle($registry, $input['apps'], $this->input->getOption('admin-password-hash-stdin') === true);

        if ($appIds === null) {
            return self::FAILURE;
        }

        $notInCatalog = array_values(array_diff(
            $appIds,
            CoreApp::query()->whereIn('id', $appIds)->where('status', 'available')->pluck('id')->all(),
        ));

        // Ditolak sebelum satu baris pun ditulis. `RegisterBusiness` akan menolaknya juga, tetapi dari
        // dalam transaksi dan dengan pesan yang tidak menyebut langkah yang terlewat.
        if ($notInCatalog !== []) {
            $this->error(sprintf(
                'Modul berikut ada di image ini tetapi belum terdaftar di katalog: %s. Jalankan `php artisan app:register-manifest` lebih dulu.',
                implode(', ', $notInCatalog),
            ));

            return self::FAILURE;
        }

        $data = [
            'name' => $input['admin_name'],
            'email' => $email,
            'business_name' => $input['name'],
            'app_ids' => $appIds,
            'must_change_password' => true,
            'first_environment' => 'production',
            'tenant_id' => $tenantId,
        ];
        $temporaryPassword = null;

        if ($passwordHash === null) {
            $temporaryPassword = TemporaryPassword::generate();
            $data['password'] = $temporaryPassword;
        } else {
            $data['password_hash'] = $passwordHash;
        }

        try {
            $registerBusiness->handle($data);
        } catch (Throwable $exception) {
            if (! Tenant::query()->whereKey($tenantId)->exists()) {
                // Penolakan atas masukannya — hash yang biayanya melebihi setelan server ini — datang
                // sebelum satu baris pun ditulis, dan kalimatnya sudah seluruh ceritanya.
                if ($exception instanceof InvalidArgumentException) {
                    $this->error($exception->getMessage());

                    return self::FAILURE;
                }

                // Tidak ada yang tertulis: exception itu sendiri sudah seluruh ceritanya.
                throw $exception;
            }

            /*
             * Tenant sudah tertulis, dan yang gagal langkah sesudah commit — pemasangan modul beserta
             * penyiapan urutan nomornya.
             *
             * Kata sandi yang dibuat di sini tetap dibacakan. Tanpanya admin pertama tidak pernah dapat
             * masuk: kata sandi itu hanya ada di memori proses ini, dan menjalankan ulang perintah ini
             * menjawab "sudah ada" tanpa membuat yang baru. Kata sandi dari admin.erp tidak perlu
             * dibacakan — operatornya sudah memegangnya.
             */
            $this->printOutcome($tenantId, $input['name'], $email, $appIds, $temporaryPassword);
            $this->error(sprintf('Tenant sudah lahir, tetapi penyiapan modulnya tidak selesai: %s', $exception->getMessage()));
            $this->line('  Perbaiki sebabnya, lalu jalankan `php artisan environment:upgrade` untuk menyelesaikannya.');

            return self::FAILURE;
        }

        $this->printOutcome($tenantId, $input['name'], $email, $appIds, $temporaryPassword);

        return self::SUCCESS;
    }

    /**
     * Jawaban untuk tenant yang sudah ada: "sudah ada" bila owner-nya sama, tolak bila berbeda.
     *
     * Owner dicocokkan lewat email, karena email-lah yang dicatat admin.erp dan dikirim agen — id user
     * di server ini tidak pernah dikenal di sana. Satu tenant boleh punya lebih dari satu owner;
     * cukup salah satunya yang cocok.
     */
    private function answerExisting(Tenant $existing, string $email): int
    {
        $owners = User::query()
            ->whereIn('id', TenantMembership::query()
                ->where('tenant_id', $existing->id)
                ->where('system_role', 'owner')
                ->select('user_id'))
            ->orderBy('email')
            ->pluck('email')
            ->all();

        if (in_array($email, $owners, true)) {
            $this->info(sprintf('Tenant %s (%s) dengan owner %s sudah ada. Tidak ada yang diubah.', $existing->id, $existing->name, $email));
            $this->line('  Bila pemasangan modul sebelumnya terputus, `php artisan environment:upgrade` menyelesaikannya.');

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'Tenant %s (%s) sudah ada di server ini, tetapi owner-nya bukan %s (owner di sini: %s). Tidak ada yang diubah. '
            .'Id tenant atau email owner yang dikirim admin.erp tidak cocok dengan yang pernah dilahirkan di server ini; '
            .'periksa catatan tenant dan situsnya di admin.erp sebelum mencoba lagi.',
            $existing->id,
            $existing->name,
            $email,
            $owners === [] ? 'tidak ada' : implode(', ', $owners),
        ));

        return self::FAILURE;
    }

    /**
     * App yang diberikan kepada tenant ini, atau null bila ada yang tidak dapat diberikan.
     *
     * `$exactly` benar pada jalur operasi `install` (hash kata sandi lewat stdin): daftar `--app`
     * adalah salinan app yang dibeli di admin.erp, **termasuk bila kosong**. Tanpa itu, tenant yang
     * hanya membeli Core diberi seluruh modul di image — dan image on-prem dikelola membawa semua
     * modul — sehingga entitlement di server klien tidak lagi sama dengan admin.erp. Jalur tangan
     * yang lama tetap memberi seluruh modul bila `--app` tidak disebut.
     *
     * @param  list<string>  $requested
     * @return list<string>|null
     */
    private function appsToEntitle(ModuleRegistry $registry, array $requested, bool $exactly): ?array
    {
        $inImage = $this->modulesInThisImage($registry);

        if ($requested === []) {
            return $exactly ? [] : $inImage;
        }

        $missing = array_values(array_diff($requested, $inImage));

        if ($missing !== []) {
            $this->error(sprintf(
                'App berikut dibeli tenant ini tetapi tidak ada sebagai modul di image ini: %s. Modul di image ini: %s. '
                .'Paket rilis dan perintah pasang tidak sepakat; rakit ulang paket untuk edisi tenant ini, lalu pasang lagi.',
                implode(', ', $missing),
                $inImage === [] ? 'tidak ada — hanya Core' : implode(', ', $inImage),
            ));

            return null;
        }

        sort($requested);

        return $requested;
    }

    /**
     * Seluruh modul yang ada di image ini.
     *
     * Image edisi dibangun dari manifest edisi — `edition:resolve` memilih folder modul yang disalin,
     * beserta dependency dan penghubungnya — jadi isi `modules/` di server pelanggan adalah persis
     * yang dibeli. Registry yang dibaca, bukan berkas edisi, karena registry-lah yang dimuat runtime:
     * modul yang diberikan tetapi tidak ada di image tidak dapat dipasang, dan modul yang ada tetapi
     * tidak diberikan adalah produk yang sudah dibayar dan tidak dapat dibuka.
     *
     * Modul bahan uji dilewati dengan aturan yang sama seperti `app:register-manifest`. Image edisi
     * memang tidak pernah memuatnya; yang dijaga di sini mesin pengembang yang menjalankan perintah ini.
     *
     * @return list<string>
     */
    private function modulesInThisImage(ModuleRegistry $registry): array
    {
        return array_values(array_map(
            static fn (ModuleManifest $module): string => $module->id,
            array_filter($registry->semua(), static fn (ModuleManifest $module): bool => ! $module->bahanUjiInternal()),
        ));
    }

    /**
     * Hash kata sandi sementara owner, dibaca dari stdin — atau null beserta alasannya.
     *
     * Stdin dibaca lewat aliran milik input bila ada (itulah yang diisi test dan `CommandTester`),
     * dan `STDIN` proses bila tidak. Terminal ditolak lebih dulu: orang yang menjalankan perintah ini
     * dengan tangan tanpa mengalirkan apa pun akan menatap perintah yang menunggu tanpa kata, dan
     * mengetik hash ke terminal meninggalkannya di riwayat layar.
     *
     * Isi yang ditolak tidak pernah dicetak. Yang dialirkan ke sini seharusnya hash, tetapi bisa
     * saja kata sandi polos yang salah alamat.
     */
    private function readPasswordHash(): ?string
    {
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
        $stream ??= defined('STDIN') ? STDIN : null;

        if (! is_resource($stream)) {
            $this->error('--admin-password-hash-stdin menunggu hash kata sandi lewat stdin, tetapi stdin tidak tersedia pada proses ini.');

            return null;
        }

        if (stream_isatty($stream)) {
            $this->error('--admin-password-hash-stdin menunggu hash kata sandi dialirkan lewat stdin, bukan diketik di terminal. Contoh: printf %s "$HASH" | php artisan tenant:bootstrap-site ...');

            return null;
        }

        $content = stream_get_contents($stream, self::STDIN_LIMIT);
        $hash = is_string($content) ? trim($content) : '';

        if (preg_match(self::BCRYPT, $hash) !== 1) {
            $this->error(
                'Yang dialirkan lewat stdin bukan hash bcrypt berbentuk $2y$<biaya>$<53 karakter>. '
                .'Isinya tidak dicetak. Tidak ada yang diubah.'
            );

            return null;
        }

        return $hash;
    }

    /**
     * Membacakan hasilnya — dan kata sandi sementara hanya bila kata sandi itu dibuat di sini.
     *
     * Ditulis ke keluaran perintah saja, tidak pernah ke log. Yang tersimpan hanya hash-nya, dan admin
     * wajib menggantinya saat pertama masuk.
     *
     * @param  list<string>  $appIds
     */
    private function printOutcome(string $tenantId, string $name, string $email, array $appIds, ?string $temporaryPassword): void
    {
        $environment = Environment::query()
            ->where('tenant_id', $tenantId)
            ->where('kind', 'production')
            ->first();

        $this->info(sprintf('Tenant %s lahir dengan id %s.', $name, $tenantId));
        $this->line(sprintf('  %-20s: %s', 'Lingkungan pertama', $environment instanceof Environment ? 'production ('.$environment->slug.')' : 'tidak ada'));
        $this->line(sprintf('  %-20s: %s', 'Modul', $appIds === [] ? 'tidak ada — hanya Core' : implode(', ', $appIds)));
        $this->line(sprintf('  %-20s: %s', 'Admin pertama', $email));

        if ($temporaryPassword === null) {
            $this->line(sprintf('  %-20s: %s', 'Kata sandi', 'yang ditunjukkan admin.erp kepada operator; hanya hash-nya yang tersimpan di sini'));
            $this->newLine();
            $this->warn('Admin wajib mengganti kata sandinya saat pertama masuk.');

            return;
        }

        $this->line(sprintf('  %-20s: %s', 'Kata sandi sementara', $temporaryPassword));
        $this->newLine();
        $this->warn('Kata sandi ini hanya tampil sekali dan tidak dapat dibaca lagi dari mana pun. Admin wajib menggantinya saat pertama masuk.');
    }

    /**
     * App yang disebut lewat `--app`, sebagai daftar teks yang sudah dirapikan.
     *
     * Dibaca lewat `getOption()` dengan alasan yang sama seperti {@see self::stringOption()}. Nilai
     * yang bukan teks tidak dibuang diam-diam melainkan dibiarkan sampai ke validasi sebagai teks
     * kosong, supaya ia ditolak dengan nama opsinya.
     *
     * @return list<string>
     */
    private function requestedApps(): array
    {
        $value = $this->input->getOption('app');
        $list = is_array($value) ? $value : [];

        return array_values(array_map(
            static fn (mixed $app): string => is_scalar($app) ? trim((string) $app) : '',
            $list,
        ));
    }

    /**
     * Nilai opsi sebagai teks.
     *
     * Dibaca lewat `getOption()` yang bertipe `mixed`, bukan `option()` yang PHPDoc-nya menjanjikan
     * string: pemanggil lewat `Artisan::call()` dapat mengirim tipe lain apa adanya.
     */
    private function stringOption(string $name): string
    {
        $value = $this->input->getOption($name);

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
