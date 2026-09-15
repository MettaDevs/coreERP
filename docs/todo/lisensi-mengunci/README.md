# Lisensi yang mengunci

Rencana kerja, bukan desain kanonik. Ditulis 15 September 2026 setelah pemilik produk memutuskan
lisensi server klien **mengunci**, bukan hanya memperingatkan. Keputusan itu lahir bersama keputusan
**satu image untuk semua klien** di [Registry image sendiri dengan Harbor](/todo/registry-harbor/):
begitu image membawa kode seluruh modul, satu-satunya yang membedakan klien yang membeli satu app dari
klien yang membeli semuanya adalah lisensinya.

Halaman ini **menggantikan** keputusan "Lisensi habis → peringatan, tanpa mengunci" di
[On-prem yang dikelola vendor](/todo/on-prem-dikelola/).

## Pertanyaan yang dijawab halaman ini

- App mana yang boleh dibuka tenant di server klien, dan siapa yang memutuskannya?
- Apa yang terjadi saat lisensi habis, hilang, atau diubah — dan siapa yang tetap dapat masuk?
- Bagaimana lisensi diperpanjang tanpa operator mengingatnya setiap bulan?
- Bagaimana sewa dihentikan dari admin.erp?
- Apa yang tidak dapat dicegah lisensi, dan bagaimana kita mengetahuinya?

## Keputusan

| Keputusan | Diambil | Yang dibeli | Yang dibayar |
| --- | --- | --- | --- |
| Sumber kebenaran app | App yang aktif di `tenant_app_entitlements` saat lisensi diterbitkan, dibaca admin.erp lewat API internal Core | Satu tempat mengubah app yang dibeli, sama dengan SaaS | Perubahan app baru berlaku di server klien setelah lisensi berikutnya terpasang |
| Isi lisensi | JSON bertanda tangan: tenant, situs, **daftar app**, tanggal berakhir | Mengubah database di server klien tidak dapat menambah app | Format lama (versi 1) tidak lagi diterima — belum ada satu pun klien yang memakainya |
| Masa berlaku | **30 hari**, diperpanjang otomatis | Sewa yang berhenti mengunci aplikasi dalam hitungan minggu, bukan tahun | Server yang tidak dapat menghubungi admin.erp lebih dari tiga minggu ikut terkunci |
| Perpanjangan | admin.erp menyertakan lisensi baru di **jawaban laporan agen** saat sisa masanya ≤ 10 hari | Tanpa penjadwal, tanpa operator; setiap perpanjangan diaudit | Satu panggilan API internal saat perpanjangan jatuh tempo |
| Saat terkunci | Pengguna tenant melihat halaman "Lisensi tidak berlaku"; **akun provider tetap masuk**; data tidak disentuh; login, logout, dan `/up` tetap bekerja | Vendor masih dapat memperbaiki; tidak ada data yang hilang | Pelayanan klinik berhenti — itu memang tujuan kuncinya, dan karena itu peringatannya tampil 7 hari sebelumnya |
| Lisensi hilang atau tanda tangannya salah | **Terkunci**, sama dengan habis | Menghapus berkas lisensi bukan jalan pintas | Salah pasang di sisi kita juga mengunci; halaman kunci menyebut sebabnya |
| Kewajiban lisensi | `COREERP_LICENSE_REQUIRED=true` di `.env` server on-prem dikelola; bawaannya `false` | Pemasangan beli-putus dan SaaS tidak pernah terkunci | Root di server klien dapat mematikannya — lihat "Yang tidak dapat dicegah" |
| Menghentikan sewa | Tombol "Hentikan perpanjangan lisensi" di halaman situs admin.erp | Tanpa menyentuh server klien; lisensi yang berjalan habis dengan sendirinya | Paling lama 30 hari sampai terkunci |

## Kontrak

### Berkas lisensi (versi 2)

`license.json` — byte persis yang ditandatangani:

```json
{"version":2,"tenant_id":"01J...","site_id":"01J...","apps":["human-resources","management-aset"],"valid_until":"2026-10-15","issued_at":"2026-09-15T08:00:00Z"}
```

| Bidang | Aturan |
| --- | --- |
| `version` | tepat `2`; versi lain ditolak |
| `apps` | id app, unik, terurut; boleh kosong (hanya Core) |
| `valid_until` | tanggal kalender `YYYY-MM-DD`; hari itu masih berlaku |

`license.json.sig` — base64 satu baris dari tanda tangan RSA PKCS#1 v1.5 SHA-256 atas byte
`license.json`. Kunci publiknya `license-public.pem`, diantar ke agen saat pendaftaran.

### Core

| Setelan | Env | Bawaan |
| --- | --- | --- |
| `coreerp.license.required` | `COREERP_LICENSE_REQUIRED` | `false` |
| `coreerp.license.path` | `COREERP_LICENSE_PATH` | — |
| `coreerp.license.public_key_path` | `COREERP_LICENSE_PUBLIC_KEY_PATH` | — |
| `coreerp.license.warn_days` | — | `7` |

