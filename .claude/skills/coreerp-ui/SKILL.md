---
name: coreerp-ui
description: Standardize CoreERP and app React interfaces with @apperp/ui. Use when changing controls, forms, dialogs, tables, typography, accessibility, dark mode, iframe theme context, or app UI packaging.
---

# CoreERP UI

Use `@apperp/ui@0.6.2` as the single source for generic UI. Inspect its components before adding markup or CSS. Add a shared component only when existing primitives cannot compose the required behavior. A thin wrapper over an existing primitive is acceptable when its purpose is to keep one non-obvious behavior identical across apps; say so in the file's doc comment. `@apperp/ui/collapsible-section` is such a wrapper over `Accordion`: what it standardizes is the value summary that stays readable on the header line while the section is closed. `@apperp/ui/transfer-list` is a dual-listbox picker (unassigned left, assigned right, single/bulk transfer buttons) for assigning a bounded reference list to a record — use it instead of a `MultiSelect` combobox when both sides need to stay visible at once.

## Boundaries

- Web Shell owns the launcher, shared header, rail, sidebar, search, notifications, appearance, and account controls.
- An app owns only its business content and does not recreate Shell navigation.
- App CSS may define layout and domain presentation. It must not redefine fonts, tokens, controls, dialogs, tables, focus rings, invalid/disabled/loading states, or dark mode.
- Redefining an SDK component from the page has three recognisable shapes, and all three are the same mistake. Reaching into its internals with arbitrary variants (`[&_thead_th]:bg-primary` on `DataTable`); zeroing the spacing it owns (`CardHeader className="py-0"`); or re-implementing what it already does (`DialogContent` given `fixed top-1/2 left-1/2 -translate-*` when it centres itself, where a `size` prop is then silently overridden by a `max-w-*` on the same element). Each one makes that screen drift from every other screen, and each one breaks quietly when the SDK changes its internal markup — no error, just a style that stops applying. If the new look is right, it belongs in the SDK as a variant so every screen gets it; if it is right for one screen only, it is probably not right.
- Do not copy Control Plane source. Import public subpaths such as `@apperp/ui/input`, `@apperp/ui/dialog`, and `@apperp/ui/table`.
- A hosted app fills the iframe canvas. Do not add a second rounded, bordered page container when the Shell already provides the page frame.
- For a Shell sidebar, apply a custom `--sidebar-width` to the SDK Sidebar root so its spacer and rendered panel always share the same width. Show a label tooltip only when overflow is actually detected; never attach one to every menu item.
- Use the SDK `DataTable` for dense operational lists. Put row actions in its `…` menu, and show bulk actions only after rows are selected. Keep a list header compact; do not spend a tall card section on a title that has no additional context. Resizable columns must show a full-height drag guide and truncate labels/cells at their minimum width; text must never overlap its neighboring column. A record master rendered as the two-pane layout below is the exception: its left pane replaces the table.
- A select or combobox popup must match its trigger width and alignment. Do not add decorative width or offset that makes it extend beyond the field.
- For `Select` or combobox controls inside an SDK `Sheet`, `Dialog`, Popover, or another overlay, pass that overlay's content ref as `portalContainer`. Without it, the menu can render beneath the overlay and look open while its options cannot be clicked. This is a mandatory implementation rule; see the repository-wide rule in [`AGENTS.md`](../../../AGENTS.md#ui-overlay-dropdowns).

## Build a screen

1. Import `@apperp/ui/styles.css` after Tailwind and add `@source '../node_modules/@apperp/ui/dist'`.
2. Compose `Field`, `FieldLabel`, `FieldDescription`, and `FieldError`. Every field may carry optional contextual help, but only complex or non-obvious fields need it. Keep field-specific help with the field, behind `@apperp/ui/field`'s `FieldHint`.
   - `FieldHint`'s `children` is a small dedicated icon placed beside the control, not the control itself. Wrapping the control was tried and reverted: it fires the tooltip every time the pointer merely passes over the field on its way to actually clicking it, which reads as noisy rather than helpful. A separate icon never overlaps the area the user interacts with.
   - Hover opens the hint after roughly one second and closes when the pointer leaves; clicking the icon toggles a persistent open state, and clicking it again closes it. Because the trigger is a real button, the same toggle is reachable by keyboard for free (Tab to it, then Enter/Space) — no separate wiring needed.
   - `FieldLegend`'s `hint` prop uses the same `FieldHint` underneath for a section title rather than a field control.
   - Use visible `FieldDescription` when the user needs the guidance before continuing, and always for load or validation failures — a failed reference lookup is a real problem, not a hint, and must never be hidden behind hover.
   - Keep required and error text visible. For record forms, render the generated code first, then the name, then classifications and dependent selections in their business sequence. A required dependency does not belong above the record identity just because it is required.
   - Use an on-field label when the control supports it: `Input label="…"` renders the label inside the input and keeps it floating after a value is entered. Do not duplicate it with `FieldLabel`. Use `FieldLabel` only for controls without an on-field-label API.
   - Required indicators have one source only. Never put `*` into the label text of a control that renders its own required indicator (including SDK `Select`/combobox); pass the clean label and `required={true}` so the single indicator is rendered in the required style. Omit `required` and the indicator for optional fields. If a primitive has no built-in indicator, add one explicit accessible indicator at the label layer—never combine both approaches.
