# CoreERP

- Desain kanonik ada di `docs/dev/README.md`. Buka hanya dokumen yang relevan dengan tugas; dokumen konsep lama bersifat historis.
- Jaga perubahan dan dependency tetap minimal. Jangan membuat abstraksi atau compatibility layer spekulatif.
- Teks UI untuk end user—termasuk hint, label, dialog, empty state, error, dan status—wajib memakai bahasa sehari-hari yang menjelaskan tindakan atau dampaknya bagi pengguna. Jangan tampilkan istilah internal seperti entitlement, artifact, deployment/installation registry, `TenantContext`, `tenant_id`, atau istilah arsitektur lain kecuali layar memang ditujukan untuk developer/operator teknis.
- Setiap module memiliki API, UI, database, migration, contract, dan container sendiri. Dilarang query database lintas module; gunakan REST/OpenAPI atau event/AsyncAPI.
- Jangan samakan katalog, entitlement, installation, dan runtime health module. Katalog berarti produk dikenal; entitlement berarti tenant berhak memakai; `installed/ready` hanya sah setelah artifact ditempatkan dan migration berhasil menurut installation/deployment registry. UI berlabel "terpasang" wajib membaca registry tersebut, tidak boleh menyimpulkannya dari entitlement. Jika registry belum ada, nyatakan gap dan jangan memalsukan state.
- Data tenant wajib memakai `TenantContext` tepercaya dan `tenant_id`; data operasional memakai `org_unit_id` bila relevan.
- SaaS dikelola control plane; on-prem perpetual berdiri sendiri, memakai update bertanda tangan, dan tanpa telemetry wajib.
- Pertahankan perubahan user yang tidak terkait. Verifikasi hanya scope yang berubah dengan script Composer/NPM yang tersedia.

Rules:
- Do not ever hardcode a name, like name of a company, name of a person, name  of a BIG MODULE, everything should config on database, ask me if you still didnt clear about this later on the conv

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

When the user types `/graphify`, use the installed graphify skill or instructions before doing anything else.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- Dirty graphify-out/ files are expected after hooks or incremental updates; dirty graph files are not a reason to skip graphify. Only skip graphify if the task is about stale or incorrect graph output, or the user explicitly says not to use it.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
