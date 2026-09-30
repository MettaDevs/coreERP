# Layar setup yang belum berisi

Halaman ini untuk developer. Isinya layar yang **sudah dideklarasikan di manifest** tetapi isinya belum selesai.

Ini penting karena layar yang terdaftar di manifest **sudah muncul di navigasi Shell** begitu tenant diberi permission-nya. Kalau isinya kosong tanpa penjelasan, pengguna mengira aplikasinya rusak.

Layar monitoring aset yang dulu dibahas di sini, ringkasan register yang membaca `GET /aset`, sejak 30 September 2026 menjadi dokumen pemeriksaan fisik sungguhan: lihat [Monitoring aset](/apps/management-aset/transaction/monitoring-aset/).

## Layar setup yang sengaja kosong

`fixed-asset-parameters` menampilkan `Empty` dengan penjelasan, bukan form: pengaturan pembulatan saat ini disimpan pada Buku penyusutan, dan pengaturan lain menunggu kebutuhannya dipastikan. Komponennya `ui/pengaturan-aset-tetap/PengaturanAsetTetapPlaceholderPage.tsx`.

Layar kosong kedua yang dulu ada di sini, profil posting aset, sudah berisi sejak 23 September 2026 sebagai [Posting group aset](/apps/management-aset/master/posting-group/). Akunnya ternyata tidak menunggu modul Finance: daftar akun referensi di Core menjadi sumbernya (K-05 feed posting finance).

### Kenapa layarnya sudah ada padahal isinya belum

Karena tempatnya di navigasi sudah pasti, dan **kosong yang dijelaskan lebih baik daripada menu yang hilang**. Orang yang mencari "profil posting" akan menemukan layarnya beserta alasan kenapa belum ada isinya, bukan mengira fitur itu tidak pernah direncanakan lalu membuat pemetaan akun sendiri di tempat lain.

Yang penting: **teks kosongnya menyebut alasan dan penggantinya.** Layar kosong tanpa penjelasan adalah cacat; layar kosong yang menerangkan di mana pengaturannya sekarang berada adalah dokumentasi yang muncul tepat di tempat orang mencarinya.

## Kalau Anda mengisi salah satunya

1. Endpoint barunya masuk kontrak dalam perubahan yang sama.
2. Permission-nya sudah ada di manifest — periksa dulu sebelum menambah yang baru.
3. Pengaturan yang menyentuh akun menunjuk daftar akun referensi Core lewat kontrak `DaftarAkun`, seperti posting group, bukan tabel akun sendiri di modul ini.

## Halaman terkait

- [Monitoring aset](/apps/management-aset/transaction/monitoring-aset/) — pemeriksaan fisik aset
- [Penyusutan: profil, buku, dan matriks](/apps/management-aset/master/depresiasi/) — tempat pengaturan pembulatan sekarang berada
- [Kontrak](/apps/management-aset/arsitektur/kontrak) — aturan sebelum menambah endpoint
