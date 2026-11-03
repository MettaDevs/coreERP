# Bukti pola Kuta 1 dan Mengwi 1

Pemeriksaan dilakukan melalui MCP SQL Server pada 2 November 2026, hanya SELECT metadata, struktur unit, dan agregat katalog. Tidak mengambil identitas/rekam medis pasien, nilai credential, atau menjalankan sinkronisasi maupun perubahan data.

## Target yang benar-benar diperiksa

Koneksi MCP `Puskesmas Dev kuta 1` menunjuk instance lokal MSI dan database `dinkes-simpus-kuta-satu`. Database `dinkes-simpus-mengwi-satu` ditemukan pada instance yang sama dan diperiksa melalui nama database eksplisit. Ini bukan pemeriksaan database remote bertanggal 30102026, Linux TrialFaskes, atau produksi legacy.

## Struktur unit dan data

| Temuan                              | Kuta 1                                                                   | Mengwi 1                                    |
| ----------------------------------- | ------------------------------------------------------------------------ | ------------------------------------------- |
| Induk layanan pada mUnitBisnis      | Unit 2, Puskesmas Kuta Satu.                                             | Unit 2, UPTD Puskesmas Mengwi I.            |
| Baris berlabel Pustu                | 3 unit.                                                                  | 9 unit.                                     |
| Baris IFK                           | Ada unit 1.                                                              | Ada unit 1.                                 |
| Tabel lokal pasien, obat, dan jasa  | mPasien, mBarang, SIMmListJasa adalah USER_TABLE lokal.                  | Tabel yang sama juga USER_TABLE lokal.      |
| Kolom parent/induk pada mUnitBisnis | Tidak ditemukan kolom bernama parent/induk pada metadata yang diperiksa. | Tidak ditemukan pada pemeriksaan yang sama. |

Pola tersebut mendukung interpretasi satu deployment Puskesmas bersama unit Pustu-nya, dengan pasien disimpan lokal per deployment. Namun, flag Pustu dan UnitBisnisID saja bukan relasi parent universal. Import ke Foundation perlu assignment eksplisit; jangan mengunci UnitBisnisID=2 pada desain baru.

Sebagian SIMmSection.UnitBisnisID bernilai NULL dan tidak ada section yang terpetakan langsung ke seluruh ID Pustu pada query agregat tersebut. Karena itu source binding section/lokasi harus ditelusuri sebelum import; tidak menebak semua section NULL sebagai Pustu tertentu.

## Keselarasan katalog yang terukur

| Objek                | Agregat hasil SELECT                                                                                                                                                          |
| -------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| mBarang              | Kuta 18.276 baris; Mengwi 18.308 baris. Seluruh 18.276 Barang_ID Kuta ditemukan di Mengwi dengan Kode_Barang dan Nama_Barang yang sama pada perbandingan tersebut.            |
| SIMmListJasa         | Kuta 1.478 baris; Mengwi 1.393 baris. Ada 1.393 JasaID yang sama; 1.377 mempunyai JasaName sama, sehingga 16 mempunyai nama berbeda pada ID yang sama.                        |
| Scope patient master | Metadata yang dicari menunjukkan NRM pada mPasien; tidak ditemukan kolom UnitBisnisID/Registry/Pusk pada filter metadata tersebut. Tidak membandingkan pasien antar-database. |
| Data jasa lokal      | SIMmListJasaBiaya dan SIMmListJasaSection ada pada kedua database; pricing/penawaran section tidak boleh dianggap identik hanya dari JasaID.                                  |

Ini perbandingan snapshot dengan key dan field tertentu, bukan checksum seluruh schema/data. Perbandingan obat tidak mencakup semua field stok, harga, satuan, dan atribut lain.

Keselarasan tinggi **tidak membuktikan** kedua database memakai tabel fisik yang sama, satu publisher aktif, atau sinkronisasi live yang selalu berhasil. Keduanya mempunyai tabel lokal. User menetapkan target shared master; implementasi baru memerlukan authority, mapping, revision, dan rekonsiliasi yang eksplisit.

## Hal yang tidak disimpulkan

- Tidak mengklaim seluruh stored procedure, trigger, job, atau mekanisme distribusi master sudah ditelusuri.
- Tidak menyimpulkan Pustu berbagi semua lokasi saldo fisik hanya dari tabel unit atau nama database.
- Tidak menyamakan baris IFK dengan authority katalog target tanpa verifikasi kontrak dan writer.
- Tidak menyimpulkan user yang bisa memilih unit otomatis berhak melihat seluruh rekam medis kelompok.
- Tidak mengukur RPS, performa, ukuran baseline database, atau kelayakan legal berbagi data kesehatan.

## Metode yang dapat diulang

1. Verifikasi DB_NAME dan instance melalui koneksi MCP yang dipilih, lalu gunakan nama database eksplisit pada query lintas kedua snapshot.
2. Baca kolom dan jenis objek mUnitBisnis, SIMmSection, mPasien, mBarang, serta SIMmListJasa dari katalog SQL Server.
3. Pilih hanya UnitBisnisID, nama unit, flag Pustu, serta field routing yang aman bila diperlukan. Jangan SELECT seluruh mUnitBisnis karena tabel menyimpan credential.
4. Bandingkan katalog dengan JOIN key dan hasil COUNT/SUM field equality. Laporkan scope perbandingan dan perbedaan, bukan menyimpulkan authority dari kemiripan.
5. Jangan menjadikan penelitian ini izin melakukan sync, copy credential, merge pasien, atau memperbarui data sumber.

Target desain yang memakai bukti ini ada pada [profil kelompok layanan](SERVICE-GROUP-PROFILES.md), [PRD](PRD.md), dan [aturan sharing](ORGANIZATION-AND-SHARING.md).