3. Prefer `Input`, `Textarea`, `NativeSelect`, `Switch`, and `Button`.
   - For the row of actions that sits above a record screen — edit, add, status, archive, and the save/cancel pair while editing — use `@apperp/ui/record-action-bar` rather than laying out that row per screen. It keeps the bar visible while a long form is scrolled and wraps its buttons instead of clipping them on a narrow screen; the buttons themselves stay ordinary `Button` and `ActionButton` supplied by the caller.
   - For the recurring record actions — add, edit, archive, delete — use `@apperp/ui/action-button` rather than styling a `Button` per screen, so the icon and colour of an action never differ between screens. Archive is deliberately softer than delete: archiving is reversible and keeps existing references, deleting is not. Reserve the filled destructive treatment for permanent deletion alone. A status toggle and other reversible, consequence-free actions stay a neutral `Button variant="outline"`.
   - Never let colour be the only signal. Every action carries an icon and a text label as well, and colours come from the `success` / `warning` / `destructive` tokens so both themes stay correct. Do not write raw palette classes such as `text-yellow-500`: bright yellow measures 1.39:1 against the light background token and is unreadable, while the `warning` token measures 5.26:1.
   - `ActionButton` always supplies the mapped icon; callers supply the everyday text label and the normal `Button` props. Do not show a delete action for an endpoint that only archives or soft-deletes a record.
   - **Never render a control whose endpoint does not exist.** Confirm the route and its verb in the router before wiring an action to it — a button posting to a path nobody implemented is not a placeholder, it is a promise to the user that fails at the click. The same check catches a path that exists under a different name: `settings/access/memberships/{membership}` is not `settings/access/members/{id}`, and only one of them is real.
   - A destructive action must respect the same permission the server enforces. When the payload carries a per-record flag such as `can_edit_access`, gate every action that record owns on it, not only the edit. Gating on `canManage` alone offers "delete" on rows the server will refuse — most visibly the tenant owner's.
4. Use SDK `Sheet` with `side="right"` for create or edit forms launched from a dense operational list, so the list remains visible as context. Give the sheet a scrollable form body and a fixed save/cancel footer. Keep a content ref and pass it as `portalContainer` to every Select/combobox in that overlay.
   - For a **record master** — a list whose records are read by comparing one against another, such as asset groups or depreciation books — use a two-pane layout instead: the record list on the left, the detail on the same screen at the right. Moving between records must not open and close an overlay. The left pane must read as a record list, never as Shell navigation. The detail keeps the same scrollable body and fixed action row. Because the detail is on the page rather than in an overlay, `portalContainer` is unnecessary there; it stays mandatory for any Select that remains inside a `Sheet`, `Dialog`, or Popover.
   - A record master may open read-only and switch to editing on an explicit action. When it does, never express read-only with `disabled`: a disabled control emits no click and takes no focus, so the user cannot click a value to start editing it. Use `readOnly` on text controls, and intercept the pointer on controls that have no read-only state. Use `AlertDialog` with `AlertDialogDescription` for confirmations. Use `DialogDescription` for context that must be visible in the dialog; do not hide required decision text behind a hover/click hint. Page/card descriptions remain optional and never replace field-level help.
5. Use SDK `Table`, `Card`, `Badge`, `Empty`, and pagination controls.
6. Keep end-user copy in everyday Indonesian and explain the action or impact.

## New pages inside Control Plane (Shell)

Two mistakes shipped a page with a doubled header and no way to reach it. Both are invisible in type-check and lint, so check them by hand.

- **Never wrap a Core page in `AppLayout`.** `resources/js/app.tsx` already assigns `AppLayout` as the default Inertia layout for every page outside `auth/` and `welcome`. A page that renders `<AppLayout>` itself gets the header, rail, and sidebar twice — the screenshot looks like a Shell inside a Shell. Return a fragment with `<Head>` and your `<main>`, and pass breadcrumbs through the page property instead:

  ```tsx
  export default function ReportExports(props: Props) { /* ... */ }
  ReportExports.layout = {
      breadcrumbs: [{ title: 'Ekspor laporan', href: '/reports/exports' }] satisfies BreadcrumbItem[],
  };
  ```

  Follow `pages/workflow-inbox.tsx` or `pages/settings/number-sequences.tsx`; do not follow older pages that still import `AppLayout` directly.
