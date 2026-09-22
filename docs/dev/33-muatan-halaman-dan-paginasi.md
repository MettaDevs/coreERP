# Muatan halaman: apa yang boleh ikut, dan apa yang harus menunggu diminta

Dokumen ini mengatur **berapa banyak data yang boleh dibawa satu halaman**. Ia berlaku untuk setiap
layar Inertia — milik Core maupun milik module — dan untuk setiap endpoint yang memberinya makan.

Tampilannya diatur standar halaman (`.agents/skills/coreerp-page-standard/SKILL.md`); yang di
bawah ini mengatur isinya.

## Kenapa aturan ini ada

Diukur 18 September 2026 pada `/settings/access`, satu-satunya tenant pengembangan, dengan **tiga
anggota, tiga role, dan dua module**:

| Yang diukur | Nilai |
| --- | --- |
| Besar respons Inertia | **117.906 byte** |
| Di antaranya, prop `apps` (katalog izin) | **109.031 byte** |
| Query per kunjungan | **44** |

Halaman itu tidak terasa lambat, dan justru itu masalahnya. Ia tidak lambat karena datanya belum
ada. Setiap angka di atas tumbuh mengikuti sesuatu yang **pasti bertambah**: jumlah module yang
dijual, jumlah pegawai tenant, jumlah role yang mereka susun. Kegagalannya sudah ditulis; yang belum
tiba hanya datanya.

Ini bentuk kegagalan yang paling mahal di ERP, karena ia tidak pernah muncul di mesin tempat kodenya
ditulis. Ia muncul di server pelanggan terbesar, beberapa bulan sesudah rilis, pada orang yang tidak
punya cara menjelaskannya.

## Empat aturan

### 1. Daftar dipaginasi di server. Tanpa pengecualian yang berbunyi "datanya sedikit".

`->get()` atas tabel yang barisnya ditentukan pemakaian tenant — anggota, dokumen, transaksi,
master, kode undangan — adalah cacat, bukan pilihan. Pakai `->paginate()`, dan biarkan pencarian,
saringan, serta pengurutan dikerjakan SQL.

Yang boleh `->get()` utuh hanyalah **daftar tertutup**: satuan ukur bawaan, klasifikasi organisasi,
kode kebijakan data — himpunan yang besarnya ditentukan katalog produk, bukan pemakaian tenant, dan
yang bertambahnya adalah keputusan rilis.

Ambang yang dipakai menilai: kalau seorang pelanggan dapat membuat baris ke-10.000 tanpa meminta izin
siapa pun, daftarnya wajib dipaginasi.

Paginasinya tampil di `CardFooter` — lihat standar halaman.

### 2. Prop yang tidak dibaca cat pertama dikirim `Inertia::defer()`

Inertia v3 mengirim prop `defer` pada permintaan susulan yang ia kirim sendiri sesudah halaman
tercat. Ini bukan pemuatan malas buatan tangan: tidak ada `useEffect`, tidak ada keadaan pemuatan
yang harus diurus, tidak ada endpoint kedua yang harus diamankan ulang.

Yang wajib ditunda:

- isi dialog dan panel yang belum terbuka;
- pohon referensi yang hanya dipakai pemilih — katalog izin, susunan organisasi, daftar wilayah;
- apa pun yang besarnya tumbuh mengikuti jumlah module, bukan jumlah baris yang ditampilkan.

```php
// Katalog izin hanya dibaca di dalam dialog rincian role.
'apps' => Inertia::defer(fn (): array => $this->katalogIzin($entitledAppIds->all())),
```

Di sisi React, prop itu **tidak ada** pada render pertama. Tipenya karena itu opsional dan diberi
nilai bawaan kosong — bukan karena backend kadang tidak punya, melainkan karena memang ada satu
jendela waktu ketika halaman hidup tanpanya:

```tsx
export default function Access({ apps = [], hierarchies = [], ... }: Props) {
```

### 3. Yang dikirim adalah bentuk yang dibaca layar, bukan baris model

