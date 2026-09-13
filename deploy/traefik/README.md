# Traefik + TLS wildcard lewat DNS-01

Penutup TLS untuk seluruh alamat lingkungan. Sertifikatnya satu, memuat empat nama, diterbitkan
Let's Encrypt lewat tantangan DNS-01 pada zona Cloudflare.

Rancangan beserta alasannya ada di
[environment dan pusat admin](../../docs/todo/environment-dan-pusat-admin/README.md).

## Hanya ingin mencoba alamat per lingkungan di laptop? Berkas ini tidak dibutuhkan

Untuk uji ujung-ke-ujung — membuat tenant, memberinya lingkungan, membuka alamatnya — tidak perlu
Traefik, sertifikat, token Cloudflare, maupun berkas `hosts`. Cukup dua setelan env:

`apps/core/.env`

```
COREERP_BASE_DOMAIN=erp.localhost
```

`apps/control-plane/.env`

```
COREERP_BASE_DOMAIN=erp.localhost
COREERP_ADDRESS_SCHEME=http
COREERP_ADDRESS_PORT=8000
```

Lalu nyalakan ulang kedua dev server. Alamatnya menjadi
`http://<tenant>--<lingkungan>.<jenis>.erp.localhost:8000`, dan konsol mencetaknya persis begitu.

**Kenapa tanpa `hosts`.** `.localhost` dicadangkan RFC 6761 untuk mesin sendiri, termasuk setiap
subdomainnya. Diukur di Windows 11 pada 13 September 2026: `pt-uji--peragaan.demo.erp.localhost`
diselesaikan ke `127.0.0.1` dan `::1` oleh sistem operasi, tanpa satu baris pun di `hosts`.

**Jangan `.local`.** Nama itu milik mDNS (RFC 6762). Di mesin yang sama `pt-uji.erp.local` tidak
terselesaikan sama sekali, dan di macOS ia dilempar ke Bonjour.

Yang tidak dapat diuji dengan cara ini hanyalah TLS itu sendiri — untuk itulah sisa berkas ini.

## Kenapa ini dapat dicoba di laptop

Tantangan DNS-01 berupa **record TXT**, bukan permintaan HTTP masuk. Jadi mesin di balik NAT —
termasuk laptop — dapat memperoleh sertifikat sungguhan tanpa membuka satu port pun dan tanpa satu
pun record A publik berubah. Yang mengarahkan peramban ke laptop cukup berkas `hosts`.

Itu membuat percobaan pertama **lebih aman di lokal daripada di server**: tidak ada yang publik
yang bergerak, dan tidak ada yang bisa rusak bagi orang lain.

## Sekali sebelum mulai

### 1. Token Cloudflare, di luar repo

`C:\Users\<kamu>\.coreerp\traefik.env` (Windows) atau `/etc/coreerp/traefik.env` (server):

```
CF_DNS_API_TOKEN=<token>
ACME_EMAIL=<email kamu>
```

Tokennya dibuat di Cloudflare → My Profile → API Tokens → **Create Custom Token**, dengan tepat
dua izin dan dibatasi satu zona:

| | |
| --- | --- |
| Permissions | `Zone` · `Zone` · **Read**<br>`Zone` · `DNS` · **Edit** |
| Zone Resources | Include · Specific zone · `grenery.xyz` |

`Zone:Read` dipakai klien ACME untuk menemukan zone ID; `DNS:Edit` untuk menulis TXT. Tidak lebih.
Token seluas akun akan memberi Traefik kuasa mematikan situs lain di zona yang sama.

**Berkasnya sengaja di luar repo.** `.gitignore` menutup `.env` pada kedalaman mana pun, tetapi
nama seperti `traefik.env` tidak tertangkap pola itu — dan token yang ter-push tidak dapat ditarik
kembali.

### 2. `hosts`, supaya peramban menemukan laptop

`hosts` **tidak mengenal wildcard**, jadi tiap nama harus disebut. Buka
`C:\Windows\System32\drivers\etc\hosts` sebagai Administrator, tambahkan:

```
127.0.0.1 erp.grenery.xyz
127.0.0.1 admin.erp.grenery.xyz
127.0.0.1 pt-sinar-abadi.erp.grenery.xyz
127.0.0.1 pt-sinar-abadi--peragaan-modul.demo.erp.grenery.xyz
```