- **Terkunci** berarti `required` dan keadaannya `missing`, `invalid`, atau `expired`.
- **App diizinkan** bila lisensi tidak wajib; bila wajib dan tidak terkunci, hanya app di `apps`.
- Penyaringan app dipasang di satu penentu dan dipakai di setiap pintu:
  - `ResolveModuleContext` — halaman dan API setiap modul;
  - `LaunchableAppCatalog::for()` — peluncur, `/apps/{app}`, `launch-manifest`;
  - `HandleInertiaRequests` — daftar produk yang dimiliki;
  - `AuthenticateAppService` — panggilan `internal/v1` antar-app.
- Middleware kunci di grup `web` sesudah `WajibGantiSandi`, dengan daftar rute yang tetap terbuka yang
  sama polanya. Permintaan JSON mendapat `403 {"error":"license_locked"}`.
- API internal baru untuk admin.erp: `GET /api/internal/v1/tenants/{tenant}/entitlements` →
  `{"tenant_id": "...", "apps": [...]}`, di grup `control-plane`, didaftarkan di
  `apps/core/contracts/openapi-internal.yaml`.

### admin.erp

| Setelan `config/sites.php` | Bawaan |
| --- | --- |
| `license_valid_days` | `30` |
| `license_renew_before_days` | `10` |
| `license_renew_cooldown_minutes` | `60` |

- Kolom baru di `sites`: `license_issued_at`, `license_valid_until`, `license_suspended_at`.
- Jawaban `POST /api/agent/v1/report` menjadi `{"interval_seconds": n, "license"?: {"license": "...", "signature": "..."}}`.
  `license` disertakan hanya bila:
  - situs tidak dicabut dan `license_suspended_at` kosong;
  - laporan agen menyebut `license_expires_at` kosong atau ≤ hari ini + `license_renew_before_days`;
  - lisensi terakhir diterbitkan lebih lama dari `license_renew_cooldown_minutes` yang lalu.
- API internal Core yang gagal **tidak pernah** menggagalkan laporan: laporan tetap diterima, lisensi
  dicoba lagi setelah jeda.
- Setiap penerbitan dicatat di `operator_audit_events` (pengguna kosong untuk perpanjangan otomatis).
- Laporan agen membawa bidang baru `license_required` (boolean). admin.erp menampilkan peringatan bila
  situs on-prem dikelola melaporkan `false`.
- Operasi `install_license` tetap ada untuk memasang lisensi segera, misalnya setelah app ditambah.

### Agen

- Jawaban laporan yang membawa `license` dipasang lewat pemeriksaan yang sama dengan operasi
  `install_license`: tanda tangan, `site_id`, `version`. Kegagalan memasang tidak menggagalkan putaran.
- `susun_laporan` menambahkan `license_required` dari `.env`.
- `deploy/agent/env.template` menyetel `COREERP_LICENSE_REQUIRED=true`; `deploy/compose.edition.yaml`
  meneruskan `${COREERP_LICENSE_REQUIRED:-false}` ke setiap service yang memasang folder lisensi.

## Yang tidak dapat dicegah, dan cara mengetahuinya

Server klien milik klien. Root di sana dapat mengubah `.env`, mengubah kode PHP di dalam container,
atau mematikan agen. Lisensi menaikkan biaya pelanggaran dari "ubah satu baris database" menjadi
"sengaja membongkar pemasangan", dan sisanya dijaga kontrak.

| Pelanggaran | Yang terlihat di admin.erp |
| --- | --- |
| `COREERP_LICENSE_REQUIRED` dimatikan | Laporan `license_required: false` → peringatan di halaman situs |
| Agen dimatikan | Situs tampil tertinggal; lisensi berhenti diperpanjang lalu habis |
| Kode PHP diubah | Tertimpa pada pembaruan berikutnya; pemeriksaan isi berkas rilis di setiap putaran agen adalah pekerjaan lanjutan |

## Tahapan

1. **Core**: pembaca versi 2, kunci, penyaringan app, halaman kunci, API entitlements.
2. **admin.erp**: penerbit versi 2, perpanjangan lewat jawaban laporan, penghentian sewa, kontrak.
3. **Agen**: memasang lisensi dari jawaban laporan, `license_required`, setelan compose.
4. **Uji ujung-ke-ujung** di server kedua bersama E2E registry: lisensi habis mengunci, akun provider
   masuk, perpanjangan membuka kembali, app yang tidak dibeli tidak dapat dibuka.

## Kriteria terima

- App yang tidak ada di lisensi tidak muncul di peluncur dan halamannya menjawab 403, walaupun
  `tenant_app_entitlements` diubah langsung di database.
- Lisensi habis, hilang, atau tanda tangannya salah mengunci pengguna tenant; akun provider tetap masuk;
  login, logout, dan `/up` bekerja.
- Pemasangan dengan `COREERP_LICENSE_REQUIRED` kosong tidak pernah terkunci.
- admin.erp menyertakan lisensi baru hanya saat jatuh tempo, tidak untuk situs yang dicabut atau yang
  perpanjangannya dihentikan, dan tetap menerima laporan saat API internal Core gagal.
- Agen memasang lisensi dari jawaban laporan dan menolak lisensi bertanda tangan salah atau milik situs
  lain.
- Setiap penjaga baru dibuktikan dapat merah.
