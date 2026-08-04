# Keputusan arsitektur Management Aset V1

## [ ] Batas kepemilikan

| Pemilik | Tanggung jawab |
| --- | --- |
| Core | Organization directory, kalender fiskal, workflow dan inbox lintas aplikasi, audit/attachment/event platform |
| Management Aset | Master dan lifecycle aset, lokasi fisik, transaksi, histori, profil/book penyusutan, perhitungan, dan export penyusutan |
| Backoffice eksternal | COA, financial dimensions, akun debit/kredit, jurnal, dan rekonsiliasi GL |

Management Aset tidak membuat tabel COA, posting profile, voucher, atau jurnal.
Core juga tidak menghitung penyusutan dan tidak membaca database Aset.

## [ ] Organisasi, penerimaan, penggunaan, dan PIC

Satu aset menyimpan fakta berikut secara terpisah. Nilai boleh sama bila
kenyataannya memang sama, tetapi tidak boleh disimpulkan satu dari lainnya.

| Fakta | Field target | Arti |
| --- | --- | --- |
| Entitas legal | `legal_entity_id` | Pemilik legal/aset dan konteks kalender fiskal |
| Unit penerima | `receiving_org_unit_id` | Unit yang menerima atau menampung aset saat serah-terima |
| Penerima | `received_by_user_id` | Orang yang menyelesaikan serah-terima administratif/fisik |
| Unit pengguna | `usage_org_unit_id` | Unit yang memakai aset; dasar unit cost dan alokasi penyusutan |
| Penanggung jawab | `custodian_user_id` | Pengguna internal yang saat ini bertanggung jawab atas aset |

Semua ID organisasi adalah opaque reference dari Core, bukan foreign key lintas
database. Bila penanggung jawab boleh berupa pekerja yang tidak mempunyai akun,
gantikan referensi user dengan kontrak Workforce/Party sebelum fitur tersebut
dirilis; jangan memasukkan nama bebas sebagai pengganti identitas.

Perubahan unit pengguna, PIC, atau lokasi menambah baris riwayat bertanggal
efektif. Data aktif pada register hanyalah proyeksi riwayat terakhir; catatan
lama tidak ditimpa.

## [ ] Lokasi fisik milik aplikasi Aset

Lokasi menjawab **di mana** aset berada, bukan siapa yang memakai. Buat master
lokasi sendiri di aplikasi Aset dengan parent-child lokal:

```text
site/kantor/RS -> gedung -> lantai -> ruangan -> area/rak (opsional)
```

Setiap lokasi tenant-owned, dapat dinonaktifkan tanpa menghapus histori, dan
memiliki satu parent atau menjadi root. Alamat/site dapat mengonsumsi kontrak
alamat Core bila tersedia, tetapi organisasi dan lokasi tidak boleh disamakan.

## [ ] Integrasi dan histori

Setiap transaksi dan event tenant-owned membawa `tenant_id`; fakta yang
berdampak akuntansi juga membawa `legal_entity_id`; fakta penggunaan membawa
`usage_org_unit_id` bila relevan. Pertukaran lintas modul memakai REST
versioned atau event outbox/inbox, tidak ada query database lintas modul.

Rujukan: `docs/dev/01a-tenant-and-org-hierarchy.md`,
`docs/dev/04-api-and-integration.md`, dan
`docs/dev/07-reporting-and-replicas.md`.