Baris ketiga dan keempat menyesuaikan tenant yang ada di database kerjamu. Alamatnya dihitung
`EnvironmentAddress::forEnvironment()` — bentuknya `<tenant>.<domain>` untuk produksi dan
`<tenant>--<lingkungan>.<jenis>.<domain>` untuk selainnya.

### 3. `.env` Core

```
COREERP_BASE_DOMAIN=erp.grenery.xyz
COREERP_TRUSTED_PROXIES=*
```

Yang kedua wajib. Tanpanya Laravel membaca alamat dari koneksi ke Traefik, bukan dari header
`X-Forwarded-*` — jadi ia menyangka dirinya dilayani lewat `http` dan mengarahkan ulang ke `http`,
yang lalu dialihkan Traefik ke `https`, berulang. Gejalanya redirect loop yang sebabnya tidak
tertulis di mana pun.

`*` hanya sah di sini. Di server, sebut rentang container Traefik.

## Menjalankannya

Dev server harus hidup lebih dulu (`core` pada 8000, `control-plane` pada 8001), dan **terikat
`0.0.0.0`**, bukan `127.0.0.1` — Traefik menghubunginya dari dalam container lewat
`host.docker.internal`, dan proses yang hanya mendengar loopback tidak dapat dicapai dari sana.
`.claude/launch.json` sudah disetel begitu.

```
docker compose --env-file "$HOME/.coreerp/traefik.env" -f deploy/traefik/compose.yaml up
```

Penerbitan pertama memakan 30–90 detik: lego menulis TXT, menunggu ia terlihat, baru meminta
sertifikatnya.

## Staging lebih dulu, dan itu bawaannya

Let's Encrypt **produksi** membatasi 5 penerbitan per 7 hari untuk set nama yang identik. Lima kali
salah setel berarti terkunci seminggu, dan setelan pertama hampir tidak pernah benar. Staging
menerbitkan sertifikat yang **tidak dipercaya peramban** — peringatan merah, klik lanjut — dengan
jatah yang praktis tak terbatas.

Naik ke produksi **sesudah** seluruhnya terbukti:

```
ACME_CA_SERVER=https://acme-v02.api.letsencrypt.org/directory
```

di berkas env yang sama, lalu buang penyimpanan lamanya supaya ia tidak memakai akun staging:

```
docker compose -f deploy/traefik/compose.yaml down
docker volume rm coreerp-traefik_acme
```

## Record DNS wajib DNS-only, bukan proxied

Saat alamatnya benar-benar menunjuk server, record-nya harus **abu-abu** di Cloudflare.

Universal SSL gratis hanya mencakup apex dan wildcard **satu tingkat**: `grenery.xyz` dan
`*.grenery.xyz`. Alamat demo kita — `<tenant>--<lingkungan>.demo.erp.grenery.xyz` — berada di
tingkat kedua, dan tidak tercakup. Dengan proxy menyala, Cloudflare akan menyajikan sertifikatnya
sendiri yang tidak cocok, dan peramban menolaknya mentah-mentah. Yang mencakup tingkat kedua hanya
Advanced Certificate Manager, yang berbayar.

`grenery.xyz` dan `www.grenery.xyz` tidak tersentuh sama sekali — keduanya tetap proxied ke situs
yang sudah ada di sana.

## Kalau gagal

| Gejala | Sebabnya hampir selalu |
| --- | --- |
| `CF_DNS_API_TOKEN belum diisi` | Berkas env tidak ditemukan; periksa jalur pada `--env-file` |
| Token ditolak Cloudflare | Izinnya kurang `Zone:Read`, atau zonanya bukan `grenery.xyz` |
| Menunggu TXT lalu kehabisan waktu | Resolver menyimpan jawaban negatif. Sudah dijawab dengan menanya `1.1.1.1` langsung di compose |
| `502` di peramban | Dev server mati, atau masih terikat `127.0.0.1` |
| Redirect berulang tanpa henti | `COREERP_TRUSTED_PROXIES` belum disetel |
| Peringatan sertifikat merah | Normal untuk staging. Itu tandanya berhasil |
