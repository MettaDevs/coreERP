FROM node:22-bookworm-slim AS build
WORKDIR /sdk
COPY package.json package-lock.json ./
RUN npm ci
COPY README.md ./
COPY tsconfig.json ./
COPY scripts ./scripts
COPY src ./src
# Namanya diseragamkan di sini supaya tahap berikut tidak perlu menyebut versi. Sebelumnya
# versi tertulis di jalur COPY, sehingga menaikkan versi paket diam-diam merusak build ini.
RUN npm run build && mkdir /out && npm pack --pack-destination /out \
    && mv /out/apperp-ui-*.tgz /out/apperp-ui.tgz

FROM scratch
COPY --from=build /out/apperp-ui.tgz /apperp-ui.tgz
