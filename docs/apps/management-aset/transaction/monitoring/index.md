# Monitoring dan layar yang belum berisi

Halaman ini untuk developer. Isinya tiga layar yang **sudah dideklarasikan di manifest** tetapi isinya belum selesai — dan kenapa keduanya bukan hal yang sama.

Ini penting karena layar yang terdaftar di manifest **sudah muncul di navigasi Shell** begitu tenant diberi permission-nya. Kalau isinya kosong tanpa penjelasan, pengguna mengira aplikasinya rusak.

## Monitoring aset

`management-aset.monitoring-aset` — layar ringkasan yang membaca `GET /api/v1/aset` lalu menampilkan kode, status hidup, dan nilai perolehan.

Ia **berfungsi**, hanya masih sederhana: belum ada penyaringan, pengelompokan, maupun ringkasan angka. Permission-nya `monitoring-aset.read`, terpisah dari `aset.read` supaya orang bisa diberi akses melihat ringkasan tanpa akses ke register lengkapnya.

Kodenya di `ui/transactions/monitoring-aset/MonitoringPage.tsx`.

## Dua layar setup yang sengaja kosong

`fixed-asset-parameters` dan `fixed-asset-posting-profiles` menampilkan `Empty` dengan penjelasan, bukan form.

| Layar | Kenapa kosong |
| --- | --- |
| **Parameter aset tetap** | Pengaturan pembulatan saat ini disimpan pada Buku penyusutan. Pengaturan lain menunggu kebutuhannya dipastikan |
| **Profil posting aset** | Pemetaan akun menunggu modul Finance. App aset tidak menyimpan akun atau posting apa pun |

Keduanya memakai satu komponen, `ui/fixed-assets-setup/FixedAssetSetupPlaceholderPage.tsx`.

### Kenapa layarnya sudah ada padahal isinya belum

Karena tempatnya di navigasi sudah pasti, dan **kosong yang dijelaskan lebih baik daripada menu yang hilang**. Orang yang mencari "profil posting" akan menemukan layarnya beserta alasan kenapa belum ada isinya, bukan mengira fitur itu tidak pernah direncanakan lalu membuat pemetaan akun sendiri di tempat lain.

Yang penting: **teks kosongnya menyebut alasan dan penggantinya.** Layar kosong tanpa penjelasan adalah cacat; layar kosong yang menerangkan di mana pengaturannya sekarang berada adalah dokumentasi yang muncul tepat di tempat orang mencarinya.

## Kalau Anda mengisi salah satunya

1. Endpoint barunya masuk kontrak dalam perubahan yang sama.
2. Permission-nya sudah ada di manifest — periksa dulu sebelum menambah yang baru.
3. Profil posting menyentuh akun, yang **bukan milik modul ini**. Kalau Finance sudah ada, pemetaannya jadi rujukan ke modul itu, bukan tabel akun di sini.

## Halaman terkait

- [Register aset](/apps/management-aset/transaction/register-aset/) — sumber data monitoring
- [Penyusutan: profil, buku, dan matriks](/apps/management-aset/master/depresiasi/) — tempat pengaturan pembulatan sekarang berada
- [Kontrak](/apps/management-aset/arsitektur/kontrak) — aturan sebelum menambah endpoint
