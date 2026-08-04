FROM node:22-bookworm-slim AS build
WORKDIR /sdk
COPY package.json package-lock.json ./
RUN npm ci
COPY README.md ./
COPY tsconfig.json ./
COPY scripts ./scripts
COPY src ./src
RUN npm run build && mkdir /out && npm pack --pack-destination /out

FROM scratch
COPY --from=build /out/apperp-ui-0.2.0.tgz /apperp-ui.tgz
