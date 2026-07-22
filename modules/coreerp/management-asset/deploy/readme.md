cd D:\Kerja\CoreERP\modules\coreerp\management-asset\deploy
Copy-Item .env.example .env
docker compose --env-file .env -f compose.fragment.yaml up -d