# Packages

Hanya contract tooling, app SDK, dan UI SDK yang stabil dapat ditempatkan di sini. Domain model internal app tidak boleh menjadi shared package.

`ui/` adalah paket desain `@apperp/ui`: token visual dan komponen dasar. Header, launcher, rail, dan sidebar tetap milik Web Shell.

Core memakainya sebagai workspace npm — akar workspace adalah akar repo, dan `apps/core` menyebutnya `"@apperp/ui": "*"`. Karena itu `npm ci` dijalankan dari akar repo, bukan dari folder app, dan hanya ada satu `package-lock.json` untuk keduanya. `npm run build` di Core membangun paket ini lebih dulu lewat skrip `ui:build`.

Repository app bisnis yang terpisah belum ikut: mereka masih memasang berkas `.tgz` hasil `packages/ui/Dockerfile`.

Rujukan: [standar module](../docs/dev/02-module-standard.md) dan [API governance](../docs/dev/04-api-and-integration.md).