- **Every new route needs an entry in `components/app-sidebar.tsx`.** That file is the main Shell navigation the user sees (Dashboard, Organization, Data referensi, Nomor dokumen, ...). `layouts/settings/layout.tsx` is only the sub-navigation of the Profile/Security/Appearance pages; a link placed there alone is unreachable from the rail. Put the item under the existing group it belongs to (admin-only groups are already gated on `system_role`), and add it to the settings sub-nav too only if the page uses that layout.
- **Verify by walking the rail**, not by opening the URL directly: open the Shell, click through the rail to the new page, and confirm one header and one sidebar. A page reached by typing its URL proves nothing about either rule.

## Theme bridge

The Shell sends `theme: { appearance: 'light' | 'dark', font: 'poppins' | 'geist' }` in `coreerp.context`. After validating `event.source`, `event.origin`, and `appId`, call `applyCoreErpTheme(event.data.theme)` from `@apperp/ui/theme`. Fonts are self-hosted by the package.

## Local and release artifacts

- Local dependency: `"@apperp/ui": "file:vendor/apperp-ui.tgz"`.
- Never reference package source across repositories.
- `erp-dev/start.ps1` builds and distributes the tarball with Docker; host Node and registry signup are unnecessary. This Docker build is the only authoritative build of the SDK. **Never `npm pack` it on the host and copy that tarball into a consumer's vendor directory.** Two builds of identical source are not guaranteed to produce a byte-identical tarball — the packed archive's hash is a function of the whole file, not just its logical content. A host-built tarball will differ from what `start.ps1`'s Docker build produces, `Publish-UiSdk` will treat that difference as a real change and overwrite it on the very next `-Build`, and every consumer that had a lockfile matched to the host tarball breaks with `npm error code EINTEGRITY` in its own next `docker compose build` — a fully working local stack, self-inflicted, without a single line of app or SDK source changing.
- `npm ci` verifies a `file:` dependency's package-lock.json entry by hash. Whenever the vendored tarball's bytes change for any reason, every consumer's `package-lock.json` has to be refreshed in the same step — not as a followup, not "later" — or the very next `npm ci` (host or Docker) fails EINTEGRITY against a hash the file no longer has. `Publish-UiSdk` in `erp-dev/start.ps1` does this automatically via `Sync-UiSdkLockfile` whenever it re-vendors a changed tarball; that is what makes it safe to run `-Build` repeatedly. If a tarball is ever vendored by hand outside that function, its lockfile refresh has to be done by hand too, immediately, for every consumer: delete that dependency's entry from `package-lock.json` first — `npm install` alone will not re-verify an entry it already trusts as resolved, even against a tarball whose content changed — then run `npm install --package-lock-only`.
- **The tarball is gitignored in consumers, so a directory-level `git add` stages the lockfile and silently skips the tarball.** Consumers ignore `vendor/*` or `.packages/*` and keep the tarball tracked by an earlier forced add. `git add ui` therefore commits `package-lock.json` pointing at a hash the committed tarball does not have, and `main` breaks `npm ci` for everyone while the working tree that produced it looks perfectly fine. Stage the tarball by explicit path in the same commit as the lockfile, and confirm before pushing:

  ```bash
  git hash-object ui/vendor/apperp-ui.tgz          # bytes on disk
  git rev-parse HEAD:ui/vendor/apperp-ui.tgz       # bytes recorded in the commit
  ```

  The two must be equal. Never verify a binary artifact by redirecting `git show HEAD:<path>` to a file in PowerShell — its redirection applies text encoding and corrupts the bytes, so the hash you compute matches nothing and points at a problem that does not exist, or hides one that does.
- The ignored tarball is a development adapter. Release CI must publish the same version to the artifact registry.
- Generated output is not source. `graphify-out/` and similar directories belong in `.gitignore` at the repository root (`/graphify-out`, anchored so a same-named directory deeper in the tree is not swept up by accident), not in git. Committing them makes every regeneration surface as a thousand-line diff nobody can review, and the bytes stay in history after the files are removed.

## Checks

Run package build, type-check, export smoke, consumer builds, and local Docker health checks. Verify light/dark appearance and keyboard/focus behavior in the iframe. For every changed dialog, open it in the running app: its title and required decision text must be visible in the modal itself, not hidden behind a hover/click hint. Run `graphify update .` after repository changes.
