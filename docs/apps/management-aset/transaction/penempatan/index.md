# Penempatan dan mutasi

Halaman ini untuk developer. Isinya cara aset berpindah unit kerja dan lokasi, serta kenapa riwayatnya tidak pernah ditimpa.

Penempatan menjawab: **pada tanggal tertentu, aset ini ada di mana dan tanggung jawab siapa.**

## Riwayat, bukan keadaan sekarang

Tabel `aset_tr_penempatan_aset` menyimpan **satu baris per perpindahan**, tidak pernah menimpa baris lama. Baris pertama dibuat otomatis saat aset diterima, dengan alasan "Penerimaan aset".

Keadaan sekarang memang juga disalin ke kolom pada aset (`responsible_org_unit_id`, `asset_location_id`, `financial_dimension_org_unit_id`) supaya daftar tidak perlu menelusuri riwayat tiap baris. Tetapi **riwayat yang berwenang**, bukan salinan itu.

Alasannya ada di penyusutan: angka penyusutan dibebankan ke unit kerja yang berlaku **pada periode itu**, bukan unit aset sekarang. Aset yang pindah unit di tengah tahun membebani dua unit berbeda pada periode berbeda — dan itu memang yang diinginkan akuntansi.

Kalau riwayat ditimpa, pembebanan periode lama ikut berubah tiap kali aset pindah, dan laporan yang sudah dicetak jadi tidak cocok lagi.

## Endpoint

| Endpoint | Gunanya |
| --- | --- |
| `POST /api/v1/mutasi-aset` | Menyusun berita acara serah terima; belum memindahkan apa pun |
| `POST /api/v1/mutasi-aset/{id}/selesaikan` | Serah terima terjadi; penempatan setiap aset berpindah |
| `GET /api/v1/aset/{id}/history` | Riwayat penempatan, urut tanggal berlaku |

Menyelesaikan serah terima butuh permission `management-aset.aset.mutate` — terpisah dari `update`, dan terpisah pula dari izin menyusun dokumennya (`management-aset.mutasi-aset.*`). Orang yang boleh mengoreksi data aset belum tentu boleh memindahkannya, dan juru tulis yang menyiapkan berkasnya belum tentu berwenang menyerahkan barangnya.

::: info `POST /api/v1/aset/{id}/penempatan` sudah tidak ada
Endpoint itu dibuang pada 17 September 2026. Ia memindahkan aset tanpa nomor dokumen dan tanpa bukti serah terima, dan ia satu-satunya yang menulis `lifecycle_state = 'in_use'` — nilai yang tidak pernah dibaca logika mana pun. Seluruh pekerjaannya kini dikerjakan [mutasi aset](#endpoint).
:::

## Yang berubah saat mutasi

1. Baris baru di `aset_tr_penempatan_aset` dengan tanggal berlaku dan alasannya.
2. Unit penanggung jawab pada aset ikut berubah.
3. Lokasi ikut berubah kalau disebut.
4. **Dimensi keuangan ikut dihitung ulang** — dari unit yang dipetakan pada lokasi baru, atau kalau tidak ada, dari unit tujuan pada dokumennya.
5. `lifecycle_state` aset **tidak** berubah. Di Dynamics 365, memasang aset pada functional location dan mengubah lifecycle state adalah dua tindakan terpisah; menggabungkannya membuat aset yang dimutasi ke gudang penyimpanan ikut berstatus dipakai.

Poin 4 sering terlewat: memindahkan aset ke lokasi yang dipetakan ke unit lain berarti pembebanan biayanya juga pindah. Lihat [Lokasi aset dan dimensi keuangan](/apps/management-aset/master/lokasi/).

## Aturan yang dijaga

**Aset yang sudah `decommissioned` atau `disposed` tidak bisa dimutasi.** Barang yang sudah dihentikan pemakaiannya tidak berpindah tangan lagi.

**Unit tujuan harus menjadi tanggung jawab pemanggil.** Diperiksa lewat `OrganizationScope::require()`. Tanpa ini, mutasi jadi pintu belakang untuk memindahkan aset ke unit yang tidak boleh diakses.

**Buku penyusutan harus lengkap sebelum mutasi pertama.** Kode memeriksa apakah matriks group × buku sudah terisi dan profilnya bisa dihitung. Kalau belum, mutasi ditolak dengan pesan yang menunjuk matriksnya.

Ini disengaja dan sering ditanyakan: kenapa pemeriksaan penyusutan muncul saat memindahkan barang? Karena mutasi adalah saat aset menjadi `in_use`, dan aset yang dipakai tetapi bukunya belum benar akan menghasilkan penyusutan salah diam-diam. Lebih baik ditolak sekarang, saat masih jelas apa yang kurang.

**Alasan wajib diisi.** Maksimal 250 karakter. Riwayat tanpa alasan tidak menjawab pertanyaan yang biasanya ditanyakan orang saat membacanya.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `AssetController::place()` | Mutasi |
| `AssetController::history()` | Riwayat |
| `AssetController::assertDepreciationReady()` | Pemeriksaan buku sebelum mutasi |
| `ui/transactions/mutasi-aset/MutationPage.tsx` | Layar |

## Halaman terkait

- [Register aset](/apps/management-aset/transaction/register-aset/) — status hidup dan kolom aset
- [Lokasi aset dan dimensi keuangan](/apps/management-aset/master/lokasi/) — pemetaan lokasi ke unit
- [Proses penyusutan](/apps/management-aset/transaction/penyusutan/) — pemakai riwayat ini
