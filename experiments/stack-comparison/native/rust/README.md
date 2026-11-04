# Kandidat Rust untuk pembanding stack

Kandidat ini menjalankan profil `pending-purchase-v1` dari source CoreERP
`6b3cf5846fd4442c3e895cacf2ba58275f9dd75e`. Proses HTTP, sesi, login, izin, nomor,
validasi, penyimpanan aset, penerbitan posting, dan readback berjalan di Rust.
Tidak ada pemanggilan PHP, proxy ke Core, rekaman SQL untuk diputar ulang, atau
stored procedure yang menjalankan seluruh transaksi.

Database memakai schema, constraint, dan trigger CoreERP asli. Fixture sintetis
disiapkan sekali oleh pembanding bersama sebelum kandidat dijalankan. Kandidat
tidak memigrasikan atau mengubah schema tersebut.

## Menjalankan

Dari folder ini, dengan Rust dan linker yang sesuai target tersedia:

```powershell
cargo build --release --locked -j 2
cargo test --release --locked -j 2
cargo fmt --check
```

Image compiler yang dipaku pada `Dockerfile` berisi Rust 1.96.1 untuk Linux musl.
Image hasil hanya berisi binary pada `scratch`, dengan user 65532. Image itu tidak
berisi PHP. Pada laptop Windows tanpa linker MSVC, gunakan image tersebut untuk
build.

```powershell
.\run.ps1 -RuntimeEnv <path-runtime.env> -Build
```

`runtime.env` memasok `APP_KEY` dan `DB_PASSWORD` dari fixture privat. Script
menjalankan container dengan dua CPU, memori 1 GiB, dan port loopback. Nilai
rahasia tidak disimpan dalam source maupun image.

| Variabel | Bawaan | Kegunaan |
| --- | --- | --- |
| `APP_KEY` | Wajib | Key cookie Laravel AES-256-CBC, format `base64:` atau 32 byte. |
| `DB_PASSWORD` | Wajib | Password PostgreSQL. |
| `DB_HOST` | `127.0.0.1` | Host PostgreSQL. Script Docker memakai `host.docker.internal`. |
| `DB_PORT` | `18640` | Port PostgreSQL. |
| `DB_USERNAME` | `compare_admin` | User PostgreSQL. |
| `DB_DATABASE` | `erp_compare_rust` | Clone database kandidat. |
| `DB_POOL` | `8` | Jumlah koneksi maksimal per proses. |
| `BIND_HOST` | `127.0.0.1` | Alamat listener. Image memakai `0.0.0.0`. |
| `PORT` | `18643` | Port listener. |
| `SESSION_COOKIE` | `laravel-session` | Nama cookie sesi fixture PHP aktual. |
| `CACHE_PREFIX` | `laravel-cache-` | Awalan kunci tabel `cache` untuk limiter login, sama dengan PHP. |
| `SESSION_BACKEND` | `database` | `database` (tabel `sessions`) atau `valkey`; lihat `../../optimized.md`. |
| `VALKEY_HOST`, `VALKEY_PORT` | `valkey`, `6379` | Valkey untuk `SESSION_BACKEND=valkey`. Kegagalannya menjadi 503. |
| `SESSION_KEY_PREFIX` | `coreerp-session:` | Awalan kunci sesi di Valkey; nilai sama dengan `sessions.payload`, TTL 7200 detik. |
| `CONTEXT_CACHE` | `off` | `on` menyimpan konteks per proses, dikosongkan saat `security_context_versions` berubah. |
| `CONTEXT_CACHE_TTL_SECONDS` | `300` | Umur maksimum entri; entri kebijakan dan izin juga berakhir pada batas validitas berikutnya. |

Dengan `SESSION_BACKEND` dan `CONTEXT_CACHE` pada bawaannya, query dan urutannya sama dengan pembanding
dasar. `CONTEXT_CACHE=on` membutuhkan `../../infra/optimized-schema.sql` pada database kandidat.

Untuk Docker pada server Linux, pasok host database yang dapat dijangkau dari
container dan konfigurasi resource/pool dari rencana pembanding. Script lokal
bukan konfigurasi uji dua replica.

## Memverifikasi

`conformance.py` memakai driver HTTP pembanding melalui `--harness`. Driver itu
hanya mengirim HTTP; script tidak menjalankan PHP. Contoh dari folder ini:

```powershell
python conformance.py --fixture <fixture.json> --env <runtime.env> --harness <scripts/http_baseline.py> --output private/conformance.json
python auth_probes.py --fixture <fixture.json> --env <runtime.env> --harness <scripts/http_baseline.py> --database erp_compare_rust --output private/auth-probes.json
python timestamp_probe.py --fixture <fixture.json> --env <runtime.env> --harness <scripts/http_baseline.py> --database erp_compare_rust --output private/timestamp-after.json
```

`auth_probes.py` mencabut lalu memulihkan status, tanggal berlaku, dan scope pada
clone pengembangan Rust. Probe ini juga menghapus dan membuat ulang sesi uji.
Metadata audit master dapat bertambah karena probe memakai UPDATE biasa.

