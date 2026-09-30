# Analisa gap CoreERP ke Business Central, fase 1

Halaman ini membandingkan **lapisan yang berjalan di belakang layar** CoreERP dengan Business Central
(BC): hal-hal yang tidak terlihat di layar, tetapi dipakai setiap module. Module bisnisnya sendiri
(finance, pengadaan, dan seterusnya) sengaja tidak dibahas di sini.

Semua keadaan CoreERP di halaman ini diperiksa langsung di kode pada 28 September 2026, bukan dari
dokumen. Semua perilaku BC diambil dari source BCApps (lisensi MIT) dan Microsoft Learn; tautannya ada
di bagian [Sumber](#sumber). Butir kerjanya ada di
[TODO analisa gap fase 1](/todo/AnalisaGapCoreErpkeBCPhase1/TODO).

## Pertanyaan yang dijawab halaman ini

1. Lapisan latar apa saja yang BC punya dan CoreERP belum?
2. Mana yang dikerjakan di fase 1, dan mana yang ditunda?
3. Untuk yang dikerjakan: bagaimana BC melakukannya, dan bagaimana bentuknya di CoreERP?

## Cakupan

Nomor di bawah mengikuti daftar gap yang dibahas bersama pemilik produk, supaya rujukannya tetap sama.

| No. | Topik | Fase 1? | Bagian |
| --- | --- | --- | --- |
| 1 | Jejak siapa yang membuat dan mengubah setiap baris | Ya | [Gap 1 dan 6](#gap-1-6) |
| 2 | Pengaman edit bersamaan | Ya | [Gap 2](#gap-2) |
| 3 | Zona waktu dan tanggal kerja pengguna, serta tautan pengguna ke pekerja HR | Ya | [Gap 3](#gap-3) |
| 4 | Retensi data log yang bisa diatur | Ya | [Gap 4](#gap-4) |
| 5 | Klasifikasi data pribadi per kolom | Ya | [Gap 5](#gap-5) |
| 6 | Log perubahan per field dan riwayat per record | Ya | [Gap 1 dan 6](#gap-1-6) |
| 7 | Lampiran dokumen (backend) | Ya, tampilannya di PRD lain | [Gap 7](#gap-7) |
| 8 | Email dan notifikasi | Tidak | [Di luar fase 1](#di-luar-fase-1) |
| 9 | Feature management per tenant | Tidak | [Di luar fase 1](#di-luar-fase-1) |
| 10 | Job latar dan ekspor laporan | Ya, penyempurnaan | [Gap 10](#gap-10) |
| 11 | Webhook langganan umum | Tidak | [Di luar fase 1](#di-luar-fase-1) |
| 12 | Kurs, dimensi, dan agregasi saldo | Tidak, bersama finance | [Di luar fase 1](#di-luar-fase-1) |

## Yang sudah setara, dan tidak perlu disentuh

Supaya tidak ada yang membangun ulang sesuatu yang sudah ada:

- **Number sequence.** Alokasi, reservasi, pool kontinu, nomor yang dipakai ulang, audit, dan job
  pemulihan harian (`number-sequences:recover` di `apps/core/routes/console.php`). Lebih lengkap dari
  No. Series BC.
- **Outbox event dan penyalinan environment.** `CopyEnvironment` menyalin lalu melucuti salinannya,
  dan pengiriman posting finance dijaga `outboundAllowed()` di `apps/core/app/Support/Finance/PostingPusher.php`,
  sehingga sandbox hasil salinan tidak mengirim apa pun ke sistem sungguhan.
- **Aturan SoD** (`sod_rules`, `sod_conflicts`). BC tidak punya; bentuknya mengikuti F&O.
- **Workflow persetujuan, kalender fiskal, jam kerja, satuan ukur, party dan alamat, OpenTelemetry.**
  Party dan alamat sengaja mengikuti bentuk F&O, bukan BC; perbandingannya dan sisa pekerjaannya ada di
  [buku alamat global](/todo/buku-alamat-global/#perbandingan-dengan-business-central).
  Party dan alamat sengaja mengikuti bentuk F&O, bukan BC; perbandingannya dan sisa pekerjaannya ada di
  [buku alamat global](/todo/buku-alamat-global/#perbandingan-dengan-business-central).

Sebagian temuan lama di [layanan platform](/todo/general/04-layanan-platform) sudah tidak berlaku:
workflow, outbox, dan party kini ada. Yang masih berlaku dan dirujuk dari sini: `PLAT-02` (lampiran),
`PLAT-07` (notifikasi), dan `PLAT-08` (job latar).

## Gap 1 dan 6: jejak pembuat dan riwayat perubahan {#gap-1-6}

### Pertanyaan pemilik

Apakah nanti, untuk aset A, bisa dilihat siapa yang pernah mengganti statusnya, siapa yang mengubah
deskripsinya, siapa yang membuatnya, seperti riwayat tugas di aplikasi manajemen proyek?

**Bisa, dan itu butuh dua lapis yang berbeda.**

| Lapis | Menjawab | Biaya |
| --- | --- | --- |
| Kolom jejak di setiap baris | Siapa membuat, kapan; siapa **terakhir** mengubah, kapan | Kecil, menempel di setiap tabel |
| Log perubahan | **Setiap** perubahan: field mana, dari nilai apa ke nilai apa, oleh siapa, kapan | Sedang, hanya untuk tabel dan field yang dipilih |

Riwayat seperti "memindahkan status dari *To Do* ke *Backlog*", "mengubah deskripsi", dan "membuat
tugas ini" adalah isi log perubahan: masing-masing satu baris perubahan field, atau satu baris
penambahan record. Kolom jejak saja tidak cukup, karena ia hanya menyimpan pengubah terakhir.

### Keadaan hari ini

- Tidak ada kolom jejak standar. Hanya sebagian tabel yang mencatat pembuatnya, dengan nama kolom yang
  berbeda-beda (misalnya `integration_clients.created_by_user_id`).
- Tidak ada log perubahan untuk data bisnis. Yang ada adalah jejak khusus: `access_audit_events`,
  `number_sequence_audit_events`, `operator_audit_events`, `workflow_history`, dan status log per
  module seperti `aset_tr_pemeliharaan_aset_status_log`.

### Di BC

**Kolom jejak diberikan platform ke setiap tabel**, tanpa ditulis developer: `SystemId`,
`SystemCreatedAt`, `SystemCreatedBy`, `SystemModifiedAt`, `SystemModifiedBy`, dan `SystemRowVersion`.
`SystemCreatedBy` dan `SystemModifiedBy` berisi ID keamanan pengguna.

**Log perubahan (Change Log) diatur per tabel dan per field.**

- `Change Log Setup (Table)`: untuk penambahan, perubahan, dan penghapusan, masing-masing dipilih
  kosong, *Some Fields*, atau *All Fields*.
- `Change Log Setup (Field)`: bila *Some Fields*, field mana yang dicatat.
- `Change Log Entry`: satu baris per perubahan field, berisi waktu, pengguna, tabel, field, jenis
  perubahan (*Insertion*, *Modification*, *Deletion*), nilai lama, nilai baru, kunci record, dan
  `SystemId` record yang berubah. Kunci sekundernya (tabel + nilai kunci record) yang membuat riwayat
  per record cepat dibaca.

**Penangkapnya ada di lapisan database, bukan di tiap layar.** Setiap penulisan ke tabel mana pun
melewati *global trigger*, dan dari sana Change Log dipanggil. Potongan dari
`GlobalTriggerManagement.Codeunit.al`:

```al
[EventSubscriber(ObjectType::Codeunit, Codeunit::"Global Triggers", 'OnDatabaseModify', '', false, false)]
local procedure OnDatabaseModify(RecRef: RecordRef)
...
begin
    // We don't want to allow anyone to disable change log management in normal execution context
    if GetExecutionContext() = ExecutionContext::Normal then
        ChangeLogMgt.LogModification(RecRef);
```

Tiga pelajaran dari dokumentasi Microsoft:

- **Jangan mencatat semua field.** Pilih *Some Fields* untuk field yang penting, karena log memperlambat
  penulisan dan memperbesar database.
- **Log dimatikan selama upgrade versi**, supaya upgrade tidak membanjiri log.
- **Entri log hanya dihapus lewat retensi**, dengan masa simpan minimum untuk kepatuhan (lihat
  [Gap 4](#gap-4)). BC bahkan mendaftarkan `Change Log Entry` sebagai tabel yang boleh diretensi dengan
  `SystemCreatedAt` sebagai tanggal acuannya.

BC juga punya *Field Monitoring*: pemberitahuan saat field sensitif berubah, misalnya rekening bank
vendor. Bagian itu butuh notifikasi, jadi menunggu [gap 8](#di-luar-fase-1).

### Bagaimana di CoreERP

1. **Kolom jejak standar di setiap tabel tenant**, terisi otomatis: pembuat, waktu buat, pengubah
   terakhir, waktu ubah. Waktu buat dan ubah sudah ada (`created_at`, `updated_at`); yang ditambahkan
   adalah pelakunya. Job latar mengisi pelaku dengan pengguna yang memicu job itu. Sebuah test boundary
   menjaga agar tabel tenant baru tidak lahir tanpa kolom ini, sama seperti `tenant_id` dijaga hari ini.
2. **Log perubahan sebagai layanan Core**, dengan bentuk yang sama dengan BC: setup per tabel dan field
   per tenant, entri yang tidak bisa diubah, dan kunci yang membuat riwayat per record cepat dibaca.
3. **Penangkap di lapisan database**, bukan di event Eloquent. Update lewat query builder
   (`DB::table()->update()`, `Model::query()->update()`) tidak melewati event model, jadi penangkap di
   level model akan bolong diam-diam. Trigger PostgreSQL menangkap semua penulisan seperti global
   trigger BC. Pelakunya dikirim lewat variabel sesi `coreerp.user_id` (`set_config(..., false)`) yang dibaca trigger: tingkat sesi, bukan per transaksi, karena kebanyakan penulisan tidak dibungkus transaksi. Job antrean memasang dan melepasnya per job (`AuditActor::runAs`).
   Karena trigger ikut migration, ia terpasang di setiap database tenant. BC sendiri menangkap di
   lapisan platform, bukan SQL: platform bertanya ke Change Log tabel mana yang perlu dicatat
   (`GetDatabaseTableTriggerSetup`), lalu memanggil `OnDatabaseModify` untuk setiap penulisan. Itu cukup
   di BC karena tidak ada jalan menulis data selain lewat server BC. Di Laravel jalan pintasnya ada
   (query builder, SQL mentah, job), sehingga padanan yang setara adalah trigger database. Trigger juga
   hanya mencatat tabel dan field yang dipilih di setup, seperti BC.
4. **Endpoint riwayat per record, dan komponen riwayat di Shell** yang menampilkan entri itu seperti
   gambar yang dicontohkan pemilik: siapa, melakukan apa, dari nilai apa ke nilai apa, kapan. Nama
   pengguna dan unit kerja ditampilkan sebagai nama, bukan ID.
5. **Status log yang sudah ada di module tetap hidup.** Riwayat per record boleh menggabungkannya, tetapi
   tidak menggantikannya pada fase ini.

Log perubahan harus lolos load test module seperti fitur lain. Trigger menambah biaya pada setiap
penulisan ke tabel yang dicatat, dan biaya itu hanya terlihat pada beban serentak.

### Yang sudah dibangun (area 1 dan 2)

- **Kolom jejak** `created_by_user_id`/`updated_by_user_id` di setiap tabel tenant, diisi trigger
  `coreerp_stamp_audit_actor`. Pelakunya dipasang saat pengguna terpasang di guard (event
  `Authenticated`), dan `RunReportExport` memakai pemesan ekspornya. Hanya id angka yang dipasang.
- **Tiga tabel log** `change_log_setup_tables`, `change_log_setup_fields`, dan `change_log_entries`.
  Pelaku entri adalah `created_by_user_id`-nya sendiri.
- **Bawaan dikirim pemilik tabelnya.** Module mendaftarkan tabel dan field yang layak dicatat beserta
  nama tampilannya lewat `ChangeLogDefaults::register()` dari migration, sehingga sampai juga ke tenant
  lama. Bawaannya baris ber-`tenant_id` kosong. Tenant yang menyimpan setelannya sendiri di **Pengaturan
  → Riwayat perubahan** mendapat salinan lengkap yang menggantikan bawaan untuk tabel itu. Tenant hanya
  memilih dicatat atau tidak per field; mode `all` dipakai tabel yang selalu dicatat.
- **Selalu dicatat**, seperti `IsAlwaysLoggedTable` BC: setup log sendiri, peran dan penugasannya,
  duty dan privilege milik tenant beserta isinya, pewarisan peran, cakupan data penugasan, aturan
  penugasan otomatis, dan aturan SoD. Daftarnya ada di fungsi `coreerp_log_change`. Baris bertenant
  kosong (katalog produk) tidak dicatat.
- **Tabel akses tanpa `tenant_id` tetap tercatat.** `role_assignments`, `security_role_duties`,
  `security_duty_privileges`, dan `security_privilege_permissions` tidak punya kolom tenant; trigger
  membaca tenantnya dari peran, duty, atau privilege yang dirujuk. Baris yang ikut terhapus karena
  induknya dihapus tidak tercatat sendiri; penghapusan induknya yang tercatat.
- **Trigger keluar lebih dulu** bila log tidak menyala, sebelum baris diubah menjadi JSON. Kunci record
  diambil dari primary key tabelnya, jadi tabel berkunci gabungan tetap tercatat kuncinya.
- **Riwayat dibuka lewat rute pemilik record.** Module memeriksa hak dan cakupan organisasi atas record
  itu dengan aturannya sendiri, lalu membaca lewat kontrak `ChangeHistory`; riwayat tidak pernah lebih
  terbuka daripada record-nya. Contohnya `GET aset/{id}/riwayat-perubahan` di modul aset. Rute Core
  `GET api/v1/change-log/{tabel}/{id}` membaca record apa pun di tenant dan karena itu berizin admin
  `core.change-log.read`, seperti membuka Change Log Entries di BC.
- **Nilai tampil sebagai nama.** Module mendaftarkan penerjemah (`ChangeLogValueResolvers`) saat boot:
  lokasi, kondisi, unit kerja, dan label status aset; akun pengguna yang ditautkan ke pekerja.
- **Komponen `ChangeHistory` di Shell**, dipakai halaman aset: linimasa per penyimpanan, bernama pelaku,
  dengan nilai lama dan baru.
- **Klien integrasi tercatat atas namanya sendiri** (keputusan pemilik 29 September 2026, opsi A): setiap
  klien punya akun aplikasi, seperti kolom `User ID` pada Microsoft Entra Application di BC. Akun itu
  tidak pernah dapat masuk dan bukan anggota tenant. Rinciannya di
  [feed posting finance](/dev/34-feed-posting-finance#klien-integrasi).
- **Load test dengan log aktif lulus**: penjenuhan `receipt-posting.js` 1000 VU dan 128 tenant, 0
  pelanggaran, 0 error 5xx, dan kedua `verify.sql` bernilai 0. Oracle-nya ditambah pemeriksaan bahwa
  pelaku entri adalah anggota tenant entrinya (atau akun aplikasi klien integrasinya) dan setiap aset
  punya entri pembuatan di tenantnya sendiri.
  Biaya log terhadap latensi tidak terukur di atas selisih antar-run. Rinciannya di
  `apps/core/loadtest/README.md`.

Yang belum tercakup, dan sengaja ditulis supaya tidak dianggap sudah:

- Jalur mesin lain masih tercatat sebagai sistem: kredensial app `internal/v1` (tidak punya pemanggil lagi
  di repo), token admin.erp (`POST internal/v1/tenants` tidak membawa operatornya), dan perintah terjadwal.

- Kolom pembuat lama tetap ada di samping kolom jejak: `invitation_codes.created_by`,
  `report_exports.user_id`, dan `finance_reference_account_imports.imported_by_user_id`.
- Layar pekerja HR belum ada, jadi riwayat pekerja baru terbaca lewat rute admin.
- Masa simpan entri log diatur layanan retensi ([Gap 4](#gap-4)); bawaannya mati sampai tenant menyalakannya.

## Gap 2: pengaman edit bersamaan {#gap-2}

### Keadaan hari ini

Baru ada di dokumen transaksi module aset: perencanaan, permintaan pengadaan, work order, mutasi, dan
penerimaan. Tabelnya membawa kolom `version`, form mengirimnya kembali saat menyimpan, update-nya
bersyarat pada versi itu, dan versi basi dijawab 409, misalnya "Rencana telah berubah. Muat ulang lalu
coba lagi."

Yang belum:

- Register aset (`aset_tr_aset`), master aset, tabel tenant Core, dan module HR tidak punya versi baris.
  Di sana, dua orang yang membuka record yang sama lalu menyimpan bergantian: penyimpanan terakhir yang
  menang, dan perubahan orang pertama hilang tanpa pesan.
- Setiap controller aset menulis penolakannya sendiri. Sebagian memulangkan kode `stale_version`,
  sebagian hanya `abort` 409 dengan pesan bebas.
- API belum memakai ETag atau `If-Match`.

### Di BC

- Setiap tabel punya `SystemRowVersion` (rowversion SQL), hanya bisa dibaca dari kode.
- API mewajibkan header `If-Match` berisi ETag pada `PATCH`; bila ETag tidak cocok dengan versi
  sekarang, record tidak diubah.
- Di layar, BC menolak penyimpanan yang datanya sudah basi. Ini perilaku platform, dan dapat dicoba di
  trial dengan membuka record yang sama di dua tab.

### Bagaimana di CoreERP

1. Setiap tabel tenant membawa versi baris yang naik setiap kali baris berubah.
2. Endpoint baca memulangkan versi itu. Form membawanya kembali saat menyimpan, dan API menerimanya
   lewat `If-Match`.
3. Penyimpanan memakai update bersyarat (versi harus sama). Bila tidak sama, jawabannya 409 dengan
   pesan yang menjelaskan dampaknya bagi pengguna, misalnya "Data ini sudah diubah orang lain sejak
   kamu membukanya. Muat ulang untuk melihat perubahan terbaru."
4. Proses berlangkah banyak di dalam satu transaksi tetap memakai kunci baris (`SELECT ... FOR UPDATE`);
   versi baris tidak menggantikannya.

### Yang sudah dibangun (area 3)

- **Kolom `version` di setiap tabel tenant**, Core dan module, dinaikkan trigger `coreerp_bump_row_version`
  pada setiap UPDATE (K-11, K-12). Nilai yang ditulis kode diabaikan. Pengecualiannya
  `core_module_installations`, yang kolom `version`-nya nomor rilis module.
- **Kolom aktivitas mesin tidak menaikkan versi** (keputusan pemilik 29 September 2026): `last_pulled_at`
  dan `last_used_at` klien integrasi serta `last_used_at` kredensial app, diteruskan sebagai argumen
  trigger. Tanpa itu admin tidak pernah bisa menyimpan klien yang sedang aktif pull.
- **Satu helper `RowVersion`.** `claim()` adalah update bersyarat yang juga memegang kunci baris, jadi
  endpoint yang hanya mengganti baris anak tetap aman. `claimIfExists()` untuk setelan yang baru lahir saat
  pertama disimpan: layar mengirim versi 0, dan kunci advisory transaksi mengurutkan dua penyimpanan pertama
  yang bersamaan. Jawabannya 409 `stale_version`, atau 428 `version_required` tanpa versi. Form Inertia
  menerimanya sebagai galat field `version`, ditampilkan sebagai toast dengan tombol **Muat ulang**.
- **Semua endpoint ubah dan arsip** di Core dan module aset (K-13), termasuk pengganti baris anak dan
  relasi yang disunting dari dua arah (jenis aset ↔ jenis pekerjaan: sisi seberang ikut dinaikkan versinya).
  Kontrol dokumen aset yang dulu menulis penolakannya sendiri beralih ke helper ini.
- **Skenario load test aset** membaca dan mengirim versi; di profil perlombaan 409 adalah hasil sah.
- **Load test lulus** (30 September 2026): penjenuhan 1000 VU dan perlombaan koreksi tanpa pelanggaran,
  kedua `verify.sql` bernilai 0, dan biaya trigger versi tidak terukur di atas selisih antar-run. Rinciannya
  di `apps/core/loadtest/README.md`.

Yang belum tercakup:

- Satu penyimpanan biasanya menaikkan versi dua kali (klaim, lalu penulisan sesungguhnya). Angkanya tidak
  bermakna selain "berbeda"; klien memakai versi dari jawaban terakhir.
- `PUT validasi-status-work-order` dan parameter workflow tidak punya baris induk untuk diklaim.

## Gap 3: zona waktu, tanggal kerja, dan pengguna ke pekerja HR {#gap-3}

Dua hal berbeda yang sempat tertukar dalam pembahasan: **tanggal kerja** bukan **jadwal kerja**. Zona
waktu dibahas lebih dulu, karena "hari ini" pada tanggal kerja baru benar bila dihitung menurut zona
pengguna.

### Zona waktu pengguna

**Keadaan hari ini tidak konsisten.** Tiga lapis memakai zona yang berbeda:

| Lapis | Zona | Contoh |
| --- | --- | --- |
| Server | UTC | `'timezone' => 'UTC'` di `apps/core/config/app.php` |
| Layar | Umumnya zona peramban | `toLocaleString` dan `Intl.DateTimeFormat` tanpa `timeZone` di `apps/core/resources/js/lib/reports.ts` dan `apps/core/resources/js/pages/settings/finance-postings.tsx` |
| Cetakan module | UTC | Waktu cetak (`dicetak_pada`), nama berkas, dan periode bawaan ditulis dengan `now()->format(...)` di `modules/apperp/management-aset/src/Reporting/Definitions/` |

Akibatnya laporan yang dicetak pukul 09.00 WIB tertulis 02.00. Form yang mengisi "hari ini" sendiri
memakai `new Date().toISOString().slice(0, 10)`, yaitu tanggal UTC, sehingga antara pukul 00.00 dan
07.00 WIB form terisi tanggal kemarin. Pola itu ada di layar Core maupun module; cari
`toISOString().slice(0, 10)` di `apps/core/resources/js` dan `modules/*/*/ui`.

**Di BC**, *Time Zone* ada di **My Settings**, satu halaman dengan *Work Date*, dan keduanya diterapkan
ke sesi oleh codeunit yang sama. Dari `UserSettingsImpl.Codeunit.al`:

```al
if OldUserSettings."Time Zone" <> NewUserSettings."Time Zone" then begin
    ShouldRefreshSession := true;
    sessionSetting.TimeZone := NewUserSettings."Time Zone";
end;
...
if OldUserSettings."Work Date" <> NewUserSettings."Work Date" then
    WorkDate(NewUserSettings."Work Date");
```

Menurut halaman *Change basic settings*, zona waktu diisi dari alamat perusahaan saat pengguna pertama
kali masuk, dan pengguna menggantinya bila tidak cocok dengan lokasinya.

**Di F&O**, tanggal-waktu gabungan (`utcdatetime`) disimpan dalam UTC, sedangkan field tanggal saja
tidak punya zona. Zona pilihan pengguna diatur di **User options** dan dipakai untuk menampilkan
tanggal-waktu gabungan; nilai awalnya dari locale Windows di komputer pengguna. Entitas legal juga punya
zona sendiri, yang dibaca lewat `DateTimeUtil::getCompanyTimeZone()`.

**CoreERP.** Pemilik menyetujui bentuknya pada 28 September 2026:

1. Jam tidak pernah diambil dari perangkat pengguna. Waktu selalu dari server, dalam UTC.
2. Setiap pengguna punya setelan **Zona waktu** di **My Profile**, seperti My Settings di BC dan User
   options di F&O.
3. Layar dan cetakan memakai setelan yang sama. Cetakan menuliskan zonanya, misalnya
   "28/09/2026 14:05 WITA".
4. Module mengirim waktu UTC bertipe `datetime`, dan Core yang memformatnya, melanjutkan format bertipe
   yang sudah ada di `App\Support\Reporting\ValueFormat` dan `ValueFormats`.
5. Konteks laporan dan konteks permintaan membawa zona pengguna, sehingga "hari ini" di module, misalnya
   periode bawaan dan nama berkas, mengikuti zona itu.

Nilai bawaan zona pengguna diputuskan di K-10 pada 28 September 2026: pilihan 1.

- **Pilihan 1, dipilih:** zona waktu menjadi setelan entitas legal, seperti `getCompanyTimeZone` di F&O.
  Pengguna yang belum mengisi setelannya mengikuti entitas legal yang sedang aktif.
- **Pilihan 2, tidak dipilih:** alamat entitas legal diubah menjadi pilihan wilayah, lalu zonanya dihitung otomatis,
  mendekati BC yang mengisi zona dari alamat perusahaan. Hari ini alamat entitas legal berupa teks bebas
  (`province` dan `city` di `postal_addresses`, ditulis
  `apps/core/app/Support/AddressBook/OrganizationAddressBook.php`), sedangkan
  `apps/core/app/Services/AddressHierarchy/TimezoneResolverService.php` butuh ID wilayah dan sengaja
  tidak mencocokkan nama. Pilihan ini ikut mengubah buku alamat semua pihak.

Bentuk kolomnya sudah punya preseden di Core: `sites.timezone`, string 40 karakter dengan bawaan
`Asia/Jakarta`.

### Tanggal kerja (WorkDate)

**Di BC**, setiap pengguna punya *Work Date* di **My Settings**. Tanggal itu menjadi tanggal posting
bawaan, dan di field tanggal mana pun pengguna cukup mengetik `w` untuk mengisinya. Gunanya untuk
mengerjakan transaksi tanggal lain (misalnya tutup bulan) tanpa mengetik ulang tanggal di setiap baris.
Contoh dari `SetUpNewLine` di `GenJournalLine.Table.al`: baris baru pada batch jurnal yang masih kosong
memakai tanggal kerja, sedangkan bila batch sudah berisi, baris baru menyalin tanggal posting baris
terakhir.

```al
if GenJnlLine.FindFirst() then begin
    "Posting Date" := LastGenJnlLine."Posting Date";
    "Document Date" := LastGenJnlLine."Posting Date";
    ...
end else begin
    "Posting Date" := WorkDate();
    "Document Date" := WorkDate();
```

BC juga punya *Allow Posting From/To* per pengguna di `User Setup`. Itu batas periode posting, dan
baru relevan saat finance dibangun ([gap 12](#di-luar-fase-1)).

Perilaku BC yang perlu ditiru, dari halaman *Change basic settings*:

- **Perubahannya sementara.** Setelah pengguna keluar atau pindah company, tanggal kerja kembali ke
  bawaannya, yaitu hari ini.
- **Selama tanggal kerja bukan hari ini, layar yang bisa diedit memberi tanda.** Ada pengingat di atas
  halaman dengan tautan ke **My Settings**, yang bisa ditutup untuk sisa sesi. Setelah ditutup, tanggal
  kerja tetap tampil di judul halaman.

**CoreERP** belum punya. Pemilik memutuskan (K-04) tanggal kerja masuk fase 1 dan diisi di halaman
**My Profile** (`/settings/profile`). Bentuknya:

1. Tanggal kerja per pengguna per sesi, bawaannya hari ini menurut zona waktu pengguna, dihitung dari jam
   server. Nilainya kembali ke hari ini saat pengguna login ulang, atau pindah tenant atau legal entity
   (padanan pindah company di BC).
2. Diisi di **My Profile**.
3. Menjadi tanggal bawaan di form transaksi, menggantikan "hari ini" yang sekarang dihitung sendiri di
   tiap form dalam UTC.
4. Selama tanggal kerja bukan hari ini, Shell menampilkan pengingat yang mengarah ke **My Profile** dan
   bisa ditutup untuk sisa sesi. Setelah ditutup, tanggal kerja tetap terlihat, seperti di judul halaman
   BC.

### Yang sudah dibangun (zona waktu dan tanggal kerja)

- **Zona waktu pengguna** di My Profile (`users.timezone`). Kosong berarti ikut zona entitas legal aktif
  (`legal_entities.timezone`, diisi di layar Organisasi, bawaan `Asia/Jakarta`), lalu zona aplikasi.
  Penghitungnya `App\Support\UserClock`.
- **"Hari ini" dari jam server** menurut zona itu, ikut setiap halaman sebagai `clock.today`. Layar yang
  dulu mengisi tanggal dengan `toISOString()` atau jam peramban kini memakai nilai ini.
- **Tanggal kerja** di My Profile, disimpan di sesi. Ia kembali ke hari ini saat login ulang atau pindah
  tenant atau entitas legal, dan tanggal kerja yang sama dengan hari ini disimpan sebagai "hari ini"
  supaya besok ikut bergeser. Form penerimaan, mutasi, dan perencanaan aset memakainya sebagai tanggal
  bawaan.
- **Pengingat di Shell** selama tanggal kerja bukan hari ini, dengan tautan ke My Profile dan tombol
  "Pakai hari ini". Setelah ditutup, tanggal kerja tetap terlihat di header.

- **Jam di layar menurut zona pengguna.** Layar Core dan Shell (tray ekspor, lonceng notifikasi, riwayat
  perubahan, daftar ekspor, layout laporan, retensi, akun dan pantau posting finance, klien integrasi,
  identitas operator) memformat waktu lewat `useDateTimeFormat()`, yang membaca `clock.timezone`. Zona
  perangkat tidak dipakai lagi, dan waktu tanpa offset dari server dibaca sebagai UTC.
- **Jam di cetakan menurut zona pengguna, beserta zonanya.** Tipe `datetime` di `ValueFormat`: module
  mengirim waktu UTC, Core menulisnya "28/09/2026 00:30 WIB" di Word, PDF, dan pratinjau, dan sebagai
  tanggal-jam Excel dengan nama zona di format selnya. Zona Indonesia ditulis WIB, WITA, atau WIT; zona
  lain ditulis selisihnya dari UTC pada saat itu, misalnya "UTC+09:00", karena singkatan seperti IST atau
  CST dipakai lebih dari satu zona. Tanggal tanpa jam (`date`) tidak digeser.
- **Zona di konteks module.** Konteks laporan membawa `timezone`, dihitung dari pengguna dan entitas legal
  catatan ekspor (worker tidak punya sesi); `KonteksPermintaan::timezone()` menjawab hal yang sama di rute
  module. Laporan aset memakainya untuk periode bawaan, nama berkas, dan batas hari filter tanggal, dan
  mengirim waktu cetak, waktu dibuat, serta waktu mulai dan selesai aktual work order sebagai `datetime`.

Yang belum: jadwal work order (`diharapkan_*`, `dijadwalkan_*`) diketik pengguna lewat isian tanggal-jam
dan disimpan persis seperti diketik, tanpa zona. Jam itu ditampilkan dan dicetak apa adanya, tanpa nama
zona. Menjadikannya waktu UTC berarti mengubah isian dan penyimpanannya di module aset; keputusan itu
belum diambil.

### Pengguna, pekerja HR, dan jadwal kerja

Pertanyaan pemilik: apakah pengguna bisa dihubungkan ke jadwal kerja di Core, atau menunggu module HR?
Bagaimana setiap pengguna punya entri di HR, padahal akunnya datang dari SSO?

**Di BC**, penautannya manual oleh admin, dan tidak setiap pengguna menjadi karyawan:

- `User Setup` menautkan pengguna ke salesperson, dan app Expense menambahkan `Employee No.`.
- `Resource` punya `Time Sheet Owner User ID`, dan `Employee` punya `Resource No.`: rantai pengguna →
  resource → karyawan yang dipakai timesheet.

**Di F&O**, pengguna diimpor dari Microsoft Entra ID, lalu admin *mengaitkan pengguna dengan Person*
bila perlu. Akunnya dari identitas luar, dan tautannya ke orang dibuat terpisah.

**CoreERP sudah memakai bentuk itu.** `hr_workers.core_membership_id` menunjuk keanggotaan tenant
(pengguna SSO di tenant itu), boleh kosong, dan unik per tenant di antara pekerja yang belum diarsipkan. Alasannya tertulis di
`modules/apperp/human-resources/src/Models/Worker.php`: pekerja yang tidak pernah membuka aplikasi tetap
harus tercatat, dan menuntut akun untuk setiap orang akan menyamakan daftar pekerja dengan daftar
pengguna. Tautannya dipilih admin HR di form pekerja dan divalidasi ke Core
(`HumanResourcesController`). Jadi tidak ada yang perlu ditunggu, dan tidak perlu membuat entri HR
otomatis untuk setiap pengguna.

Yang bisa disempurnakan: usulan tautan berdasarkan kecocokan email saat admin mengisi form, dan
menampilkan pekerja yang tertaut di layar pengguna.

**Jadwal kerja** (`working_time_templates`, `working_time_lines`) sudah ada di Core, per tenant dan
legal entity, tetapi belum ditautkan ke siapa pun. Di BC, kalender dasar ditautkan ke perusahaan dan
lokasi, sedangkan kapasitas orang diatur di resource, oleh bagian yang membutuhkannya. Tautan pekerja →
template jam kerja karena itu milik module HR, dibuat saat absensi atau timesheet dibangun, bukan di
tabel pengguna Core.

### Yang sudah dibangun (area 9)

- **Tautan lewat API saja (K-23).** Module HR belum punya layar; menu Pekerja tetap halaman pengganti
  "Layar ini belum dipindah". Usulan dan penyimpanan tautan tersedia untuk layar yang kelak dibangun dan
  untuk pemanggil API.
- **Usulan tautan dari email.** `GET /api/modules/human-resources/v1/core-members?email=` memulangkan akun
  tenant yang sama yang emailnya sama persis dengan email pekerja (tanpa membedakan huruf besar). Setiap akun
  membawa `linked_worker_id`, sehingga akun yang sudah dipegang pekerja lain bisa dilewatkan. Yang
  memutuskan tetap pengguna. `GET workers` kini ikut membawa nama dan email akun yang tertaut (`account`).
- **Satu akun, satu pekerja.** `hr_workers_core_membership_active_unique` menggantikan indeks unik lama,
  kini parsial untuk pekerja yang belum diarsipkan. Controller menolaknya lebih dulu dengan 422, dan
  penyimpanan bersamaan yang lolos pemeriksaan tertahan indeks yang sama, juga dijawab 422.
- **Menautkan dan melepas** lewat `PATCH /api/modules/human-resources/v1/workers/{worker}/core-membership`
  dengan versi baris (area 3), memakai hak yang sudah ada: `human-resources.workers.create` dan
  `human-resources.core-account-link.invoke`, untuk pekerja yang boleh dilihat pengguna. Akun tenant lain
  ditolak 422; sebelumnya akun yang tidak ditemukan saat menambah pekerja berakhir 500.
- **Pekerja di layar anggota Core.** Kolom **Pekerja** di Identity & access → Anggota menampilkan nama dan
  nomor pegawai. Core bertanya lewat kontrak baru `LinkedWorkerResolvers`
  (`apps/core/app/Support/Modules/Contracts/`), yang diisi module HR; hanya module yang terpasang untuk
  tenant itu yang ditanya, dan kolomnya tidak tampil bila tidak ada.

Tidak ada kolom baru, jadi klasifikasi data (area 5) tidak berubah: `core_membership_id` sudah
diklasifikasi `EndUserPseudonymousIdentifiers`. Test-nya di
`modules/apperp/human-resources/tests/Feature/PenyaringanTenantTest.php`.

## Gap 4: retensi data log {#gap-4}

### Keadaan hari ini

Retensi sudah ada, tetapi ditanam per perintah dan dibaca dari config global:

| Data | Diatur oleh | Dijalankan oleh |
| --- | --- | --- |
| Audit number sequence dan pool yang sudah dikonfirmasi | `coreerp.audit_retention_days`, `coreerp.confirmed_pool_retention_days` | `RecoverNumberSequenceReservations` |
| Hasil ekspor laporan | `reporting.retention_days` | `RunReportExport` (mengisi `expires_at`), `PurgeReportExports` |

Keduanya menghapus secara fisik. Itu sah, karena yang dihapus adalah log dan berkas teknis, bukan data
bisnis. Aturan "tidak ada baris yang dihapus fisik" tetap berlaku untuk data bisnis.

### Di BC

- **Tabel yang boleh diretensi didaftarkan oleh kode**, lengkap dengan kolom tanggal acuannya dan masa
  simpan minimum (`AddAllowedTable(TableId, DefaultDateFieldNo, MandatoryMinRetenDays)`). Tabel yang
  tidak didaftarkan tidak bisa diberi retensi.
- **Admin tenant memilih masa simpan per tabel**, tidak boleh lebih pendek dari minimumnya.
- **Mengaktifkan kebijakan membuat job queue** yang menerapkannya secara berkala. Setiap penerapan
  dicatat di log.

### Bagaimana di CoreERP

1. Daftar tabel yang boleh diretensi ditulis di kode Core dan module: tabel, kolom tanggal, masa simpan
   minimum, dan masa simpan bawaan.
2. **Nilai bawaannya persis seperti sekarang**, diambil dari config yang disebut di atas. Tenant yang
   tidak mengatur apa pun mendapat perilaku yang sama dengan hari ini.
3. Setelan per tenant boleh mengubah masa simpan, tidak lebih pendek dari minimum.
4. Satu job terjadwal menerapkan semua kebijakan dan mencatat hasilnya. Perintah yang sekarang
   menghapus sendiri beralih memakai layanan ini.
5. Tabel data bisnis tidak pernah masuk daftar.

### Yang sudah dibangun (area 4)

- **Daftar tabel yang boleh diretensi** ada di `App\Support\Retention\RetentionPolicies`: kode, keterangan
  di layar, tabel, kolom tanggal acuan, minimum, dan bawaan. Bawaannya dibaca dari config saat dipakai, dan
  masa simpan efektif tidak pernah di bawah minimum walau config diisi lebih kecil.

  | Kebijakan | Tabel dan tanggal acuan | Bawaan | Minimum |
  | --- | --- | --- | --- |
  | `number_sequence_audit` | `number_sequence_audit_events`, `occurred_at` | `coreerp.audit_retention_days` (400) | 365 |
  | `number_sequence_confirmed_pool` | `number_sequence_continuous_pool` berstatus `confirmed`, `updated_at` | `coreerp.confirmed_pool_retention_days` (30) | 7 |
  | `report_exports` | `report_exports`, `created_at`; berkasnya ikut dihapus | `reporting.retention_days` (7) | 1 |
  | `change_log_access` | `change_log_entries` milik tabel yang selalu dicatat, `changed_at` | mati | 365 |
  | `change_log_other` | `change_log_entries` selain itu, `changed_at` | mati | 28 |
  | `retention_policy_log` | `retention_policy_log_entries`, `created_at` | `coreerp.retention_log_retention_days` (365) | 28 |

  Kedua tabel number sequence tidak punya `tenant_id`; tenantnya dibaca dari `sequence_id` ke
  `tenant_number_sequences`. `change_log_entries` memakai `changed_at` karena tabel itu tidak punya
  `created_at`. Daftar tabel yang selalu dicatat ada sekali di `AlwaysLoggedTables`, dan test
  membandingkannya dengan daftar di fungsi SQL `coreerp_log_change`.
- **Setelan per tenant** di `retention_policy_setups` (unik per tenant dan kebijakan). Tenant tanpa baris
  memakai bawaan. Kebijakan yang punya bawaan selalu berjalan dan tenant hanya memilih masa simpan;
  kebijakan tanpa bawaan (riwayat perubahan) mati sampai tenant menyalakannya. Masa simpan di bawah
  minimum ditolak dengan 422.
- **Satu layanan**, `RetentionService::apply()`, menghapus per tenant dalam kelompok kecil. Penghapusannya
  fisik dan itu sah di sini: yang dihapus adalah log dan berkas teknis. Tabel data bisnis tidak pernah
  didaftarkan. Satu perintah terjadwal, `retention:apply`, berjalan harian; `reporting:purge-exports` tetap
  tiap jam tetapi memanggil layanan yang sama untuk `report_exports`, dan daftar ekspor memanggilnya untuk
  tenant yang sedang membaca. `number-sequences:recover` tidak lagi menghapus berdasarkan umur; ia hanya
  membuang blok alokasi yang habis, karena itu soal struktur, bukan umur.
- **Log penerapan** di `retention_policy_log_entries`: per tenant dan kebijakan, jumlah baris terhapus,
  batas waktu yang dipakai, status, dan pesan. Ditulis hanya bila ada baris terhapus atau penghapusan
  gagal, dan tabel ini sendiri diretensi.
- **Layar Pengaturan → Retensi data** dengan permission `core.retention.read` dan `core.retention.update`,
  lewat rantai entry point, privilege, dan duty (`core.retention.inquire`, `core.retention.manage`) yang
  didaftarkan migration katalog keamanan. Owner memegang keduanya.

## Gap 5: klasifikasi data pribadi {#gap-5}

### Keadaan hari ini

Tidak ada penanda apa pun untuk kolom yang berisi data pribadi. Untuk sistem yang menyimpan data pasien
dan karyawan, ini celah terhadap UU PDP.

### Di BC

BC punya dua lapis.

**Lapis developer: properti `DataClassification` di setiap field.** Nilainya `CustomerContent`,
`EndUserIdentifiableInformation`, `EndUserPseudonymousIdentifiers`, `OrganizationIdentifiableInformation`,
`AccountData`, `SystemMetadata`, dan `ToBeClassified` untuk field yang belum diklasifikasi. Klasifikasi
ditulis di tingkat tabel sebagai bawaan, lalu field yang berbeda menimpanya. Dari
`ChangeLogEntry.Table.al`, tabelnya `CustomerContent`, tetapi field pengguna menimpanya:

```al
table 405 "Change Log Entry"
{
    DataClassification = CustomerContent;
    ...
        field(4; "User ID"; Code[50])
        {
            Caption = 'User ID';
            DataClassification = EndUserIdentifiableInformation;
            TableRelation = User."User Name";
```

Aturan ini dipaksa mesin, bukan diserahkan ke reviewer. AppSourceCop **AS0016**: field biasa wajib
memakai `DataClassification` dengan nilai selain `ToBeClassified`. FlowField otomatis `SystemMetadata`.

**Lapis admin tenant: tingkat sensitivitas per field.** Nilainya *Sensitive* (termasuk data kesehatan),
*Personal*, dan *Normal*, diisi lewat Data Classification Worksheet (bisa massal lewat Excel). Field
sensitif bisa disamarkan (*masking*) di layar.

**Klasifikasi juga menjaga telemetri.** Event telemetri dengan `DataClassification` selain
`SystemMetadata` tidak dikirim ke Application Insights.

### Bagaimana di CoreERP

Fase 1 mengambil lapis developer dan aturan telemetrinya. Lapis admin (sensitivitas per tenant dan
masking) butuh layar, jadi masuk fase berikutnya.

1. Satu enum dengan nilai yang sama dengan BC.
2. Setiap model tenant menyatakan klasifikasi bawaan tabelnya, dan kolom yang berbeda menimpanya. Tabel
   tenant tanpa model dinyatakan di satu registry per pemilik: satu untuk Core, satu per module (K-19).
3. Satu test gagal bila ada tabel tenant tanpa klasifikasi, sebagai padanan AS0016.
4. Telemetri mengikuti K-18: SigNoz tetap menerima laporan kesalahan utuh, sedangkan notifikasi Discord
   hanya membawa data teknis. Ini menyimpang dari BC, yang hanya mengirim data `SystemMetadata` ke
   telemetri. Alasannya letak datanya: Application Insights di BC adalah layanan di luar tenant, sedangkan
   SigNoz berjalan di server kita sendiri dan laporan utuhnya dibutuhkan untuk menelusuri kesalahan.
   Discord pihak ketiga, jadi aturan BC berlaku di sana.
5. Semua tabel tenant yang sudah ada diklasifikasikan. Kolom nama, email, telepon, NIK, NPWP, tanggal
   lahir, alamat, dan catatan medis diklasifikasikan eksplisit, bukan mengandalkan bawaan tabel.

Bentuk deklarasinya ditetapkan di K-06:

```php
#[DataClassification(DataClass::CustomerContent)]
final class Worker extends Model
{
    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'name' => DataClass::EndUserIdentifiableInformation,
        'email' => DataClass::EndUserIdentifiableInformation,
    ];
}
```

### Yang sudah dibangun (area 5)

- **Enum dan deklarasi** di `App\Support\Modules\Contracts`, supaya module dapat memakainya: `DataClass`
  (tujuh nilai BC), atribut `DataClassification` untuk bawaan tabel, konstanta `COLUMN_CLASSIFICATION` di
  model untuk kolom yang berbeda, dan antarmuka `DataClassificationRegistry` untuk tabel tanpa model
  (K-19). Registry Core ada di `App\Models\UnmodeledTables`; module menaruh registry-nya di `src/Models`.
  Atribut dibaca juga dari kelas induk, jadi 22 master aset mewarisi klasifikasi `MasterData`.
- **Kolom jejak** `created_by_user_id` dan `updated_by_user_id` diklasifikasi platform sebagai
  `EndUserPseudonymousIdentifiers` di `AuditColumns::COLUMN_CLASSIFICATION`, seperti kolom sistem BC.
- **Semua tabel tenant terklasifikasi**: 129 tabel, yaitu 74 milik Core (48 lewat model, 26 lewat
  registry), 51 milik module aset, dan 4 milik module HR. Tabel sisi pusat (`OwnedByControlPlane`) tidak
  ikut, sama seperti penjaga kolom jejak. Keputusan kolom yang layak dicatat:

  | Kolom | Klasifikasi | Alasan |
  | --- | --- | --- |
  | `hr_workers.name`, `email` | EndUserIdentifiableInformation | Nama dan email pekerja |
  | `hr_workers.personnel_number`, `core_membership_id`, `hr_worker_position_assignments.worker_id` | EndUserPseudonymousIdentifiers | Menunjuk orang lewat tabel lain |
  | `parties.name`, `search_name`, `electronic_addresses.value`, bagian alamat `postal_addresses` | EndUserIdentifiableInformation | Party bisa orang, jadi yang lebih ketat dipakai |
  | `vendors.tax_number` | EndUserIdentifiableInformation | Vendor bisa perorangan; NPWP orang adalah data pribadi |
  | `organizations.name`, kop cetak `print_identities` | OrganizationIdentifiableInformation | Nama, alamat, dan nomor pajak entitas tenant |
  | `change_log_entries.old_value`, `new_value`, `outbox_events.payload`, `access_audit_events.payload` | EndUserIdentifiableInformation | Menyalin isi tabel lain, termasuk nama dan email |
  | `sod_conflicts.mitigation_note` | EndUserIdentifiableInformation | Catatan tentang orang tertentu |
  | `*_user_id` dan `*_membership_id` di Core dan aset | EndUserPseudonymousIdentifiers | ID pengguna |
  | `integration_clients.token_digest`, `signing_secret`, `app_service_credentials.secret_hash`, `token_digest`, tabel `invitation_codes` | AccountData | Kredensial dan kode undangan |
  | `nama`/`name` master, referensi, dan dokumen bisnis | CustomerContent, ditulis eksplisit | Nama barang, jabatan, wilayah; bukan orang |

  Bawaan tabel: `CustomerContent` untuk data bisnis dan setelan tenant, `SystemMetadata` untuk data yang
  dibuat sistem (`core_module_installations`, `tenant_deployments`, `finance_posting_deliveries`,
  `app_service_credentials`,
  `retention_policy_log_entries`, `aset_processed_core_events`, `access_audit_events`),
  `AccountData` untuk `invitation_codes` dan `tenant_app_entitlements`.
- **Test B-5** `tests/Feature/Boundary/DataClassificationBoundaryTest.php`: gagal bila tabel tenant Core
  atau module belum diklasifikasi, bawaannya `ToBeClassified`, timpaan menyebut kolom yang tidak ada, atau
  kolom bernama seperti `name`, `nama`, `email`, `phone`, `telepon`, `nik`, `npwp`, `birth`, `lahir`,
  `address`, `alamat` ikut bawaan `CustomerContent`/`SystemMetadata` tanpa ditulis eksplisit. Kolom yang
  namanya menyesatkan (`table_name`, `field_name`, `report_name`, `layout_name`, `file_name`) dikecualikan
  di test itu dengan alasannya. Satu test lain membuktikan pemeriksanya menangkap tabel tanpa klasifikasi.
- **Discord dibersihkan (K-18).** `PengirimDiscord` mengirim daftar izin atribut: kelas exception, sumber,
  method, nama rute, status, `tenant_id`, id laporan, `trace_id`, dan tautan ke SigNoz. Pesan exception,
  SQL beserta nilainya, nama tenant, nama pengguna, alamat IP, dan user agent tidak lagi dikirim.
  `LaporanKesalahan`, berkas log, dan SigNoz tidak berubah. Test di `PengirimDiscordTest` memakai laporan
  berisi nama orang, email, nama tenant, dan SQL bernilai, lalu memastikan tidak ada yang sampai ke
  Discord sementara atribut untuk SigNoz tetap utuh.

## Gap 7: lampiran dokumen (backend) {#gap-7}

Tampilan lampiran seperti BC dibuat di PRD lain. Fase ini hanya layanan dan datanya.

### Keadaan hari ini

Tidak ada layanan lampiran (`PLAT-02`). `aset_tr_dokumen_siklus_aset` berisi dokumen bisnis
(dekomisioning, penjualan, dan pemusnahan aset), bukan berkas. Disk `s3` sudah tersedia di
`apps/core/config/filesystems.php`.

### Di BC

`Document Attachment` adalah satu tabel untuk semua record:

- Kuncinya `Table ID`, `No.`, `Document Type`, `Line No.`, dan `ID`, jadi lampiran bisa menempel ke
  header maupun baris dokumen.
- Isinya nama berkas, jenis, ekstensi, isi berkas (`Media`), siapa dan kapan melampirkan.
- Penanda *Document Flow* (`Flow to Sales Trx`, `Flow to Purch. Trx`, dan seterusnya) menentukan apakah
  lampiran record dari tabel lain, misalnya customer atau item, ikut disalin ke dokumen penjualan atau
  pembelian yang memakainya. Penyalinan antar record dari tabel yang sama tidak memeriksa penanda itu.
- App *External Storage* menambahkan penyimpanan di luar database (`Stored Externally`, `External File Path`).

**`Document Attachment` hanya dipasang pada master data dan dokumen**, misalnya Customer, Vendor, Item,
Fixed Asset, Employee, Resource, dokumen penjualan dan pembelian beserta versi terpostingnya, order
produksi, dan proyek. Tabel setup dan data referensi tidak diberi lampiran. Jurnal dan entry buku besar
juga tidak memakai `Document Attachment`: buktinya dilampirkan lewat *Incoming Document*, tabel
terpisah (`Incoming Document Attachment`) yang dibuat dari baris jurnal, atau dari nomor dokumen dan
tanggal posting sebuah entry.

### Bagaimana di CoreERP

Pemilik menyetujui bentuk BC (K-09): **satu tabel lampiran untuk semua record**.

1. Satu layanan lampiran di Core, dipakai semua module lewat panggilan dalam proses. Module tidak
   menyimpan berkas sendiri-sendiri.
2. Datanya menempel pada jenis record dan ID record, dengan baris dokumen sebagai opsi, seperti kunci
   BC. Berkasnya di disk `s3`, dengan hash isi untuk memeriksa keutuhan.
3. Hak melihat dan melampirkan mengikuti hak atas record induknya.
4. Klasifikasi lampiran mengikuti induknya: lampiran pekerja HR adalah data pribadi.
5. Arsip lampiran memakai `deleted_at`, seperti data lain.

Record yang diberi lampiran pada fase 1, mengikuti pola BC:

| Tabel | Lampiran? | Alasan |
| --- | --- | --- |
| `aset_tr_aset` | Ya | Master aset: foto, faktur pembelian, garansi, sertifikat |
| `aset_tr_penerimaan_aset` | Ya | Dokumen: surat jalan, faktur, berita acara serah terima |
| `aset_tr_mutasi_aset` | Ya | Dokumen: berita acara mutasi |
| `aset_tr_pemeliharaan_aset` | Ya | Dokumen work order: foto kerusakan, laporan teknisi |
| `aset_tr_dokumen_siklus_aset` | Ya | Dokumen dekomisioning, pemusnahan, dan penjualan: berita acara, bukti |
| `aset_tr_permintaan_pengadaan_aset` | Ya | Dokumen permintaan: penawaran, justifikasi |
| `aset_tr_perencanaan_aset` | Ya | Dokumen perencanaan: kajian, anggaran |
| `hr_workers` | Ya | Master pekerja: kontrak, identitas. Klasifikasi data pribadi |
| `vendors` (Core) | Ya | Master vendor: kontrak, dokumen pajak |
| `aset_m_model_aset` | Opsional | Buku manual dan spesifikasi per model, bila dibutuhkan |
| Baris dokumen (`*_details`) | Lewat dokumennya | Lampiran baris menempel ke dokumen induk dengan nomor baris, seperti `Line No.` di BC |
| `aset_tr_penyusutan_aset` | Tidak | Entry hasil hitungan; buktinya ada di dokumen sumber |
| `aset_tr_buku_aset` | Tidak | Setelan per aset, bukan dokumen |
| `aset_m_*` lainnya, `aset_m_posting_group` | Tidak | Data referensi dan setup |
| `finance_postings` dan tabel feed finance | Tidak | Setara entry; lampiran tinggal di dokumen sumber |
| Tabel number sequence, workflow, environment, referensi wilayah | Tidak | Data teknis dan referensi |

Apakah lampiran penerimaan ikut ke aset yang lahir dari penerimaan itu, mirip *Document Flow* di BC,
diputuskan di K-07.

### Yang sudah dibangun (area 6)

- **Satu tabel `document_attachments`** (K-09): tenant, jenis record (nama tabel induk), ID record, nomor
  baris dokumen yang opsional, nama berkas, jenis isi (dibaca dari isi berkas, bukan dari nama), ukuran,
  lokasi di disk, hash SHA-256, klasifikasi, kolom jejak dan versi, `created_at`, dan `deleted_at`. Pelampir
  adalah `created_by_user_id` (K-01). Tanpa foreign key ke induk, karena induknya bisa tabel module. Model
  `App\Models\DocumentAttachment`; bawaan tabel `CustomerContent` seperti BC, dengan `file_name` sebagai
  data pribadi karena orang menamai berkas dengan nama orang.
- **Pendaftaran jenis record lewat kontrak** `AttachmentRecordType` dan `AttachmentRecordTypes` di
  `App\Support\Modules\Contracts`. Pemilik tabel menyatakan klasifikasi lampirannya dan menjawab boleh
  membuka, boleh mengubah, dan ada-tidaknya baris dokumen. Core memasang konteks module pemiliknya lebih
  dulu (`ResolveAttachmentContext` memakai `ResolveModuleContext`), jadi jawabannya memakai permission dan
  kebijakan organisasi yang sama dengan layar record itu. Core tidak membaca tabel module.
- **Klasifikasi mengikuti induk**: disalin ke kolom `data_class` saat diunggah. `hr_workers` dan `vendors`
  `EndUserIdentifiableInformation` (vendor bisa perorangan, aturan yang sama dengan `vendors.tax_number`);
  dokumen aset `CustomerContent`.
- **Berkas di disk `s3`** lewat `coreerp.attachments.disk`, di `attachments/<tenant>/<id lampiran>` tanpa
  nama berkas. Setiap unduhan menghitung ulang hash-nya; isi yang berbeda atau berkas yang hilang tidak
  dikirim, dijawab 500 `attachment_corrupted`, dan dilaporkan sebagai `AttachmentContentMismatch`.
- **Endpoint** sesi login di bawah `api/v1`, untuk layar Shell (tanpa kontrak `internal/v1`):
  `GET`/`POST records/{tabel}/{id}/attachments`, `GET attachments/{id}/download`, dan
  `DELETE attachments/{id}` dengan `If-Match` atau `version`. Record yang tidak boleh dibuka dijawab 404;
  boleh dibuka tetapi tidak boleh diubah dijawab 403. Daftar memulangkan `meta.can_change`, batas ukuran,
  dan jenis berkas yang diterima.
- **Batas unggah bawaan** (K-22): 10 MB (`COREERP_ATTACHMENT_MAX_KB`), jenis PDF, JPG/JPEG, PNG, DOCX, dan
  XLSX, diperiksa dari ekstensi dan isinya. Image Core menyetel `upload_max_filesize` 10M dan
  `post_max_size` 12M (`apps/core/Dockerfile`); tanpa itu PHP menolak berkas di atas 2 MB sebelum validasi.
- **Pendaftaran fase 1**: tujuh tabel aset (`AssetAttachments`), `hr_workers` (`WorkerAttachments`), dan
  `vendors` (`VendorAttachments`). Hak ubah memakai permission `update` resource-nya; pekerja dan dokumen
  siklus aset, yang tidak punya permission ubah, memakai `create` (K-20). Permission ubah permintaan pembelian
  aset kini dideklarasikan di manifest (K-21). Status dokumen tidak menahan lampiran, seperti lampiran pada
  dokumen terposting di BC. Baris dokumen menempel ke header
  dengan `line_number` untuk penerimaan, mutasi, work order, perencanaan, dan permintaan pengadaan.
- **Tidak didaftarkan untuk retensi**: lampiran data bisnis dan hanya diarsipkan.
- **Test B-6**: `tests/Feature/Attachments/DocumentAttachmentTest.php` (vendor, antar tenant, hash, arsip
  dan versi, batas unggah, daftar pendaftaran), `LampiranDokumenAsetTest` di module aset (hak baca/ubah,
  lingkup organisasi, antar tenant, baris dokumen, dokumen siklus dengan `create`, dan pemegang duty kelola
  permintaan pembelian yang mengubah serta melampiri permintaan lewat katalog hasil `app:register-manifest`),
  dan `PenyaringanTenantTest` di module HR (lingkup pekerja, data pribadi, pelampir dengan `workers.create`).

Yang belum:

- Tampilan lampiran di layar dibuat di PRD lain.
- Compose on-prem (`deploy/compose.edition.yaml`) belum menyetel disk S3, jadi lampiran di server on-prem
  belum punya tempat simpan sampai disk-nya disiapkan di sana.

## Gap 10: job latar dan ekspor laporan {#gap-10}

### Pertanyaan pemilik

Katanya semua unduhan bisa berjalan di frontend tanpa backend sama sekali. Apakah punya kita sudah
best practice?

**Untuk laporan, tidak disarankan dipindah ke frontend, dan BC juga tidak melakukannya.** Membuat berkas
di peramban hanya cocok untuk mengekspor baris yang sedang tampil di layar dan jumlahnya kecil. Untuk
laporan, cara itu berarti:

- seluruh data harus dikirim dulu ke peramban, termasuk data pasien yang sebenarnya cukup diringkas di
  server;
- proses mati bila tab ditutup, dan tidak bisa dijadwalkan;
- layout PDF atau Word tidak lagi dijaga di satu tempat;
- batas baris dan waktu tidak bisa ditegakkan server, dan ekspor tidak tercatat.

### Di BC

- Laporan dibuat di server. Laporan berat atau berulang dijadwalkan lewat job queue, dan hasilnya masuk
  **Report Inbox** per pengguna untuk diunduh belakangan.
- `Job Queue Entry` menyimpan jadwal (hari, rumus tanggal berikutnya), waktu mulai paling awal,
  **jumlah percobaan maksimum**, kategori, status, dan log.
- BC online membatasi jumlah baris, waktu eksekusi, dan jumlah dokumen per laporan. Batas bawaannya
  boleh dinaikkan per laporan sampai batas maksimum.

### Keadaan CoreERP

**Bentuknya sudah sama dengan BC.** `RunReportExport` membuat berkas di worker Core. Hak pengguna
diterbitkan ulang saat job berjalan, jadi hak yang dicabut di tengah jalan langsung berlaku. Pengguna
melihat kemajuannya di tray ekspor Shell, padanan Report Inbox. Batas baris, batas ekspor aktif per
pengguna, batas waktu renderer, dan masa simpan hasil diatur di `apps/core/config/reporting.php`;
batas waktu job ditulis di `RunReportExport`.

Yang belum setara:

1. ~~Semua kegagalan diperlakukan sama~~ Sudah: gangguan sesaat diulang sampai batas percobaan, kegagalan
   tetap tidak; lihat *Yang sudah dibangun (area 8)* di bawah.
2. ~~Masa simpan hasil ekspor belum lewat layanan retensi~~ Sudah: `RunReportExport` mengisi `expires_at` dari masa simpan tenant, dan penghapusannya lewat layanan retensi ([Gap 4](#gap-4)).
3. **Tidak ada penjadwalan ekspor berulang**, dan tidak ada job latar per tenant yang terlihat oleh admin
   tenant (`PLAT-08`). Bagian ini butuh notifikasi ketika job gagal, jadi ditunda bersama
   [gap 8](#di-luar-fase-1) (K-08).
4. **Batas baris tidak bisa dinaikkan per laporan** di bawah batas maksimum. Ini opsional.

~~Ekspor baris yang sedang tampil di tabel boleh dibuat di frontend sebagai tambahan, dengan batas baris
yang jelas.~~ Diputuskan lain pada 1 Oktober 2026 (K-27): ekspor daftar juga lewat antrean ekspor server,
untuk semua baris yang cocok, bukan hanya yang dimuat layar. Laporan tetap di server.

Keputusan 1 Oktober 2026 (K-24 sampai K-29) menambah padanan BC berikut. Yang ditiru: request page
berisi opsi dan filter per data item, "Last used options and filters" dan preset bernama per pengguna
atau bersama (tabel *Object Options*, page 1560 *Report Settings*), Send to Excel (data only), dan Open
in Excel pada list page — baris dan kolom yang tampil dengan filter halaman itu, tanpa layout. Yang
sengaja tidak ditiru: RDLC, sintaks filter tekstual penuh untuk pengguna awam, filter pada field tabel
mana pun (dataset adalah kontrak, dan kebijakan data berlaku), Edit in Excel yang menulis balik, format
parameter XML, dan penjadwalan (ditunda, K-08).

### Yang sudah dibangun (area 8)

- **Gangguan sesaat dibedakan dari kegagalan tetap** di `RunReportExport`. Yang diulang hanya dua:
  layanan PDF yang terlambat menjawab, menolak sambungan, atau menjawab 5xx/408/429 (ditandai
  `RenderException::transient()` di `PdfConverter`), dan worker yang mati di tengah ekspor. Semua yang lain
  langsung gagal tanpa diulang, seperti sebelumnya: layout atau dokumen yang ditolak layanan PDF (4xx
  lainnya), data melewati batas baris, hak atau keanggotaan yang dicabut, parameter yang ditolak module,
  dan kesalahan tak terduga (hampir selalu cacat kode).
- **Batas percobaan** `reporting.export_attempts`, bawaan 3 (`COREERP_REPORTING_EXPORT_ATTEMPTS`), dengan
  jeda `reporting.export_retry_seconds` 15 lalu 60 detik, padanan *Maximum No. of Attempts to Run* BC.
  Selama menunggu, baris kembali `queued` dan `failure_message` berbunyi "Gangguan sesaat saat membuat
  dokumen. Ekspor dicoba lagi otomatis (percobaan 2 dari 3)."; catatan itu dihapus bila ekspornya lalu
  berhasil. Percobaan terakhir yang gagal menulis pesan layanan PDF ditambah "Sudah dicoba 3 kali."
- **Worker yang mati di tengah ekspor.** Sebelumnya baris tertinggal `running` selamanya. Sekarang baris
  `running` punya masa sewa, `started_at` + batas waktu job (600 detik) + 30 detik. Percobaan yang datang
  sebelum sewa habis mengembalikan job ke antrean sampai sewa habis, karena `retry_after` antrean (90
  detik) lebih pendek dari batas waktu job dan worker pertama mungkin masih bekerja. Setelah sewa habis,
  percobaan berikutnya mengambil alih barisnya lewat satu UPDATE bersyarat. Bila antrean menyerah
  (`failed()`), baris ditandai gagal dengan pesan: melewati batas waktu 10 menit (tidak diulang,
  `failOnTimeout`), atau proses di server terputus berulang kali.
- **Masa simpan lewat layanan retensi** (8.2): sudah tersambung sejak area 4, kini dibuktikan dengan job
  yang benar-benar berjalan: `expires_at` mengikuti setelan tenant, dan `reporting:purge-exports`
  menghapus baris beserta berkasnya yang lebih tua dari setelan itu.
- **Test B-8** di `tests/Feature/ControlPlane/ReportingTest.php`, memakai antrean database dan
  `queue:work --once` sungguhan karena antrean sync tidak menjalankan ulang job yang dikembalikan:
  gangguan sesaat diulang sampai tiga percobaan lalu gagal; gangguan sesaat yang pulih berakhir selesai;
  penolakan layout (400) dan data terlalu besar tidak diulang; baris yang ditinggal worker mati tidak
  disentuh selama sewanya belum habis lalu diambil alih; pesan saat antrean menyerah. Test masa simpan:
  `test_masa_simpan_ekspor_mengikuti_setelan_tenant_dan_dihapus_layanan_retensi`.

### Yang sudah dibangun (opsi laporan, 1 Oktober 2026)

Rinciannya di [Dokumen cetak, layout, dan ekspor](/dev/23-document-rendering#opsi-terakhir-dan-preset-laporan).

- **Opsi terakhir (K-24).** `report_last_used_options`, satu baris per pengguna per laporan. Halaman
  laporan module membuka filternya dengan pilihan terakhir dan mencatat setiap pratinjau yang berhasil;
  permintaan ekspor mencatat filter, format, dan layout. Dialog cetak memilih layout dan format terakhir.
- **Preset (K-25).** `report_presets`: preset pribadi dibuat, dipilih, dan diarsipkan dari baris filter
  laporan, dengan versi baris dan pilihan tanggal relatif (hari ini, bulan ini, bulan lalu, tahun ini,
  tahun lalu) yang diterjemahkan Core menurut zona pengguna. Preset bersama terlihat oleh semua yang
  boleh menjalankan laporannya, dengan nama pembuatnya; membuat dan mengubahnya menunggu keputusan
  permission (lihat usulan di bawah).
- **Excel (data saja) (K-26).** Pilihan di dialog cetak untuk setiap laporan; `report_exports.kind = data`,
  ditulis OpenSpout per baris dengan sel bertipe.
- **Ekspor daftar di layar (K-27, 8.4).** Kontrak `ListExportSource`/`ListExportSources`, rute
  `POST /api/v1/list-exports`, event `coreerp:list-export`, dan `DataTable` yang memberi tahu urutannya.
  Pilot: tombol Ekspor ke Excel di Inventarisasi aset. Test: 50.000 aset diekspor utuh dengan memori
  puncak yang tidak bertambah dibanding 5.000.
- **Filter master pilihan banyak (K-28)** di laporan penyusutan, penjualan, dan pemusnahan aset, termasuk
  filter lokasi dan kondisi yang baru, dengan nama pilihan di kepala laporan dan layout bawaannya.

Usulan permission preset bersama (belum dibuat, menunggu keputusan): entry point `core.report-preset.form`,
permission `core.report-preset.create`, `core.report-preset.update`, dan `core.report-preset.delete` pada
preset bersama, satu privilege `core.report-preset.maintain`, duty `core.report-preset.manage`, dipegang
Owner. Membaca preset bersama tetap mengikuti hak menjalankan laporannya. Alternatif yang lebih kecil:
memakai `core.report-layout.update` (pengelola layout laporan), dengan akibat hak mengganti kop surat
dan hak membagi preset tidak dapat diberikan terpisah.

Yang tidak dibangun:

- 8.3 (batas baris per laporan) tetap ditunda (K-29).
- Catatan percobaan ulang tersimpan di baris tetapi belum tampil di tray ekspor; tray hanya menampilkan
  `failure_message` pada ekspor yang gagal.
- Gangguan penyimpanan (disk atau object storage) dan database saat ekspor belum dibedakan; keduanya
  masih diperlakukan sebagai kesalahan tak terduga.

## Di luar fase 1 {#di-luar-fase-1}

| No. | Topik | Kenapa ditunda | Catatan |
| --- | --- | --- | --- |
| 8 | Email dan notifikasi | Keputusan pemilik | `PLAT-07`. Field Monitoring (gap 6) dan notifikasi job gagal (gap 10) menunggu ini |
| 9 | Feature management | Keputusan pemilik | Menyalakan fitur baru per tenant beserta pembaruan datanya |
| 11 | Webhook langganan umum | Bukan fokus fase ini | Yang ada sekarang push khusus finance lewat `integration_clients` |
| 12 | Kurs, dimensi, agregasi saldo | Finance belum dibangun | Termasuk batas periode posting per pengguna (*Allow Posting From/To*) |

## Keputusan

| Kode | Keputusan | Usulan |
| --- | --- | --- |
| K-01 | Nama kolom jejak dan apa yang dirujuk | **Diputuskan 28 Sep 2026:** `created_by_user_id` dan `updated_by_user_id`, merujuk `users.id`. Riwayat lengkapnya tetap di satu tabel log perubahan (gap 6); kolom jejak hanya ringkasan per baris |
| K-02 | Penangkap log perubahan | **Diputuskan 28 Sep 2026:** trigger PostgreSQL, pelaku lewat variabel sesi `coreerp.user_id` |
| K-03 | Versi baris | **Diputuskan 28 Sep 2026:** kolom versi eksplisit seperti `version` di dokumen aset, bukan kolom sistem `xmin` |
| K-04 | Tanggal kerja per pengguna | **Diputuskan 28 Sep 2026:** masuk fase 1, diisi di My Profile, perilaku seperti BC |
| K-05 | Daftar awal tabel yang boleh diretensi | **Diputuskan 28 Sep 2026:** log number sequence, hasil ekspor laporan, dan entri log perubahan |
| K-06 | Bentuk deklarasi klasifikasi di kode | **Diputuskan 28 Sep 2026:** atribut kelas untuk bawaan tabel, konstanta untuk kolom |
| K-07 | Lampiran ikut berpindah antar dokumen | **Diputuskan 28 Sep 2026:** ditunda; fase 1 hanya melampirkan ke satu record |
| K-08 | Job latar per tenant | **Diputuskan 28 Sep 2026:** fase 2, bersama notifikasi |
| K-09 | Bentuk lampiran | **Diputuskan 28 Sep 2026:** satu tabel untuk semua record, seperti `Document Attachment` BC |
| K-10 | Nilai bawaan zona waktu pengguna | **Diputuskan 28 Sep 2026:** setelan zona waktu entitas legal (nama zona IANA); pengguna yang belum mengisi ikut entitas legal aktif |
| K-11 | Penaik versi baris | **Diputuskan 29 Sep 2026:** trigger PostgreSQL `coreerp_bump_row_version` pada setiap UPDATE, alasan yang sama dengan K-02: update lewat query builder dan job latar ikut menaikkan versi |
| K-12 | Tabel yang membawa versi baris | **Diputuskan 29 Sep 2026:** semua tabel tenant, seperti `SystemRowVersion` BC, dijaga test boundary yang sama dengan kolom jejak |
| K-13 | Luas pewajiban versi | **Diputuskan 29 Sep 2026:** semua endpoint ubah dan arsip di Core dan module beserta form-nya, dalam satu PR |
| K-14 | Masa simpan minimum dan bawaan retensi | **Diputuskan 29 Sep 2026:** ikut pola BC. Bawaan sama dengan perilaku hari ini, dibaca dari config. Audit number sequence min. 365 hari, pool terkonfirmasi min. 7, hasil ekspor min. 1, entri log tabel yang selalu dicatat min. 365 dan entri lain min. 28 (keduanya mati bawaannya), catatan penerapan retensi bawaan 365 min. 28 |
| K-15 | Layar setelan retensi | **Diputuskan 30 Sep 2026:** Pengaturan → Retensi data, dengan permission `core.retention.read` dan `core.retention.update` di duty sendiri, `core.retention.inquire` dan `core.retention.manage`, bukan di duty riwayat perubahan: peran yang sudah memegang duty itu tidak diam-diam mendapat hak menghapus log. Owner memegang keduanya |
| K-16 | Catatan hasil penerapan | **Diputuskan 30 Sep 2026:** tabel tenant `retention_policy_log_entries`, tampil di layar yang sama, dan ikut diretensi |
| K-17 | Cadangan nomor berurutan di layar retensi | **Diputuskan 30 Sep 2026:** tidak tampil di layar tenant dan tidak dapat diatur tenant; masa simpannya hanya lewat config operator (`COREERP_CONFIRMED_POOL_RETENTION_DAYS`). Isinya pemeliharaan database: nomor yang terbit tetap tercatat di `number_sequence_issues`. Menyimpang dari pola BC yang menampilkan semua tabel terdaftar; BC sendiri tidak punya padanan tabel cadangan ini |
| K-18 | Klasifikasi dan telemetri | **Diputuskan 30 Sep 2026:** SigNoz (di server sendiri) tetap menerima laporan kesalahan utuh. Hanya notifikasi Discord (pihak ketiga) yang dibersihkan: kelas exception, method dan rute, status, `tenant_id`, jejak dan tautan ke SigNoz; tanpa nama orang, email, nama tenant, user agent, atau pesan exception dan SQL bernilai. Menyimpang dari BC, yang hanya mengirim `SystemMetadata` ke telemetri, karena SigNoz bukan pihak ketiga |
| K-19 | Tabel tenant tanpa model | **Diputuskan 30 Sep 2026:** dinyatakan di satu kelas registry per pemilik, satu untuk Core dan satu per module, berisi tabel, bawaan, dan timpaan kolom. Test membaca model dan registry |
| K-20 | Hak melampiri record tanpa permission ubah | **Diputuskan 30 Sep 2026:** `hr_workers` dan `aset_tr_dokumen_siklus_aset` memakai permission `create` resource-nya yang sudah ada (`human-resources.workers.create`, `management-aset.<jenis dokumen>.create`) sebagai hak mengubah, termasuk melampirkan dan mengarsipkan lampiran. Tidak ada kode permission baru |
| K-21 | Permission ubah permintaan pembelian aset | **Diputuskan 30 Sep 2026:** `management-aset.permintaan-pembelian-aset.update` (akses `update`, entry point API permintaan) dideklarasikan di manifest, masuk privilege `management-aset.permintaan-pembelian-aset.maintain` di duty `management-aset.permintaan-pembelian-aset.manage`. Controller-nya sudah memeriksa permission itu sejak lahir |
| K-22 | Batas unggah lampiran | **Diputuskan 30 Sep 2026:** 10 MB (`COREERP_ATTACHMENT_MAX_KB` bawaan 10240), jenis PDF, JPG/JPEG, PNG, DOCX, XLSX. Image Core menyetel `upload_max_filesize` 10M dan `post_max_size` 12M |
| K-23 | Layar tautan pekerja HR | **Diputuskan 30 Sep 2026:** module HR belum punya layar; tautan pekerja lewat API saja, fokus module aset. Menu Pekerja tetap halaman pengganti; kolom Pekerja di layar anggota Core tetap ada |
| K-24 | Opsi terakhir per pengguna per laporan | **Diputuskan 1 Okt 2026:** filter dan opsi terakhir tiap pengguna untuk tiap laporan disimpan Core (`report_last_used_options`, dibatasi tenant) dan menjadi isian awal halaman filter dan dialog cetak, seperti "Last used options and filters" BC |
| K-25 | Preset laporan bernama | **Diputuskan 1 Okt 2026:** preset opsi dan filter bernama per laporan, pribadi atau dibagi ke tenant, dengan token tanggal relatif (bulan ini, bulan lalu, tahun ini, dan sejenisnya) yang diterjemahkan menurut zona pengguna saat dipakai; simpan memakai versi baris. Preset pribadi tidak butuh permission selain hak menjalankan laporan. **Siapa yang boleh membuat dan mengubah preset bersama menunggu keputusan permission**; sampai itu, preset bersama hanya dapat dibaca |
| K-26 | Excel (data saja) | **Diputuskan 1 Okt 2026:** setiap laporan dapat diekspor sebagai dataset mentah ke xlsx bertipe lewat `ValueFormat`, tanpa layout atau template, melalui antrean ekspor latar yang sama |
| K-27 | Ekspor daftar di layar (8.4) | **Diputuskan 1 Okt 2026:** daftar yang tampil (kolom, urutan, judul, filter, dan urutan baris; semua baris yang cocok) diekspor lewat antrean ekspor server yang sama, tampil di tray dan ikut masa simpan, dibaca bertahap dengan penulis xlsx streaming; xlsx bertipe sampai batas lembar Excel, CSV sesudahnya. Tanpa template. Pilot register aset lewat mekanisme bersama: module pemilik daftar memenuhinya lewat kontrak Core. Hak ekspor sama dengan hak melihat, dan kebijakan data organisasi berlaku persis seperti daftarnya |
| K-28 | Filter master pilihan banyak | **Diputuskan 1 Okt 2026:** filter master laporan (lokasi, kondisi, group, dan sejenisnya) boleh banyak pilihan dengan arti *atau*, dari ujung ke ujung: penerapan di module, layar, dan kepala laporan yang menyebut nama |
| K-29 | Batas baris ekspor | **Diputuskan 1 Okt 2026:** 8.3 (batas per laporan) tetap ditunda. Gagasan batas tetap 5.000 baris untuk ekspor dari layar dibuang; batas ekspor daftar adalah batas lembar Excel untuk xlsx dan config untuk CSV. Ekspor laporan, termasuk data saja, tetap dijaga `reporting.max_rows` karena datasetnya disusun di memori |

## Sumber {#sumber}

Microsoft Learn:

- [System fields](https://learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/developer/devenv-table-system-fields)
- [Log changes](https://learn.microsoft.com/en-us/dynamics365/business-central/across-log-changes) dan [Set up auditing](https://learn.microsoft.com/en-us/dynamics365/business-central/across-setup-auditing)
- [Update customer (If-Match)](https://learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/api-reference/v2.0/api/dynamics_customer_update)
- [Change basic settings: work date](https://learn.microsoft.com/en-us/dynamics365/business-central/ui-change-basic-settings#work-date) dan [time zone](https://learn.microsoft.com/en-us/dynamics365/business-central/ui-change-basic-settings#time-zone)
- [Create new users (F&O)](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/sysadmin/tasks/create-new-users)
- [Date/time data and time zones (F&O)](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/organization-administration/date-time-zones) dan [DateTimeUtil.getCompanyTimeZone](https://learn.microsoft.com/en-us/dotnet/api/dynamics.ax.application.datetimeutil.getcompanytimezone?view=dyn-finops-dotnet)
- [Data retention policies](https://learn.microsoft.com/en-us/dynamics365/business-central/admin-data-retention-policies)
- [AppSourceCop AS0016](https://learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/developer/analyzers/appsourcecop-as0016) dan [Classifying data sensitivity](https://learn.microsoft.com/en-us/dynamics365/business-central/admin-classifying-data-sensitivity)
- [Telemetry dan DataClassification](https://learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/developer/devenv-instrument-application-for-telemetry-app-insights)
- [Store document attachments externally](https://learn.microsoft.com/en-us/dynamics365/business-central/across-store-document-attachments-externally)
- [Report Inbox](https://learn.microsoft.com/en-us/dynamics365/business-central/ui-work-report-inbox), [Schedule a report](https://learn.microsoft.com/en-us/dynamics365/business-central/ui-work-report), dan [Job queues](https://learn.microsoft.com/en-us/dynamics365/business-central/admin-job-queues-schedule-tasks)
- [Operational limits](https://learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/administration/operational-limits-online)

Source BCApps, commit `777e102e90`:

- [`GlobalTriggerManagement.Codeunit.al`](https://github.com/microsoft/BCApps/blob/777e102e90a078b7256bf94b065ba50089abafa9/src/Layers/W1/BaseApp/GlobalTriggerManagement.Codeunit.al)
- [`ChangeLogEntry.Table.al`](https://github.com/microsoft/BCApps/blob/777e102e90a078b7256bf94b065ba50089abafa9/src/Layers/W1/BaseApp/System/ChangeLog/ChangeLogEntry.Table.al) dan [`ChangeLogSetupField.Table.al`](https://github.com/microsoft/BCApps/blob/777e102e90a078b7256bf94b065ba50089abafa9/src/Layers/W1/BaseApp/System/ChangeLog/ChangeLogSetupField.Table.al)
- [`RetenPolAllowedTables.Codeunit.al`](https://github.com/microsoft/BCApps/blob/777e102e90a078b7256bf94b065ba50089abafa9/src/System%20Application/App/Retention%20Policy/src/Retention%20Policy%20Allowed%20Tables/RetenPolAllowedTables.Codeunit.al) dan [`RetenPolInstallBaseApp.Codeunit.al`](https://github.com/microsoft/BCApps/blob/777e102e90a078b7256bf94b065ba50089abafa9/src/Layers/W1/BaseApp/OtherCapabilities/RetentionPolicy/RetenPolInstallBaseApp.Codeunit.al)
- [`Employee.Table.al`](https://github.com/microsoft/BCApps/blob/777e102e90a078b7256bf94b065ba50089abafa9/src/Layers/W1/BaseApp/HumanResources/Employee/Employee.Table.al)
- [`GenJournalLine.Table.al`](https://github.com/microsoft/BCApps/blob/777e102e90a078b7256bf94b065ba50089abafa9/src/Layers/W1/BaseApp/Finance/GeneralLedger/Journal/GenJournalLine.Table.al) dan [`GeneralJournal.Page.al`](https://github.com/microsoft/BCApps/blob/777e102e90a078b7256bf94b065ba50089abafa9/src/Layers/W1/BaseApp/Finance/GeneralLedger/Journal/GeneralJournal.Page.al)
- [`UserSettingsImpl.Codeunit.al`](https://github.com/microsoft/BCApps/blob/777e102e90a078b7256bf94b065ba50089abafa9/src/System%20Application/App/User%20Settings/src/UserSettingsImpl.Codeunit.al)
- [`DocumentAttachment.Table.al`](https://github.com/microsoft/BCApps/blob/777e102e90a078b7256bf94b065ba50089abafa9/src/Layers/W1/BaseApp/Foundation/Attachment/DocumentAttachment.Table.al) dan [`DocumentAttachmentMgmt.Codeunit.al`](https://github.com/microsoft/BCApps/blob/777e102e90a078b7256bf94b065ba50089abafa9/src/Layers/W1/BaseApp/Foundation/Attachment/DocumentAttachmentMgmt.Codeunit.al)
- [`ReportInbox.Table.al`](https://github.com/microsoft/BCApps/blob/777e102e90a078b7256bf94b065ba50089abafa9/src/Layers/W1/BaseApp/eServices/EDocument/ReportInbox.Table.al) dan [`JobQueueEntry.Table.al`](https://github.com/microsoft/BCApps/blob/777e102e90a078b7256bf94b065ba50089abafa9/src/Layers/W1/BaseApp/Modules/System/JobQueue/JobQueueEntry.Table.al)

Kode CoreERP yang diperiksa:

- `apps/core/routes/console.php`, `apps/core/config/coreerp.php`, `apps/core/config/reporting.php`,
  `apps/core/config/filesystems.php`
- `apps/core/app/Console/Commands/RecoverNumberSequenceReservations.php`,
  `apps/core/app/Console/Commands/PurgeReportExports.php`, `apps/core/app/Jobs/RunReportExport.php`
- `apps/core/app/Console/Commands/CopyEnvironment.php`, `apps/core/app/Support/Finance/PostingPusher.php`
- `modules/apperp/human-resources/src/Models/Worker.php`,
  `modules/apperp/human-resources/src/Http/Controllers/HumanResourcesController.php`
- `modules/apperp/management-aset/src/Models/transaksi/DokumenSiklusAset/DokumenSiklusAset.php`,
  `modules/apperp/management-aset/routes/api/lifecycle-documents.php`
- `modules/apperp/management-aset/src/Http/Controllers/transaksi/` (penolakan versi basi),
  `modules/apperp/management-aset/src/Reporting/Definitions/`
- `apps/core/config/app.php`, `apps/core/resources/js/lib/reports.ts`,
  `apps/core/resources/js/pages/settings/finance-postings.tsx`,
  `apps/core/app/Support/Reporting/ValueFormat.php`, `apps/core/app/Support/Reporting/ValueFormats.php`
- `apps/core/app/Support/AddressBook/OrganizationAddressBook.php`,
  `apps/core/app/Services/AddressHierarchy/TimezoneResolverService.php`,
  `apps/core/database/migrations/2026_09_14_120000_create_site_registry_tables.php`
