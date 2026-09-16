---
name: coreerp-harbor
description: Work safely on CoreERP's release registry and delivery path — Harbor, the release assembler, SaaS dev deploys, and image pulls on managed client servers. Use when touching `deploy/registry/`, `deploy/perakit/`, `deploy/saas/pasang-rilis.sh`, `.github/workflows/rilis.yml` or `deploy-dev.yml`, `apps/control-plane/app/Registry/`, release registration or manifest v2, registry credentials in the agent API, image pull/tag logic in `deploy/agent/coreerp-agent` or `scripts/update.sh`, service images in `deploy/compose.edition.yaml`, or when cutting a release, deploying SaaS dev, rotating a robot, or debugging a failed push or pull.
---

# CoreERP Harbor and release delivery

One image per release is built once on the first server, pushed to Harbor, deployed to SaaS dev by digest, and pulled
by the agent on client servers with the same digest. Merging to `main` deploys nothing.

## Canonical sources

Read the section you need; do not restate it from memory:

- `docs/dev/29-alur-rilis-server-klien.md` — the flow from merge to client server, who holds which secret, and how a
  release number is chosen.
- `docs/dev/30-registry-harbor.md` — what Harbor holds, the robots, per-operation credentials, the pull sequence,
  immutability/retention/GC, and the measured traps.
- `deploy/registry/SPIKE.md` — the measurements behind almost every rule. Prefer it over Harbor's own documentation
  when they disagree; the spike measured this Harbor version on these servers.
- `deploy/registry/RUNBOOK.md` — operator procedures, each marked tested or untested.
- `deploy/perakit/README.md` — the assembler, the sudo wrapper, and its installer.
- `docs/todo/registry-harbor/README.md` — the PRD, open decisions, and TODO ids (CP-05, REG-08, AG-03, E2E-01).

## Invariants — never weaken these

- **Clients pull digests, never tags.** The digest sits in a manifest signed by the release key, which no robot holds.
  A stolen push robot must not be able to make a client run another image.
- **Compose on servers never pulls.** Every service keeps `pull_policy: never` and refers only to `${EDITION_IMAGE}` or
  `coreerp.local/*`. A missing local tag must fail, not fall back to `docker.io`.
- **No registry host is stored on a client server.** Host and credentials arrive with each operation.
- **One pull-only robot per operation.** It is created when the agent asks, recorded on the operation row, and deleted
  by the sweep in `RegistryCredentials::releaseClosed()` once the operation is no longer held.
- **Nothing gets a local tag until every image has matched its `RepoDigests`.** A rejected pull must not overwrite the
  tag a rollback depends on.
- **Secrets are never printed** — not to terminals, logs, conversations, command arguments, or audit detail. Pass them
  through 0600 files and stdin. Run artisan commands in the console container with `docker exec -u www-data`, never as
  root.
- **No human password accounts in Harbor.** Harbor accepts basic auth on `/v2/`, bypassing the token rate limit.
- **Do not change SSH, firewall, or static Traefik settings** (including Traefik's 60-second `readTimeout`) without the
  product owner's approval. Only the assembler pushes, from a fast server.
- **Immutability stays on and retention stays unscheduled** until CP-05 lands `terpasang-*` tags. Artifacts with an
  immutable tag cannot be deleted by retention.

## Places that must change together

| Rule | Where it is written |
| --- | --- |
| Local tag names (`coreerp.local/core:<release>`, `coreerp.local/pendamping/<name>:<first 20 hex of digest>`) | `deploy/agent/coreerp-agent`, `scripts/update.sh`, compose rewrite in `deploy/perakit/rakit.sh` |
| GC schedule (Saturday 20:00 UTC) | `CRON_GC` in `deploy/registry/atur-harbor.sh`, `GC_HARI_UTC`/`GC_JAM_UTC` in `rakit.sh` |
| Manifest v2 shape | `rakit.sh`, `ReleaseRegistry::fromManifestV2()`, the agent's manifest reader |
| Install/upgrade operation parameters and the credential endpoint | `apps/control-plane/contracts/openapi-agent.yaml`, `AgentApi`, the agent |
| Companion ("pendamping") images | Literal `image:` lines in `deploy/compose.edition.yaml` — adding one there adds a companion to the next release |

Nothing mechanical catches a drift between these. Change them in the same pull request and say so in its description.

## Cutting and verifying a release

1. Pick the number: three digits; bump MINOR if any migration changed since the previous release, PATCH otherwise
   (the check command is in docs/dev/29, "Angka mana yang dinaikkan").
2. Trigger it: `gh workflow run rilis.yml -R MettaDevs/coreERP -f rilis=<X.Y.Z> -f ref=main`. The `rakit` job builds on
   the first server; `pasang-dev` then deploys SaaS dev from the same digests.
3. Verify, do not assume:
   - both jobs green;
   - `/var/log/coreerp-rilis/<release>.log` ends with the release registered;
   - the running SaaS containers' image ids resolve (`RepoDigests`) to the digests in
     `/var/lib/coreerp-perakit/rilis/<release>/saas.json`;
   - core `/up` answers 200 from inside the console container, and the admin console login page answers 200;
   - the release appears in `site_releases`.

Release numbers cannot be reused; a burned number costs nothing.

## Measured traps

| Symptom | Cause |
| --- | --- |
| `/v2/` answers 401 **without** `Www-Authenticate`; `docker login` fails oddly | Harbor's proxy nginx cached the old `core` address; restart the proxy |
| Credential check passes with a wrong password | Harbor answers anonymous requests to `GET /projects` with `200 []`; check that the project is in the body |
| Assembler pushed a number that already existed | A failed tag read was treated as "absent". Only `NOT_FOUND`/`MANIFEST_UNKNOWN`/`NAME_UNKNOWN` mean absent; run `crane` with the caller's uid |
| Pull still works minutes after a robot was deleted | Issued tokens live for token lifetime + ~60 s |
| Large layer push dies at exactly 60 s | Traefik `readTimeout`; push from the first server |
| `.Id` of a pulled image does not match the digest | `.Id` is the config digest on the classic store and the manifest digest on containerd; compare `RepoDigests` |

## Testing changes

- Console credentials and release registration: `RegistryCredentialTest`, `ReleaseRegistrationTest`, and the registry
  part of `SettingsScreenTest` under `apps/control-plane/tests/Feature/Sites/`.
- Agent pulls: `docker run --rm -v "$PWD":/repo -w /repo ubuntu:24.04 bash deploy/agent/tests/run-tests.sh` (on Git
  Bash for Windows use `$(pwd -W)` with `MSYS_NO_PATHCONV=1`).
- Harbor contract from outside: `sudo bash deploy/registry/uji-asap.sh` on the first server.
- The assembler has no automated suite. Prove a change by cutting a real test release and reading its log.

A fake Harbor that only returns canned answers proves the code calls the URL it expects, not that the sequence
works. Shape fakes on measured responses, keep state across calls when the code reads before it writes,
and prove each new guard can fail by mutating the code once.

## Before calling it done

- The invariants above still hold, and every place in "Places that must change together" moved together.
- `docs/dev/30-registry-harbor.md` and, for operator-facing changes, `deploy/registry/RUNBOOK.md` describe the new
  behaviour. Mark RUNBOOK procedures tested only if they were run to completion on the server.
- No secret, token, or password appears in the diff, the commit message, test output you paste, or the conversation.