Oracle independen berada pada `experiments/stack-comparison/scripts/sql_oracle.py`.
Jalankan oracle atas clone yang sama setelah HTTP, lalu jalankan `--selftest`
untuk membuktikan bahwa perubahan satu sen di dalam transaksi rollback terdeteksi.
Oracle dan pembanding HTTP tidak mengimpor fungsi money atau response kandidat.

Bukti lokal pada 3 November 2026:

- Tujuh unit test lulus untuk money HALF_UP dan alokasi remainder, autentikasi
  cookie, scope, versi, pencarian, dan cursor.
- `conformance.py` lulus 26 pemeriksaan HTTP, termasuk alur utama, replay dengan
  body berubah, If-Match, izin, CSRF, cookie rusak, scope, cursor, dan race completion.
- `auth_probes.py` lulus 11 pemeriksaan pencabutan akses dan sesi yang masih hidup.
- Oracle SQL lulus 24 pemeriksaan setelah probe. Selftest menemukan ketiga efek
  perubahan satu sen dan rollback mengembalikan oracle menjadi hijau.
- `timestamp_probe.py` mereproduksi selisih timestamp lama saat publication
  menunggu lock akun. Sesudah perbaikan, timestamp kolom selalu disalin dari
  input/payload; probe yang sama kembali hijau saat melewati pergantian detik.
- Satu run `golden-20261103` pada clone `erp_compare_final_rust` cocok dengan PHP
  pada 12 request, yaitu sepuluh aksi profil dengan `asset-show` untuk ketiga aset.
  Pembanding HTTP menjawab `VERIFIED`, nol perbedaan; oracle SQL juga hijau.

Raw response, waktu request, dan laporan oracle disimpan privat oleh koordinator.
Hasil di atas tidak membuktikan kapasitas, query accounting lengkap, atau parity
di luar kasus yang dijalankan.

## Batas implementasi

Rantai utama memakai pembelian dengan vendor, mode `direct_payable`, tiga unit,
atribut wajib, buku straight-line bulanan dengan tahun kalender, dan posting
`pending`. Semua nama dan kode bisnis dibaca dari database.

Cabang yang belum dicakup mencakup upload dan pewarisan lampiran, tautan
permintaan pembelian, hibah, saldo awal, perintah pembatalan, challenge 2FA,
rotasi previous key, alamat environment dengan database khusus,
UI Inertia lengkap, nomor fiskal/continuous, dan metode penyusutan lainnya.
Cabang domain tersebut ditolak dengan `unsupported_profile` atau tidak memiliki
route. Tidak ada jawaban 200 pengganti operasi yang belum dijalankan.

Bootstrap `/login` menyediakan HTML dan cookie untuk klien HTTP. Bootstrap itu
bukan implementasi seluruh halaman login CoreERP. `X-Inertia: true` tanpa versi
menghasilkan 409. Pemilihan workspace dan seluruh akses module membaca ulang
otoritas dari database pada setiap request; dengan `CONTEXT_CACHE=on`, otoritas
dibaca dari cache proses sesudah query versi konteks pada request yang sama,
sedangkan pemilihan workspace tetap dihitung per request. Pemeriksaan lisensi wajib di luar
konfigurasi fixture belum diimplementasikan.

Publisher juga menghitung `held` dan `manual` dari mapping dan setting yang
dibacanya, tetapi perbandingan publikasi yang dibuktikan di sini hanya `pending`.
Driver memakai strategi query native, pool PostgreSQL, dan reset koneksi setelah
request. Hasil performa kelak mencakup seluruh pilihan tersebut dan tidak boleh
diatribusikan hanya ke runtime Rust.

## Kepemilikan kode

`main.rs` memiliki adapter HTTP dan pool. `auth.rs` memiliki sesi JSON/base64,
cookie/CSRF, password, limiter login (primitif cache Laravel menurut
`../../login-limiter.md`), workspace, role hierarchy, dan data scope. `context_cache.rs`
memiliki cache konteks per proses untuk `CONTEXT_CACHE=on`. `numbering.rs`
memiliki scope/context, preallocation, lock, replay, dan audit nomor. `receipts.rs`
memiliki transaksi penerimaan, aset, placement, buku, atribut, dan attachment
callback. `posting.rs` memiliki normalisasi, mapping akun, dimensi historis,
snapshot, dan tiga tabel feed finance. `lists.rs` memiliki RecordSearch dan cursor
IncrementalList. `money.rs` memiliki pembulatan dan pembagian nilai exact.

Nomor header memiliki commit sendiri sebelum INSERT penerimaan. Claim versi dan
UPDATE header memakai trigger yang sama dengan PHP sehingga versinya dapat naik
dua kali. Asset, nomor aset, placement, buku beserta trigger ledger, atribut, dan
posting disimpan dalam satu transaksi completion. Actor audit dipasang saat
pemulihan user; actor dan metadata ledger tidak ditinggalkan pada koneksi pool.
