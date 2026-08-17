# Reverse proxy untuk konten UI app

Shell menyajikan UI setiap app **same-origin** pada:

```
/apps-content/<placement>/<app-id>/
```

Path itu diturunkan dari pasangan `(app_id, placement)` oleh
`App\Support\AppContentPath`; tidak ada nilai path yang disimpan di database dan
tidak ada yang ditulis tangan. Reverse proxy hanya perlu menerjemahkan path itu
ke container UI milik placement bersangkutan.

## Jangan tulis config ini manual

Config-nya artefak. Render dari registry placement:

```bash
php artisan app:render-proxy-config --target=nginx --output=/etc/nginx/conf.d/coreerp-apps-content.conf
```

`--target=apache` menghasilkan blok `ProxyPass`/`ProxyPassReverse` yang setara.
Command melaporkan setiap placement yang dilewati beserta alasannya, sehingga
config yang belum lengkap tidak terbaca seolah sudah lengkap.

Di dev, `docker/entrypoint.sh` sudah menjalankan render ini saat container web
naik, jadi tidak ada langkah manual — cukup restart `core-app` setelah placement
baru dibuat.

## Dua hal yang mudah salah

**Trailing slash wajib.** `proxy_pass http://<ui-service>:80/;` dengan slash di
akhir memotong prefix, sehingga container app menerima `/`. Tanpa slash, app akan
menerima path lengkap dan seluruh route-nya meleset.

**UI app harus di-build dengan base relatif** (`base: './'` pada Vite). Prefix
path memuat `placement`, yang berbeda antar deployment, sedangkan satu image
release harus bisa dipasang di semua placement. Base absolut memaksa satu build
per placement — kustomisasi sekali-pakai yang justru dihindari model SaaS ini.

## Batas yang diketahui

Config ini statis. Jumlah placement tumbuh seiring tenant isolated, jadi setiap
provisioning menuntut render ulang dan reload proxy di semua replica. Cukup untuk
puluhan placement.

Karena path sudah di-key placement, penggantian ke resolusi dinamis nanti —
`resolver` nginx dengan `proxy_pass` bervariabel, ingress controller dengan aturan
per-placement, atau service router kecil yang membaca `app_placements` — tidak
menuntut perubahan skema maupun migrasi data.
