FROM oven/bun:1.4.4 AS build
WORKDIR /app
COPY experiments/stack-comparison/native/typescript/package.json experiments/stack-comparison/native/typescript/bun.lock ./
RUN bun install --frozen-lockfile
COPY experiments/stack-comparison/native/typescript/src ./src
RUN bun run build

FROM oven/bun:1.4.4-slim
WORKDIR /app
COPY --from=build /app/dist ./dist
USER bun
ENV HOST=0.0.0.0 PORT=8000
EXPOSE 8000
CMD ["bun", "dist/hono.js"]
