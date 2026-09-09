# Kontrak module Management Aset — statusnya sekarang

**Keputusan (F3-23): berkas di folder ini adalah dokumentasi, bukan kontrak yang dijaga CI.**
Ia dipertahankan karena menggambarkan bentuk permintaan dan jawaban yang sesungguhnya, bukan
karena ada pemanggil di luar module yang bergantung padanya.

## Kenapa statusnya berubah

Kontrak adalah janji kepada kode yang tidak kita kendalikan. Ketika Management Aset masih
aplikasi tersendiri, `openapi.yaml` memang janji seperti itu: ia dibaca proses lain yang
dibangun dan dirilis terpisah. Setelah module masuk ke dalam runtime Core, satu-satunya
pemanggil rute `/api/v1/...` adalah antarmuka module ini sendiri — dibangun dari
`modules/apperp/management-aset/ui/`, di dalam repo yang sama, dirilis bersama. Aturan
kontrak di `.claude/skills/coreerp-architecture/SKILL.md` menyebut kasus ini sendiri:
spesifikasi yang dibangkitkan hanya sah untuk permukaan yang konsumennya adalah UI module
itu sendiri.

Permukaan yang benar-benar melewati batas module berpindah ke kontrak PHP di dalam proses,
bukan ke berkas YAML:

- Keputusan workflow dan penerimaan event sudah menjadi event Laravel in-process (F3-09;
  penyediaan tenant menyusul di F3-11). Sisi penerbit tetap dikontrakkan pada
  `apps/control-plane/contracts/asyncapi.yaml`, dan **itulah** salinan yang berwenang.
- Laporan dibaca Core lewat `App\Support\Modules\Contracts\PenyediaLaporanModul`, bukan
  lewat `internal/v1/laporan/...` (F3-12).

## Pemeriksa cakupan sudah dihapus, dan ia memang tidak pernah berjalan di sini

`check-contract-coverage.py` dibuang bersama keputusan ini. Dua alasannya terpisah, dan
keduanya cukup sendiri-sendiri:

1. **Tidak ada alur CI yang memanggilnya.** `.github/workflows/lint.yml` memang menjalankan
   `python3 contracts/check-contract-coverage.py`, tetapi langkah itu memakai
   `working-directory: apps/control-plane` — yang dijalankan adalah salinan milik Core, yang
   membandingkan `routes/api.php` Core dengan `contracts/openapi-internal.yaml` Core. Salinan
   milik module tidak pernah tersentuh. Standar repo ini menyebutnya apa adanya: pemeriksa
   yang tidak dipanggil pipeline mana pun adalah berkas, bukan gerbang.
2. **Ia sudah tidak bisa dijalankan sama sekali.** Skripnya menetapkan `API_DIR = Path("api")`
   lalu menjalankan `php artisan route:list --json` di sana. Sejak F3-02 membuang berkas
   kerangka, `api/` tidak lagi memuat `artisan` maupun `bootstrap/`, jadi skripnya berhenti
   pada pesan "`php artisan route:list` failed" sebelum membandingkan satu rute pun.

Tidak ada langkah pengganti yang ditambahkan ke `lint.yml`. Rute module sekarang dijaga
test module, dan penyimpangannya terlihat pada UI yang memanggilnya — bukan pada berkas
YAML yang tidak dibaca siapa-siapa.

## Yang masih berguna dari folder ini

| Berkas | Kenapa disimpan |
| --- | --- |
| `openapi.yaml`, `src/` | Gambaran tertulis 200-an endpoint module beserta bentuk payload-nya; satu-satunya tempat aturan `enum`, kode error, dan pola paginasi ditulis utuh |
| `bundle.py` | Penggabung `src/` menjadi `openapi.yaml`; tetap dipakai kalau dokumentasinya disunting |
| `asyncapi.yaml` | Bentuk amplop event yang diterima module; **salinan berwenangnya ada di Core**, dan bila keduanya berbeda, Core yang benar |

Karena tidak ada lagi yang memeriksanya, berkas di sini **bisa basi tanpa ada yang gagal**.
Perlakukan sebagai catatan, bukan sebagai janji. Nasib akhirnya — termasuk apakah
`asyncapi.yaml` masih perlu ada dalam bentuk salinan — dievaluasi ulang pada F7-06
("Bersihkan sisa") di `docs/todo/satu-runtime/01-prd.md`.
