# Situs dokumentasi CoreERP untuk stack development lokal.
#
# Polanya sama dengan aset-ui dan hr-ui: artifact dibangun saat build image,
# lalu disajikan nginx. Menjalankan dev server VitePress di dalam container
# di atas bind mount tidak dipakai — Vite memuat modul kliennya dari dua path
# berbeda (/@fs/... dan /node_modules/...) sehingga peta route klien rusak.
#
# Untuk menulis dokumen, pakai `npm run docs:dev` di host: hot reload penuh.
# Container ini untuk yang membaca; perbarui isinya dengan `start.ps1 -Build`.

FROM node:22-alpine AS build

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

# Build gagal bila ada tautan antar dokumen yang putus.
# DOCS_NO_GIT mematikan pembacaan tanggal dari git log: build context `docs/`
# tidak membawa `.git`, dan image build tidak memasang git.
RUN DOCS_NO_GIT=true npm run docs:build

FROM nginx:alpine

COPY --from=build /app/.vitepress/dist /usr/share/nginx/html
COPY nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80
