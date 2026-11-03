# Prompt agent berikutnya

Lanjutkan eksperimen pembanding stack CoreERP dari handover berikut. Jangan mulai ulang dari nol dan jangan menimpa perubahan sesi lain.

Checkout utama:
`C:/Users/Msi_/.codex/worktrees/native-stack-comparison/CoreERP`

Baca terlebih dahulu:

1. `AGENTS.md` dan skill relevan, termasuk poteto-mode, coreerp-architecture, coreerp-runtime-operations, code-formatting, benchmark-checklist.
2. `experiments/stack-comparison/HANDOFF.md` secara penuh.
3. `experiments/stack-comparison/profile.json`.
4. `.audit/native-stack/PLAN.md`, kedua grounding, `design-verdict.md`, dan `review-native-go-harness.md`.

Pengguna meminta empat implementasi native Bun+Hono+oRPC OpenAPI, Bun+Elysia+oRPC OpenAPI, Go, Rust, dibandingkan dengan PHP existing. Alur penerimaan aset sampai posting pending dan readback harus setara, termasuk auth, permissions, penomoran, query middleware, transaksi, audit, trigger, ledger, buku penyusutan, atribut, payload posting serta seluruh hasil tersimpan. Empat kandidat tidak boleh memanggil PHP untuk melakukan operasi. Local correctness dahulu, baru pengujian terisolasi di dev1/dev2. Analytics tetap hold. Jangan merge/deploy aplikasi aktif. Windows ditunda.

Simulasikan pengguna dengan start tersebar dan jeda independen Uniform(1,9) detik, mean 5. Cari last-pass/first-fail terhadap target read p95 200/p99 500 ms dan write p95 400/p99 900 ms, dengan correctness nol pelanggaran. Lima repeat yang sepadan, real sessions, snapshot sama, tuning dan resource budget tercatat, generator terpisah dan bottleneck terbukti. Jangan mengklaim seluruh ERP atau maksimum RPS dari profil ini.

Pekerjaan berhenti atas permintaan pengguna. Tidak ada benchmark server atau hasil pemenang. Source fixture dipaku pada SHA6b3cf5846fd4442c3e895cacf2ba58275f9dd75e. Root memiliki commit PHP/Go/Bun dan perubahan parent yang BELUM di-commit; Rust commit06a711b847f3bb22db64b69815a9a776baab80c4 belum diintegrasikan. Dua checker posting selesai tetapi masih uncommitted di worktree stack-php-baseline pada branch codex/stack-posting-checker. Semua lokasi dan caveat versi binary ada di HANDOFF.

Prioritas pertama adalah mengamankan checkpoint tanpa secrets, memeriksa recovery PostgreSQL uji yang terakhir exit137/OOMKilledfalse, menyelesaikan P1 acknowledgement-versus-input/durable-work reconciliation, lalu mengintegrasikan dan membuktikan checker posting. Setelah itu ulangi golden flow memakai checker terbaru dan binary Go/Bun/Rust terbaru pada clone bersih. Artifact lama belum memenuhi metadata checker terbaru; jangan menambal artifact secara manual.

Runtime uji sudah dihentikan; volume, seed.dump dan evidence privat dipertahankan. Jangan membaca password/cookie/token ke output atau stage private/. SSH dev2 berhasil melalui `ssh -J dev1 dev2`; baca config SSH resmi sebelum akses. Tidak ada perubahan server yang sudah diterapkan oleh eksperimen ini.

Sesudah seluruh gate lokal dan PHP-off cold-start terbukti, lanjutkan ke load runner, fixture server baru 128tenant x8akun, dan benchmark dev1/dev2. Laporkan VERIFIED, NOT VERIFIED, dan INCONCLUSIVE dengan jujur; jangan memilih stack dari smoke test atau angka vendor.
