<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\CoreApp;
use App\Models\Environment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ControlPlane\TemporaryPassword;
use App\Support\Modules\ModuleManifest;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Melahirkan satu-satunya tenant sebuah server on-prem, dengan id yang sudah dicatat admin.erp.
 *
 * Catatan komersial tenant — klien, edisi, masa sewa — tinggal di admin.erp; datanya tinggal di
 * server pelanggan. Keduanya harus menyebut **id yang sama**, supaya laporan, tiket dukungan, dan
 * SSO menunjuk tenant yang sama tanpa tabel penerjemah. Sampai perintah ini ada, tenant on-prem
 * lahir lewat pendaftaran usaha di server itu sendiri dengan id baru, dan tidak ada yang dapat
 * menyambungkannya kembali. Pemanggilnya skrip pasang, dengan data situs dari admin.erp.
 *
 * Yang dilahirkan dikerjakan {@see RegisterBusiness}, bukan salinannya: dua salinan alur pembuatan
 * tenant adalah dua tempat yang akan menyimpang, dan yang menyimpang di sana rantai izin. Perintah
 * ini hanya menyiapkan masukannya — id, daftar modul, kata sandi sementara — lalu membacakan hasilnya.
 *
 * ## Aman diulang
 *
 * Skrip pasang dapat dijalankan ulang oleh orang yang tidak tahu apakah langkah ini sudah pernah
 * berhasil. Tenant yang sudah ada dengan id itu dijawab "sudah ada" dengan kode keluar nol, tanpa
 * menyentuh apa pun — termasuk tanpa kata sandi baru, karena kata sandi baru berarti admin yang
 * sudah bekerja mendadak tidak dapat masuk.
 */
final class BootstrapSiteTenant extends Command
{
    protected $signature = 'tenant:bootstrap-site
        {--tenant-id= : ULID tenant yang sudah tercatat di admin.erp}
        {--name= : Nama usaha pelanggan}
        {--admin-name= : Nama admin pertama}
        {--admin-email= : Email admin pertama}';

    protected $description = 'Lahirkan tenant server on-prem dengan id yang sudah dicatat admin.erp, beserta admin pertamanya';

    public function handle(RegisterBusiness $registerBusiness, ModuleRegistry $registry): int
    {
        $input = [
            'tenant_id' => $this->stringOption('tenant-id'),
            'name' => $this->stringOption('name'),
            'admin_name' => $this->stringOption('admin-name'),
            'admin_email' => $this->stringOption('admin-email'),
        ];

        $validator = Validator::make($input, [
            'tenant_id' => ['required', 'ulid'],
            'name' => ['required', 'string', 'max:255'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
        ], [
            'required' => 'Opsi :attribute wajib diisi.',
            'ulid' => 'Opsi :attribute harus berupa ULID 26 karakter, persis seperti yang tercatat di admin.erp.',
            'email' => 'Opsi :attribute harus berupa alamat email.',
            'max' => 'Opsi :attribute paling panjang :max karakter.',
        ], [
            'tenant_id' => '--tenant-id',
            'name' => '--name',
            'admin_name' => '--admin-name',
            'admin_email' => '--admin-email',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        // Huruf kecil karena begitulah `HasUlids` menulis setiap id — di Core maupun di admin.erp.
        // ULID sendiri tidak peka huruf besar, tetapi kolom PostgreSQL peka: `01J…` dan `01j…` akan
        // menjadi dua tenant yang berbeda bagi setiap query yang mencocokkan keduanya.
        $tenantId = Str::lower($input['tenant_id']);

        $existing = Tenant::query()->find($tenantId);

        if ($existing instanceof Tenant) {
            $this->info(sprintf('Tenant %s (%s) sudah ada. Tidak ada yang diubah.', $existing->id, $existing->name));
            $this->line('  Bila pemasangan modul sebelumnya terputus, `php artisan environment:upgrade` menyelesaikannya.');

            return self::SUCCESS;
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

        $email = Str::lower(trim($input['admin_email']));

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

        $appIds = $this->modulesInThisImage($registry);
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

        $temporaryPassword = TemporaryPassword::generate();

        try {
            $registerBusiness->handle([
                'name' => $input['admin_name'],
                'email' => $email,
                'password' => $temporaryPassword,
                'business_name' => $input['name'],
                'app_ids' => $appIds,
                'must_change_password' => true,
                'first_environment' => 'production',
                'tenant_id' => $tenantId,
            ]);
        } catch (Throwable $exception) {
            // Tidak ada yang tertulis: exception itu sendiri sudah seluruh ceritanya.
            if (! Tenant::query()->whereKey($tenantId)->exists()) {
                throw $exception;
            }

            /*
             * Tenant sudah tertulis, dan yang gagal langkah sesudah commit — pemasangan modul beserta
             * penyiapan urutan nomornya.
             *
             * Kata sandinya tetap dibacakan. Tanpanya admin pertama tidak pernah dapat masuk: kata
             * sandi itu hanya ada di memori proses ini, dan menjalankan ulang perintah ini menjawab
             * "sudah ada" tanpa membuat yang baru.
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
     * Modul yang diberikan kepada tenant ini: seluruh modul yang ada di image ini.
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
     * Membacakan hasilnya, termasuk kata sandi sementara — satu-satunya kesempatan melihatnya.
     *
     * Ditulis ke keluaran perintah saja, tidak pernah ke log. Yang tersimpan hanya hash-nya, dan admin
     * wajib menggantinya saat pertama masuk.
     *
     * @param  list<string>  $appIds
     */
    private function printOutcome(string $tenantId, string $name, string $email, array $appIds, string $temporaryPassword): void
    {
        $environment = Environment::query()
            ->where('tenant_id', $tenantId)
            ->where('kind', 'production')
            ->first();

        $this->info(sprintf('Tenant %s lahir dengan id %s.', $name, $tenantId));
        $this->line(sprintf('  %-20s: %s', 'Lingkungan pertama', $environment instanceof Environment ? 'production ('.$environment->slug.')' : 'tidak ada'));
        $this->line(sprintf('  %-20s: %s', 'Modul', $appIds === [] ? 'tidak ada — hanya Core' : implode(', ', $appIds)));
        $this->line(sprintf('  %-20s: %s', 'Admin pertama', $email));
        $this->line(sprintf('  %-20s: %s', 'Kata sandi sementara', $temporaryPassword));
        $this->newLine();
        $this->warn('Kata sandi ini hanya tampil sekali dan tidak dapat dibaca lagi dari mana pun. Admin wajib menggantinya saat pertama masuk.');
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
