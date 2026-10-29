# Hasil audit backend admin.erp

Tanggal: 23 Oktober 2026. Source: `D:/Kerja/CoreERP/apps/control-plane` dan integrasi Core yang dipanggil konsol.

## Hasil

VERIFIED untuk jalur yang dicantumkan di bawah. Perubahan berada di working tree lokal; belum dideploy ke admin.erp. Tidak ada klaim bahwa semua integrasi eksternal atau seluruh Core sudah diverifikasi.

| Jalur | Perubahan | Bukti |
| --- | --- | --- |
| Password dan MFA | Password belum mengesahkan sesi ketika MFA aktif. Core memverifikasi dengan Fortify, termasuk penolakan penggunaan ulang OTP. Recovery code dikonsumsi dengan row lock. Hak operator dan kebijakan password diperiksa lagi sebelum sesi dibuat. | TwoFactorLoginTest; OperatorSecondFactorTest; mfa-real-http.log |
| Batas enkripsi | Core dan konsol mempertahankan APP_KEY berbeda. Konsol meneruskan kode ke API internal, tanpa mendekripsi secret Core. | mfa-real-http.log; mfa-http-fixture.php; mfa-http-console.php |
| Tenant dan lingkungan | Pembuatan lingkungan melalui Core API; lingkungan, operasi, serta audit disimpan atomik. Respons harus 201 dan cocok dengan tenant. | EnvironmentCreationApiTest; http-smoke.log |
| Provisioning dan daftar module | Status dan bentuk respons API diperiksa. Gagal membaca module ditampilkan sebagai error, bukan daftar kosong. | CoreResponseValidationTest; EnvironmentProvisioningApiTest; http-smoke.log |
| Upgrade | Satu reservasi per lingkungan; permintaan berulang tidak menggandakan pekerjaan. UI membedakan menunggu dan sedang berjalan. | FleetUpgradeTest; http-smoke.log; queue-worker.log; http-worker-verified.log |
| Lisensi | Kebijakan situs dibaca ulang di dalam transaksi setelah panggilan jaringan. Situs yang dicabut atau ditangguhkan tidak mendapat perpanjangan otomatis. | LicenseRenewalTest; baseline-regressions.log |
| DNS dan revoke | Metadata dan audit atomik. Revoke berulang dapat mengulangi cleanup yang gagal. | SiteDnsTest; baseline-regressions.log |
| Registry | Hak situs diperiksa lagi setelah pembuatan robot Harbor. Robot dikompensasi jika situs sudah dicabut; secret tidak diberikan. | RegistryCredentialTest |
| Rilis | Nomor rilis harus tiga bagian numerik kanonik yang dapat diproses agen. | ReleaseRegistrationTest |
| SSO | Tautan memakai soft delete dan indeks unik parsial. Logout menolak token lama dan replay. Link/unlink dan audit atomik. | SsoLoginTest kedua aplikasi; runtime-sso-schema.log |

## Pemeriksaan yang dijalankan

- Seluruh suite konsol: **440 test, 2971 assertion lulus** (`console-final.log`, XML tersedia).
- Suite Core terbatas pada SSO, boundary, pembuatan/provisioning lingkungan, upgrade, dan MFA: **95 test, 444 assertion lulus** (`core-targeted.log`, XML tersedia). Ini bukan seluruh suite Core.
- Empat regresi terbukti gagal pada kode lama sebelum perbaikan (`baseline-regressions.log`).
- HTTP nyata dari adapter konsol ke Core: tenant, lingkungan, provisioning, deduplikasi upgrade, serta fleet; persistensi diperiksa melalui SQL (`http-smoke.log`).
- Worker nyata menyelesaikan upgrade; pekerjaan gagal nol dan reservasi antrean dilepas (`queue-worker.log`, `http-worker-verified.log`).
- Controller MFA konsol ke HTTP Core nyata dengan key berbeda: OTP berhasil, recovery berhasil sekali, replay ditolak tanpa sesi; kode yang dikonsumsi tidak lagi ada di database (`mfa-real-http.log`). Ini bukan pengujian browser login end-to-end.
- PHPStan konsol penuh dan file Core yang berubah lulus (`phpstan-final.log`, `core-phpstan.log`).
- Pint untuk PHP yang berubah, Prettier konsol, ESLint konsol, TypeScript konsol, dan build Vite lulus.
- Bundle kontrak Core dan coverage **27 route internal** lulus. Coverage kontrak agen konsol **8 route** lulus.
- Migration SSO dijalankan pada runtime lokal; query runtime membuktikan kolom deleted_at dan kedua indeks unik memakai `WHERE deleted_at IS NULL` (`runtime-sso-schema.log`).

Database pengujian memakai PostgreSQL khusus audit pada loopback port 5548, terpisah dari database UI lokal. Warning ekstensi OpenTelemetry host yang tidak terpasang tercatat dalam log; tidak dihitung sebagai hasil test gagal.

## Dependency

Audit Composer konsol: **0 advisory** setelah patch commonmark dan http-foundation (`console-composer-audit-final.json`).

Core memperbarui commonmark, http-foundation, Guzzle beserta psr7, Laravel 13, Flysystem, dan YAML dalam batas constraint yang ada. Identitas package module lokal dipertahankan. Suite Core di atas diulang setelah pembaruan terakhir.

**Masih terbuka: 10 advisory Core pada phpoffice/math dan phpoffice/phpspreadsheet**, termasuk advisory berperingkat critical pada PhpSpreadsheet (`core-composer-audit-final.json`). Core mematok PhpSpreadsheet 4.0 dan PHPWord 1.3. Penyelesaiannya memerlukan perubahan dependency serta verifikasi alur impor/ekspor dokumen di luar jalur konsol yang diperiksa di sini. Tidak ada klaim audit keamanan Core bersih.

## Batas verifikasi dan penerapan

- Harbor, Cloudflare/DNS, dan penyedia OIDC diuji dengan fake yang menyimpan state dan token bertanda tangan; akun layanan eksternal tidak dipanggil langsung.
- Belum ada deploy, pemeriksaan runtime server dev1, atau pengujian browser terhadap admin.erp publik dalam tahap backend ini.
- Perubahan Core API MFA dan konsol harus dirilis bersama. Migrasi SSO tetap diperlukan pada target rilis. Key konsol tidak diganti menjadi key Core.
- Perubahan pengguna di Core UI, module aset, serta SDK yang tidak berkaitan dipertahankan.

## Keputusan desain

Foundational Thinking menempatkan verifikasi secret pada pemilik key, yaitu Core. Minimize Reader Load mempertahankan satu jalur API untuk membuat lingkungan. Prove It Works menuntut HTTP, worker, dan query database nyata selain feature test. Jejak keputusan ada di `decisions.tsv`; harness dapat dijalankan ulang hanya dengan database audit yang sesuai guard-nya.

Server HTTP dan container PostgreSQL khusus audit dihentikan setelah verifikasi. Harness memerlukan database audit baru untuk dijalankan ulang.
