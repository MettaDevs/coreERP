---
name: coreerp-ui
description: Standardize CoreERP and app React interfaces with @apperp/ui. Use when changing controls, forms, dialogs, tables, typography, accessibility, dark mode, iframe theme context, or app UI packaging.
---

# CoreERP UI

Use `@apperp/ui@0.2.0` as the single source for generic UI. Inspect its components before adding markup or CSS. Add a shared component only when existing primitives cannot compose the required behavior.

## Boundaries

- Web Shell owns the launcher, shared header, rail, sidebar, search, notifications, appearance, and account controls.
- An app owns only its business content and does not recreate Shell navigation.
- App CSS may define layout and domain presentation. It must not redefine fonts, tokens, controls, dialogs, tables, focus rings, invalid/disabled/loading states, or dark mode.
- Do not copy Control Plane source. Import public subpaths such as `@apperp/ui/input`, `@apperp/ui/dialog`, and `@apperp/ui/table`.
- A hosted app fills the iframe canvas. Do not add a second rounded, bordered page container when the Shell already provides the page frame.
- For a Shell sidebar, apply a custom `--sidebar-width` to the SDK Sidebar root so its spacer and rendered panel always share the same width. Show a label tooltip only when overflow is actually detected; never attach one to every menu item.
- Use the SDK `DataTable` for dense operational lists. Put row actions in its `…` menu, and show bulk actions only after rows are selected. Keep a list header compact; do not spend a tall card section on a title that has no additional context. Resizable columns must show a full-height drag guide and truncate labels/cells at their minimum width; text must never overlap its neighboring column.
- A select or combobox popup must match its trigger width and alignment. Do not add decorative width or offset that makes it extend beyond the field.
- For `Select` or combobox controls inside an SDK `Sheet`, `Dialog`, Popover, or another overlay, pass that overlay's content ref as `portalContainer`. Without it, the menu can render beneath the overlay and look open while its options cannot be clicked. This is a mandatory implementation rule; see the repository-wide rule in [`AGENTS.md`](../../../AGENTS.md#ui-overlay-dropdowns).

## Build a screen

1. Import `@apperp/ui/styles.css` after Tailwind and add `@source '../node_modules/@apperp/ui/dist'`.
2. Compose `Field`, `FieldLabel`, `FieldDescription`, and `FieldError`. For record forms, render the generated code first, then the name, then classifications and dependent selections in their business sequence. A required dependency does not belong above the record identity just because it is required.
   - Use an on-field label when the control supports it: `Input label="…"` renders the label inside the input and keeps it floating after a value is entered. Do not duplicate it with `FieldLabel`. Use `FieldLabel` only for controls without an on-field-label API.
3. Prefer `Input`, `Textarea`, `NativeSelect`, `Switch`, and `Button`.
4. Use SDK `Sheet` with `side="right"` for create or edit forms launched from a list, so the list remains visible as context. Give the sheet a scrollable form body and a fixed save/cancel footer. Keep a content ref and pass it as `portalContainer` to every Select/combobox in that overlay. Use `AlertDialog` with `AlertDialogDescription` for confirmations. `DialogDescription` is an optional information-icon tooltip in this package, not body text; use it only for a hint that is intentionally opened by the user.
5. Use SDK `Table`, `Card`, `Badge`, `Empty`, and pagination controls.
6. Keep end-user copy in everyday Indonesian and explain the action or impact.

## Theme bridge

The Shell sends `theme: { appearance: 'light' | 'dark', font: 'poppins' | 'geist' }` in `coreerp.context`. After validating `event.source`, `event.origin`, and `appId`, call `applyCoreErpTheme(event.data.theme)` from `@apperp/ui/theme`. Fonts are self-hosted by the package.

## Local and release artifacts

- Local dependency: `"@apperp/ui": "file:vendor/apperp-ui.tgz"`.
- Never reference package source across repositories.
- `erp-dev/start.ps1` builds and distributes the tarball with Docker; host Node and registry signup are unnecessary.
- The ignored tarball is a development adapter. Release CI must publish the same version to the artifact registry.

## Checks

Run package build, type-check, export smoke, consumer builds, and local Docker health checks. Verify light/dark appearance and keyboard/focus behavior in the iframe. For every changed dialog, open it in the running app: its title and required decision text must be visible in the modal itself, not hidden behind a hover/click hint. Run `graphify update .` after repository changes.
