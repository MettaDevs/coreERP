---
name: coreerp-konsol
description: Work safely on admin.erp — the operator console in `apps/control-plane` — and on SSO in both applications. Use when touching anything under `apps/control-plane/`, the agent API or `contracts/openapi-agent.yaml`, central-side tables (`sites`, `site_*`, `environments`, `console_settings`, `operator_audit_events`, `external_identities`), site licensing or DNS, operator authentication, or `app/Sso/` and `apps/core/app/Support/Sso/`. Also use when adding a console screen, route, audited action, console setting, or a field to the agent report.
---

# CoreERP operator console and SSO

The console is the only application operated by us rather than by a clinic. It creates tenants and
environments, manages managed on-prem client servers through their agents, and holds settings that must not
live in a server's `.env`.

## Canonical sources

Read only what the task needs:

- `docs/dev/31-admin-erp-control-plane.md` — what the console is, its data boundary, both directions of conversation, audit, and the rules it enforces.
- `docs/dev/32-sso.md` — the SSO ceremony, its guards, settings, and what is temporary.
- `docs/dev/09-identity-and-access.md` — when a tenant uses SSO, and the two kinds of invitation.
- `docs/dev/29-alur-rilis-server-klien.md` and `docs/dev/30-registry-harbor.md` — where releases and images come from.

`docs/todo/environment-dan-pusat-admin/` and `docs/todo/general/02-keamanan-dan-akses.md` still claim SSO does
not exist in this repo. They describe a plan from before it was built; the pages above are current.

## Invariants — never weaken these

1. **The console owns no schema.** No `database/` folder, no migrations, no queue, no broadcast; file sessions
   and file cache exist so it demands no tables. A new central table gets its migration in Core, a marker model
   using `OwnedByControlPlane`, and a row in `apps/core/tests/Feature/Boundary/BatasPusatTest.php`.
2. **Tenants, environments, and fleet upgrades go through Core's internal API**, never through direct writes.
   Core owns rules the console cannot see — reserved slugs, entitlement, hierarchy.
3. **The site comes from the verified signature**, carried on the request attribute by `VerifyAgentSignature` —
   never from the request body. Reading `site_id` from the payload lets one site command another.
4. **Every mutating operator action writes one audit row in the same transaction as the change.** Work
   triggered by an agent report uses `recordBySystem()`, which writes no user. Details never carry secrets.
5. **Secrets stay in `console_settings`, encrypted**, written by artisan commands that read **stdin** — never
   an argument, which `ps` and shell history keep. Screens show fingerprints, never key material.
6. **Screen routes carry both `auth` and `operator`.** Not one of them. Non-operators get 404, not 403.
7. **SSO links on `iss` + `sub`, never on email.** Logging in never creates an account. Linking an identity
   requires the password to be typed again.
8. **Agent-facing errors stay `{"error": "<code>"}`** with no explaining sentence.

## Places that must change together

| Change | Everything it touches |
| --- | --- |
| New field in the agent report | `susun_laporan` in `deploy/agent/coreerp-agent` → `SiteReports::TOP_LEVEL` **and** its validation rule → `contracts/openapi-agent.yaml`. The console rejects unknown keys, so it must be deployed before agents — which is what happens anyway, since the console serves the agent files |
| New agent route | `routes/api.php` → `contracts/openapi-agent.yaml` (`contracts/check-contract-coverage.py` fails otherwise) → the agent |
| New operation kind | Core migration that widens the `site_operations` CHECK → `SiteOperation` constants → the agent's `case` → the contract enum |
| New console setting | Follow `LicenseTerms` / `RegistrySettings`: a reader that falls back to config, a settings screen field, an audited writer |
| `EnvironmentAddress`, `IdentityProvider` | Deliberate copies of Core code. Change both; Core is authoritative; twin tests are what hold them together |

## Traps that already cost time

- **A new `NOT NULL DEFAULT false` column reads as `null`** on a model that was created before the column
  existed and never refreshed, and writing it back violates the constraint. Cast with `(bool)` where the value
  is written (16 September 2026).
- **PHPStan assumes a settings reader is pure** when one test calls it twice, and reports
  `assertFalse() with true will always evaluate to true`. The reader really does hit the database: mark it
  `@phpstan-impure`. Do not weaken the test to satisfy it (16 September 2026).
- **A multi-line method signature trips Pint's `braces_position`.** Run `composer lint:check` from the app
  folder before pushing; running `pint` on a single file after a merge resolution is what let this reach CI
  (16 September 2026).
- **`Http::fake()` makes both suites agree with each other.** Core once checked `Authorization: Bearer` while
  the console sent a different header and both suites stayed green (12 September 2026). Contract tests read
  `apps/core/contracts/openapi-internal.yaml`; keep it that way, and prefer stateful fakes (see
  `FakeCloudflare`) over fixed answers.
- **Guards must read what is really used.** The test-database guards read the live connection, not `getenv()`,
  because `config/database.php` once pinned its own value and the guard could never go red.
- **Release numbers**: the console compares with `version_compare` while the agent strips trailing `.0`, so
  `0.2` and `0.2.0` disagree between them. Always three parts — see the release number gate in
  `coreerp-architecture`.
- **"Installed" and "entitled" are different questions.** `InstalledModules` reads the environment database;
  entitlement comes from Core over HTTP. A screen that swaps them once showed an unpaid module as installed.
- **Entitlement answers are accepted only on exactly `200`**, and the `tenant_id` in the body is compared. A
  `202` read as an empty list issues a Core-only licence to a tenant that bought everything.

## Testing

```bash
cd apps/control-plane
php vendor/phpunit/phpunit/phpunit                    # whole console suite
php vendor/phpunit/phpunit/phpunit --filter Sso       # SSO, mirrored in apps/core
php vendor/bin/phpstan analyse --memory-limit=1G
composer lint:check
cd ../core && php vendor/phpunit/phpunit/phpunit --filter Boundary   # after any Core migration
```

Frontend of the console: `node node_modules/typescript/bin/tsc --noEmit -p apps/control-plane` and Prettier on
the changed `.tsx` files, both from the repository root.

## Before calling it done

- Console suite green, plus Core `Boundary` when a migration was added.
- PHPStan and `composer lint:check` clean in both applications you touched.
- Contract coverage clean when a route changed; `openapi-agent.yaml` updated by hand.
- Every new guard proven able to go red — see `docs/dev/25-standar-penjaga-dan-pengujian.md`.
- The page in `docs/dev` that describes what you changed updated in the same pull request.
