# Procurement

Modul resmi Procurement. Implementasi awal hanya membuktikan bahwa API, UI, database, contract, dan container dapat dirilis secara mandiri.

```powershell
cd deploy
Copy-Item .env.example .env
docker compose --env-file .env -f compose.fragment.yaml up --build
```

- Health: `http://localhost:18081/health`
- Hello API: `http://localhost:18081/api/v1/hello`
- UI: `http://localhost:18082`
- Database: `core_module_procurement`

Rujukan: [standar modul](../../../docs/dev/02-module-standard.md) dan [identity & access](../../../docs/dev/09-identity-and-access.md).
