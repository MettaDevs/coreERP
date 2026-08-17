FROM node:22-bookworm-slim AS build
WORKDIR /app
COPY package.json package-lock.json ./
COPY vendor ./vendor
# npm dapat diam-diam melewatkan optional native binding ketika unduhannya gagal
# (npm/cli#4828): instalasinya tetap melapor sukses, lalu build meledak jauh
# kemudian dengan pesan yang menuduh lockfile, padahal penyebabnya jaringan.
# Pemeriksaan ini membuat kegagalan muncul di langkah yang benar, dan satu kali
# percobaan ulang menutup kasus yang memang cuma unduhan meleset.
RUN npm ci && node --input-type=module -e 'await import("rolldown")' || (rm -rf node_modules && npm ci && node --input-type=module -e 'await import("rolldown")')
COPY . .
RUN npm run build

FROM nginx:1.27-alpine
ENV API_UPSTREAM=app-api
COPY nginx.conf /etc/nginx/templates/default.conf.template
COPY --from=build /app/dist /usr/share/nginx/html
