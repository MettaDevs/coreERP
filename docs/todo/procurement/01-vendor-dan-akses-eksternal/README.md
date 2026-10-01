# Part 1 — vendor dan akses dari luar

Bagian dari [Procurement](/todo/procurement/). Diputuskan pemilik produk pada 1 Oktober 2026. Butir
kerjanya ada di [TODO part 1](/todo/procurement/01-vendor-dan-akses-eksternal/TODO).

## Pertanyaan yang dijawab halaman ini

- Bisakah vendor login ke sistem client, dan bagaimana bila ia tidak login?
- Bagaimana satu orang vendor bekerja untuk lebih dari satu client?
- Bagaimana calon vendor masuk, dan bagaimana rekan kerjanya bergabung?
- Bagaimana vendor kecil atau perorangan tanpa email kantor ikut serta dengan aman?
- Nomor identitas apa yang wajib, dan bagaimana vendor ganda dicegah?

## Rujukan

| Hal | F&O | BC |
| --- | --- | --- |
| Vendor login | [Vendor collaboration](https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/vendor-collaboration-work-external-vendors): user eksternal di environment client | Tidak ada; satu-satunya user luar bawaan External Accountant |
| User vendor | [Manage vendor collaboration users](https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/manage-vendor-collaboration-users): user ditautkan ke *contact person*, lahir dari permintaan user dan workflow | `Vendor Card` hanya punya `Primary Contact Code` dan `Contact` |
| Role | [Set up and maintain vendor collaboration](https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/set-up-maintain-vendor-collaboration): *Vendor (external)*, *Vendor admin (external)*, *Vendor prospect (external)* | — |
| Calon vendor | [Onboard vendors](https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/vendor-onboarding): permintaan, undangan, wizard, *vendor request*, persetujuan | Purchase quote untuk contact yang belum menjadi vendor |
| Nomor pajak ganda | — | `VATRegistrationNoFormat.Table.al`: peringatan lewat `Message` |
| Perubahan data vendor oleh staf | [Vendor workflow](https://learn.microsoft.com/en-us/dynamics365/finance/accounts-payable/vendor-workflow): field tertentu yang diubah menjadi *proposed changes* dan disetujui lewat workflow; berlaku untuk perubahan, bukan pembuatan vendor | — |

Diamati di layar F&O sandbox rujukan (perusahaan demo `USMF`, 1 Oktober 2026; hanya dibaca). Rujukan
memakai nama menu item, yang berlaku di environment F&O mana pun:

| Menu item | Yang terlihat |
| --- | --- |
| `VendProspectiveVendorRegistrationRequests` — *Prospective vendor registration requests* | Ada tombol **New**: staf dapat mengetik permintaan sendiri. Kolom: Company name, Line of business, Business justification, Organization number, Organization type, Processing status, First/Middle/Last name, Submitted date, Processed date, Email, Legal entity, Language |
| `VendRequestListPage` — *Vendor requests* | **Tidak ada tombol New**; hanya Edit dan Delete. Kolom: Request ID, Status, Request type, Vendor name, Requester, Vendor collaboration access allowed, Created date and time. *Vendor request* lahir dari wizard calon vendor, bukan diketik staf |
| Daftar workspace | *Vendor bidding*, *Vendor information*, *Purchase order confirmation* tersedia — tiga workspace Vendor collaboration |

## Keputusan

**K1-01 · Vendor ikut bekerja dengan pola Vendor collaboration F&O.** Vendor masuk ke tenant client
sebagai **user eksternal**, dan **setiap langkah vendor juga dapat dikerjakan staf**. Balasan RFQ,
respons PO, dan invoice vendor masing-masing satu record yang mencatat siapa pengisinya; F&O
menuliskannya sebagai *Submitted by vendor* atau *Submitted by purchaser*. Dengan begitu proses
berjalan penuh tanpa portal, dan portal hanya menambah layar serta role di atas record yang sama.
BC tidak punya portal vendor sama sekali, jadi bagian ini mengikuti F&O.

**K1-02 · Tidak ada user atau password di master vendor.** Login milik **orang**, bukan perusahaan
vendor: satu user terhubung ke satu kontak, dan kontak itu terhubung ke vendor. Orangnya diundang
client dan memasang sandi atau login Google-nya sendiri. Staf pengadaan tidak pernah mengetik sandi
vendor — staf yang tahu sandi vendor dapat mengirim penawaran atas nama vendor tanpa jejak. Kolom
"User Nama", "Password", dan "Konfirmasi Password" di form vendor QA dihapus.

**K1-03 · Satu identitas, keanggotaan per tenant.** Seperti tamu Entra B2B di F&O: satu orang punya
satu akun SSO, dan setiap client yang mengundangnya memberi keanggotaan sendiri di tenantnya.
Akibatnya:

- data vendor tidak pernah menyeberang tenant — client B tidak dapat melihat registrasi, dokumen,
  harga, atau fakta bahwa orang itu vendor client A;
- vendor yang sama mendaftar ulang di setiap client;
- pencabutan akses berlaku per tenant; memblokir akun SSO-nya menutup semua tenant sekaligus.

**K1-04 · Calon vendor masuk lewat onboarding F&O.** Urutannya: permintaan calon vendor → undangan
ke kontaknya dengan role *Vendor prospect* → kontak mengisi wizard registrasi → *vendor request*
diperiksa dan disetujui lewat workflow → vendor dibuat di Foundation, dan role kontaknya naik
menjadi *Vendor*. Role eksternal bawaan ikut F&O: *Vendor (external)*, *Vendor admin (external)*,
*Vendor prospect (external)*; setiap client memilih role mana yang ia buka. Jalur staf — *vendor
request* yang dibuat staf atas nama calon vendor — tidak memerlukan portal.

**K1-05 · Kontak vendor adalah orang sungguhan.** PIC vendor adalah party orang di buku alamat yang
ditautkan ke vendor per legal entity, padanan *contact person* F&O dan `Contact` BC. Satu vendor
dapat punya banyak kontak, misalnya PIC penjualan dan PIC tagihan. Kolom teks "Nama PIC" di QA
diganti tautan ini.

**K1-06 · Email apa pun boleh, kecuali email sekali pakai.** F&O menolak email konsumen seperti
@gmail untuk user vendor, tetapi Microsoft Learn tidak memberi alasannya, dan
[Entra B2B sendiri](https://learn.microsoft.com/en-us/entra/external-id/one-time-passcode) menerima
tamu lewat login Google atau kode sekali pakai yang dikirim ke email. Banyak vendor kecil dan
perorangan di Indonesia tidak punya email kantor. Yang dijamin email kantor diganti dengan:

| Yang dijamin email kantor | Penggantinya |
| --- | --- |
| Orang ini dari vendor itu | Undangan dari client ke kontak yang tercatat di vendor itu; vendor perorangan adalah orangnya sendiri |
| Orang ini pemilik email itu | Undangan hanya dapat ditebus dengan bukti memegang kotak masuknya: tautan atau kode yang dikirim ke email itu, atau login Google untuk alamat Gmail |
| Akses mati saat karyawan keluar | Staf client atau *Vendor admin* mencabut akses per tenant; akun SSO dapat diblokir |
| Jelas siapa melakukan apa | Satu akun satu orang; satu email yang dipakai bersama tidak boleh |

User eksternal yang login dengan kata sandi wajib memakai verifikasi dua langkah (TOTP yang sudah
ada di SSO); yang login dengan Google mengikuti keamanan akun Google-nya.

**K1-07 · Permintaan calon vendor dibuat staf.** Staf mengetik permintaan (nama, nomor identitas
usaha, nama dan email kontak) lalu mengundang. Ini padanan langsung F&O: halaman *Prospective vendor
registration requests* punya tombol New. Karena langkah berikutnya adalah undangan, permintaan ini
dipakai bersama portal di fase 2 (`K1-11`). API supaya situs client dapat mengirim permintaan —
padanan persis F&O, yang mengimpor permintaan dari situs milik client — dibuat saat ada client yang
memintanya. Tidak ada halaman publik "daftar jadi vendor": pintu tanpa login itu rawan spam dan
akun palsu, dan F&O sendiri tidak menyediakannya.

**K1-08 · Vendor selalu login lewat SSO kita**, apa pun penyedia login karyawan tenant itu. Vendor
bukan karyawan client, jadi tidak dapat masuk lewat penyedia login milik client (mode `sendiri` di
`tenant_identity_providers`) — sama seperti tamu F&O yang selalu login di Entra rumahnya sendiri.
Akibatnya pertanyaan "lewat mana orang ini masuk" kini punya dua jawaban per tenant: karyawan lewat
setelan tenant, user eksternal lewat SSO kita. Perubahan ini disengaja dan harus tercermin di
aturan satu baris per tenant pada tabel itu.

**K1-09 · Satu jalur untuk semua: undangan.** Tidak ada penggabungan otomatis berdasarkan domain
email, dan tidak ada tombol "minta bergabung". F&O juga tidak menggabungkan orang lewat domain:
setiap user vendor lahir dari kontak, permintaan user, dan persetujuan. Penggabungan lewat domain
memberi akses ke semua karyawan domain itu, dan tidak dapat dipakai untuk Gmail sama sekali.
Tombol "minta bergabung" memberi tahu orang luar siapa saja vendor client ini. Rekan kerja yang
ingin bergabung diundang oleh *Vendor admin* vendornya atau oleh staf client.

**K1-10 · Syarat nomor identitas adalah setelan, dan duplikat hanya diperingatkan.**

- Nomor yang wajib diatur per negara dan per jenis vendor (organisasi atau perorangan), disimpan di
  database dan dapat diubah client — seperti *vendor request configuration* F&O yang per negara.
- Bawaan Indonesia: **NPWP wajib** (orang pribadi memakai NIK sejak PMK 112/2022); **NIB
  opsional**, dan client dapat mewajibkannya untuk kategori tertentu (part 2). NIB wajib bagi setiap
  pelaku usaha menurut PP 5/2021, tetapi instansi pemerintah, organisasi nirlaba yang tidak
  berusaha, dan vendor luar negeri tidak memilikinya.
- Vendor luar negeri: nomor pajak negara asal, opsional.
- Nomor yang sudah dipakai vendor lain atau permintaan yang masih berjalan di client yang sama
  memunculkan **peringatan, bukan penolakan**, seperti BC. Kasus sah yang membutuhkannya: cabang PBF
  berbagi NPWP pusat dan hanya dibedakan NITKU. Bila cocok, penyetuju menautkan orangnya sebagai
  kontak vendor yang sudah ada, bukan membuat vendor baru.

**K1-11 · Tanpa portal, prakualifikasi mengikuti F&O apa adanya.** Diputuskan setelah layar F&O
diperiksa: *vendor request* tidak dapat dibuat staf, karena ia hanya lahir dari wizard yang diisi
calon vendor setelah login. Tanpa portal, F&O membuat vendor langsung lalu mengendalikannya lewat
keadaan vendor itu sendiri. Maka di fase 1:

- staf membuat vendor langsung, dengan **tahanan** (*vendor hold* F&O: Tidak, Invoice, Pembayaran,
  Permintaan, Semua, Tidak pernah) bernilai *Semua* sampai vendor dikualifikasi;
- kelengkapan dokumen menjadi **sertifikasi vendor**, dan barang yang dapat dipasok menjadi
  **kategori pengadaan yang disetujui** per vendor (bentuknya di part 2);
- "Lolos" di QA berarti tahanan dilepas oleh orang yang berhak dan bukan pembuat vendornya;
  "Batal" berarti tahanan dipertahankan dengan alasan;
- perubahan field penting sesudah vendor lolos, seperti rekening bank dan NPWP, disetujui lewat
  workflow seperti *vendor workflow* F&O.

Akibatnya **dokumen prakualifikasi bernomor di QA (`Pra/VIII/26/0001`) tidak ada**; kualifikasi
adalah keadaan pada vendor. Alur *vendor request* tetap ada, tetapi hanya untuk onboarding lewat
portal di fase 2 (`K1-04`, `K1-07`).

## Prasyarat: celah pengambilalihan akun di SSO

Harus ditambal sebelum undangan vendor dipakai. Pendaftaran mandiri di SSO menandai email
terverifikasi tanpa mengirim apa pun, dan login Google dengan email yang sama otomatis menempel ke
akun lokal itu. Orang lain dapat mendaftar lebih dulu memakai email vendor dengan sandinya sendiri,
lalu ikut memegang akun itu setelah vendor aslinya login dengan Google. Celah ini sama untuk email
kantor, jadi `K1-06` tidak menambah risikonya; ia hanya membuatnya lebih sering diuji. Perbaikannya
di repo SSO, bukan repo ini.

Di sisi CoreERP, undangan untuk user eksternal **tidak memilih akun lewat pencarian email di SSO**,
seperti yang dilakukan undangan terikat untuk karyawan
(`apps/core/database/migrations/2026_09_16_160000_bind_invitations_to_sso_subjects.php`). Tautan
dikirim ke email itu, dan akun yang menebus tautan itulah yang diikat.
