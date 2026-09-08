# Modules

Setiap module dibuat di `modules/<publisher>/<module>/` hanya saat diimplementasikan.

Module di folder ini **berjalan di runtime Core** dan memakai database tenant yang sama: ia memiliki
API, UI, migration, dan contract sendiri, tetapi tidak memiliki container, database, maupun token
layanan sendiri. Tabelnya dipisahkan dengan awalan nama module, misalnya `aset_`, dan setiap tabel
membawa `tenant_id`.

Ini berbeda dari app di repo `app-erp-*`, yang sampai hari ini masih memiliki container dan database
sendiri. Perbedaan lengkapnya ada pada bagian **Dua bentuk module yang hidup berdampingan** di
[AGENTS.md](../AGENTS.md), dan alasannya pada [keputusan satu runtime](../docs/todo/satu-runtime/00-keputusan.md).

Rujukan: [standar module](../docs/dev/02-module-standard.md), [API dan integration](../docs/dev/04-api-and-integration.md), serta [scope data tenant/org unit](../docs/dev/08-query-scopes-and-schema.md).
