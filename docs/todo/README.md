# TODO CoreERP terhadap Dynamics 365 F&O

Folder ini adalah **daftar kerja yang menunggu review**, bukan desain kanonik.
Desain kanonik tetap di [`docs/dev/README.md`](../dev/README.md). Kalau sebuah
temuan di sini bertentangan dengan `docs/dev`, yang menang adalah `docs/dev` —
kecuali temuan itu memang menyatakan bahwa dokumennya yang salah.

## Cara dokumen ini dibuat

Tujuh dimensi fondasi diaudit terhadap Dynamics 365 Finance & Operations. Setiap
dimensi diaudit satu agent, lalu **diverifikasi ulang agent kedua yang bertugas
membantah** dengan membuka file aslinya. Temuan yang tidak dapat dibuktikan
verifier dibuang, temuan yang salah nyata dikoreksi, dan temuan yang terlewat
auditor ditambahkan verifier.

Dari 7 audit, **227 temuan lolos verifikasi**: 42 blocker, 94 tinggi, 76 sedang,
15 rendah.

Bagian konsolidasi pada dokumen 01 tidak berasal dari audit itu. Ia ditambahkan
setelah pemeriksaan langsung ke dokumentasi Microsoft, karena audit hanya
menyebut konsolidasi sebagai app yang terblokir, bukan sebagai kemampuan yang
harus melekat pada legal entity.

Folder ini memuat lebih dari satu berkas kerja. Bagian di bawah memetakan audit
terhadap Dynamics 365; folder lain di sebelahnya berdiri sendiri, masing-masing
dengan halamannya sendiri di sidebar.

## Peta dokumen

| Dokumen | Isi | Temuan |
| --- | --- | --- |
| [00-ringkasan.md](general/00-ringkasan.md) | Vonis fondasi, skor per dimensi, jalur kritis, dan urutan gelombang | — |
| [01-organisasi-dan-konsolidasi.md](general/01-organisasi-dan-konsolidasi.md) | Organisasi, legal entity, operating unit, hierarchy, konsolidasi | 25 + 5 |
| [02-keamanan-dan-akses.md](general/02-keamanan-dan-akses.md) | Identity, authorization, workforce, governance akses | 24 |
| [03-lifecycle-dan-deployment.md](general/03-lifecycle-dan-deployment.md) | Katalog, entitlement, release, placement, deployment, operasi | 32 |
| [04-layanan-platform.md](general/04-layanan-platform.md) | Workflow, dokumen, event, batch, data management, lokalisasi | 30 |
| [05-fondasi-finansial.md](general/05-fondasi-finansial.md) | Currency, UoM, dimensi finansial, ledger, pajak, fixed asset | 38 |
| [06-app-management-aset.md](general/06-app-management-aset.md) | App #1 terhadap D365 Asset Management dan Fixed assets | 43 |
| [07-app-procurement.md](general/07-app-procurement.md) | App #2 terhadap D365 Procurement and sourcing | 35 |
| [08-peta-app.md](general/08-peta-app.md) | App apa saja yang perlu dibuat, isinya, dan urutannya | — |
| [99-sudah-dikerjakan.md](general/99-sudah-dikerjakan.md) | Yang sudah diperbaiki dan diverifikasi, supaya tidak dikerjakan dua kali | — |

## Legenda status

| Tanda | Arti |
| --- | --- |
| `[ ]` | Belum dikerjakan |
| `[~]` | Sedang dikerjakan |
| `[x]` | Selesai **dan** ada test yang membuktikannya |

Sebuah item hanya boleh ditandai `[x]` bila memenuhi aturan 3 pada
[`docs/dev/10-core-foundation-gates.md`](../dev/10-core-foundation-gates.md):
punya writer, reader, failure state, dan test yang membuktikan state sebelumnya
tidak dapat menyamar sebagai state berikutnya. Untuk modul, gate load test pada
[`docs/dev/20-load-and-concurrency-testing.md`](../dev/20-load-and-concurrency-testing.md)
juga berlaku.

## Tingkat keparahan

| Tingkat | Arti |
| --- | --- |
| Blocker | App yang disebut tidak dapat dibangun, atau akan dibangun salah, tanpa ini |
| Tinggi | App bisa dimulai, tetapi akan perlu rework atau tidak bisa masuk produksi |
| Sedang | Diperlukan agar produk lengkap |
| Rendah | Penyempurnaan |

## Yang tidak dianggap gap

Keputusan arsitektur berikut sengaja tidak dilaporkan sebagai kekurangan:
tenant sebagai batas kontrak dan bukan root organization; parent-child hidup
pada node hierarchy berversi dan bukan pada identitas organisasi; serta empat
kebenaran lifecycle yang terpisah.

Dua butir yang dulu ada di daftar ini — satu repository per app, dan tidak ada
query lintas database app — sudah tidak menggambarkan keadaan sekarang. Module
hidup di dalam repo Core dan memakai database tenant yang sama; yang memisahkan
mereka adalah awalan nama tabel beserta penjaganya. Lihat
[standar module](../dev/02-module-standard.md).

## Aturan pengerjaan

1. Jangan mulai sebelum item direview dan disetujui.
2. Satu item dikerjakan sampai ada buktinya, bukan sampai kodenya ada.
3. Kalau saat mengerjakan ternyata temuannya salah, perbaiki temuannya dan
   laporkan — jangan tetap kerjakan hal yang tidak perlu.
