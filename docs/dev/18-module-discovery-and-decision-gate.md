# Gate penemuan dan keputusan module

Sebelum membuat app, master, transaksi, workflow, atau integrasi, buat proposal yang merujuk Microsoft Learn Dynamics 365. Bila tidak ada padanan resmi, nyatakan itu; jangan menciptakan klaim kesetaraan.

Proposal menetapkan pemilik data, lifecycle, scope organisasi, keamanan, nomor, workflow/SoD, dan kontrak API/event.

Bagian **keamanan** pada proposal berarti rantai lengkapnya, bukan sekadar daftar hak: entry point apa yang dilindungi, permission beserta access level-nya, privilege sebagai satuan tugas, dan duty sebagai bagian proses bisnis — ditambah bentuk security role yang masuk akal disusun tenant dari duty itu. Keempat lapis pertama wajib menjadi empat baris terpisah pada manifest; Core menolak manifest yang meringkasnya. Aturan, diagram, dan checklistnya ada di [Rantai keamanan modul transaksi](19-transaction-security-chain.md), dan harus dilewati **sebelum** modul dibangun, bukan sesudah. Nomor hanya untuk master atau dokumen bisnis yang membutuhkan identitas yang dapat dibaca manusia. App mendeklarasikan reference pada manifest dan admin tenant mengaturnya pada **Atur nomor** Core.

Workflow hanya diperlukan untuk approval, exception, keputusan berisiko/irreversible, atau handoff terkontrol. SoD hanya bila pengaju tidak boleh sekaligus memverifikasi/menyetujui. Event hanya untuk fakta setelah commit yang dikonsumsi lintas app.

Prosedur detail dan template proposal ada di `.agents/skills/module-discovery/SKILL.md`.

## Lihat juga

- [Standar module](02-module-standard.md) — yang harus dipenuhi setelah proposal disetujui
- [Number sequence](14-number-sequences.md) — kapan sebuah entitas perlu nomor
- [API dan integration bridge](04-api-and-integration.md) — kontrak API dan event yang dideklarasikan proposal
- [Rantai keamanan modul transaksi](19-transaction-security-chain.md) — apa saja yang wajib ditetapkan untuk tiap transaksi
- [Identity dan access](09-identity-and-access.md) — keamanan, duty, dan SoD yang ikut diputuskan
- [Cara berkontribusi](../onboarding/kontribusi.md) — posisi gate ini dalam alur kerja harian