`->with(['duties.privileges.permissions'])` memulangkan objek model, dan objek model diserialisasi
**utuh** oleh Inertia: `created_at`, `updated_at`, `tenant_id`, `source`, `status`, `published_at`
ikut ke peramban walaupun tidak satu pun dibaca.

Susun bentuknya sendiri — dari satu query datar bila perlu — dan sebut kolomnya satu per satu. Pada
`/settings/access` langkah ini saja memangkas pohon izin dari **109 KB menjadi 29 KB**, dengan isi
yang terbukti identik.

Konsekuensi keduanya berpasangan dengan standar halaman: **jangan menulis field pada tipe props yang
tidak benar-benar dikirim controller.** Pembaca menganggapnya bukti bahwa backend menyediakannya.

### 4. Query tidak boleh tumbuh mengikuti jumlah baris

Pola yang harus dicari saat meninjau: sebuah query — apalagi CTE rekursif — **di dalam** `map()`,
`foreach`, atau accessor yang dipanggil per baris.

Bentuk yang benar: kumpulkan kuncinya, jalankan satu query untuk semuanya, lalu petakan di PHP.
Untuk graf role, CTE-nya cukup membawa serta kolom asal sehingga satu penelusuran menjawab seluruh
daftar:

```sql
with recursive reachable(root_id, role_id) as (
    select r.id, r.id from roles r where r.tenant_id = ? and r.is_active = true and r.id in (...)
  union
    select reachable.root_id, link.child_role_id
    from security_role_children link
    join reachable on reachable.role_id = link.parent_role_id
    ...
)
select root_id, role_id from reachable
```

Contoh nyatanya ada di `AccessController::roles()`: dua query per role menjadi dua query tetap.
Dengan tiga role di mesin pengembangan selisihnya tak terlihat; dengan lima puluh role ia seratus
query lawan dua, dan tidak ada satu baris kode pun yang berubah di antara keduanya.

## Hasil pada halaman rujukan

`/settings/access?section=members`, diukur pada container yang sama, sebelum dan sesudah keempat
aturan diterapkan:

| Yang diukur | Sebelum | Sesudah |
| --- | --- | --- |
| Besar respons pertama | 117.906 byte | **13.733 byte** (−88%) |
| Query | 44 | **33** |
| Waktu PHP (median 3 permintaan) | 177 ms | **113 ms** |
| Prop yang ditunda | — | 29 KB, tiba sesudah halaman tercat |

Isi pohon izin dibandingkan byte per byte sebelum dan sesudah: identik.

Yang **belum** dikerjakan pada halaman itu, dan berlaku sebagai utang yang tercatat: `members`,
`invitations`, dan `roles` masih `->get()` tanpa paginasi (aturan 1). Ketiganya menuntut kendali
paginasi dan pencarian sisi server di UI, jadi ia pekerjaan tersendiri — bukan efek samping
perbaikan ini.

## Saat meninjau halaman baru

1. Buka respons Inertia-nya, urutkan propnya menurut besar byte. Prop terbesar harus punya alasan
   yang dapat diucapkan.
2. Hitung querynya. Jalankan halaman itu dua kali dengan jumlah baris berbeda; kalau jumlah querynya
   ikut berubah, ada aturan 4 yang dilanggar.
3. Tanyakan tiap prop: apakah cat pertama membacanya? Kalau tidak — `Inertia::defer()`.
4. Tanyakan tiap daftar: siapa yang menentukan panjangnya? Kalau jawabannya pelanggan — paginasi.

## Lihat juga

- [08-query-scopes-and-schema.md](08-query-scopes-and-schema.md) — scope tenant/legal entity/organization pada querynya
- [04-api-and-integration.md](04-api-and-integration.md) — kontrak paginasi untuk endpoint versioned
- [20-load-and-concurrency-testing.md](20-load-and-concurrency-testing.md) — gate beban sebelum modul dinyatakan selesai
- [11-local-docker-development.md](11-local-docker-development.md) — kenapa angka di mesin Windows tidak dapat dipakai menilai halaman
