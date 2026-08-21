---
name: coreerp-page-standard
description: Build or review CoreERP and hosted-app pages with consistent typography, page hierarchy, cards, actions, hints, filters, tables, empty states, responsive behavior, and Shell navigation. Use whenever creating a new React page, adding a module screen, changing a list/detail/form page, translating a mockup into UI, or auditing visual consistency, even when the user only asks to add a page or CRUD screen.
---

# CoreERP page standard

Apply `coreerp-ui` first. Treat an existing Core page with the same job as the visual contract; inspect it before writing markup.

## Workflow

1. Find the closest Core page by behavior: directory/list, settings, form, detail, or dashboard.
2. Inspect the public `@apperp/ui` primitives it uses. Do not copy Control Plane components into an app.
   Ensure required root providers such as `TooltipProvider` are installed once before using composed primitives like `CardDescription`.
3. Identify ownership: Shell owns global header, rail, sidebar, search, theme, and account controls; the app renders business content only.
4. Implement the smallest composition that matches the reference.
5. Compare desktop/mobile, light/dark, keyboard focus, loading, error, populated, and empty states.
6. Build or type-check the changed package and run `graphify update .`.

## Page hierarchy

- Use one content title. Do not add uppercase category eyebrows such as "MASTER DATA" unless the reference page has one for a real user need.
- Match Core typography: ordinary page/card titles use `text-xl` or SDK `CardTitle`; never start a CRUD page at `text-3xl` or `text-4xl`.
- Keep page/card descriptions optional and page-wide. Do not add a hover/info hint to every heading. Give complex or non-obvious fields optional help text attached to that field; use `FieldDescription` when the guidance must be visible before entry, and keep field-specific meaning out of the page/card header.
- Keep the page container consistent with its host. An iframe app must not recreate Shell chrome.

## State the page does not own

- **A value owned by the URL is derived on every render, never copied into `useState`.** Shell navigation moves between sections by changing the query string (`?section=members` → `?section=invitations`) through an Inertia link. Visiting the same page component does not remount it, so a `useState` initialiser reads the URL once and then freezes; the address bar changes and the screen does not. The symptom looks like a broken menu, and the cause is three lines away in a file nobody suspects. `noUnusedLocals` is off, so an unused setter will not warn you.
- Props are what the server actually sends. Do not add optional fields to a props type speculatively — a reader takes `avatar_url?: string | null` as evidence the backend provides it. Either send it from the controller in the same change, or leave it out.
- Read the controller before trusting a field. If it is absent there, the fallback you wrote is the only branch that will ever run.

## Setelah menyimpan master

- Respons `POST`/`PATCH` yang berhasil harus mengembalikan record lengkap yang baru disimpan, sekurangnya `id`, `kode`, `nama`, dan status. Teruskan record itu ke controller halaman; jangan hanya meneruskan `id` lalu berharap pemuatan ulang daftar mengisi form.
- Masukkan atau perbarui record hasil respons di state daftar sebelum memulai refresh. Jika panel detail bisa terpasang ketika data daftar masih kosong, ia dapat menginisialisasi state form dari `null`: kode akhirnya terlihat dari props, tetapi nama tetap kosong karena state lokal sudah terlanjur dibuat.
- Setelah membuat record baru, pilih record tersebut dan buka mode edit otomatis bila record memiliki rincian lanjutan yang perlu diisi setelah identitasnya dibuat—contohnya nilai variable checklist, baris template, model, atau relasi. Pada form `Sheet`, biarkan form tetap terbuka dan pasang ulang dengan record hasil respons agar kode otomatis dan nama ikut tampil. Pengguna tidak perlu menekan `Ubah` untuk kedua kalinya.
- Setelah `PATCH` pada record yang sedang diedit, pertahankan perilaku halaman yang sudah disepakati (biasanya kembali ke baca). Jangan menampilkan kartu `Belum disimpan` setelah respons sukses; kartu itu hanya untuk draft sebelum `POST` berhasil.

## Cards and actions

- A directory/list page uses one primary `Card`.
- Put its title in `CardHeader`. Add a hint there only when it explains the whole list, not an individual field.
- Put the primary action in `CardAction`, not in a detached page header.
- Put filters and data in `CardContent`; pagination belongs in `CardFooter`.
- Use the SDK `Empty` composition when the comparable Core page does.
- Collapsing a section hides secondary material, never the reason the user opened the page. A directory page whose record list starts closed shows a form and two clickable headings; the data it exists to show is behind a guess. Keep the primary list open and collapse what supports it. A section that is closed by default must still say what is inside it on its header line — `@apperp/ui/collapsible-section` exists for exactly that — or the user has to open it just to learn whether it was worth opening.
- Guidance the user needs before acting stays visible. Moving an explanation inside a collapsed section hides it from precisely the person it was written for: the one who does not yet know there is something to open.

## Filters

- Desktop filter bars are horizontal with wrapping: `flex flex-col ... sm:flex-row`.
- Inspect component wrappers. `NativeSelect` owns a `w-full` wrapper, so constrain it with a parent such as `<div className="w-full sm:w-52">`; setting width only on the select does not fix stacking.
- Keep search first and related filters next. Every control needs a visible label or `aria-label`.
- Vertical stacking is the mobile fallback, not the desktop default.

## Navigation

- App manifests provide labels and permissions; Shell renders navigation.
- Do not invent placeholder dots or circles for items without meaningful icons. Text-only child navigation is valid.
- Use a real icon only when it consistently communicates the destination.
- Long labels may truncate, but retain the full accessible name.
- Hosted apps must announce `coreerp.ready` after installing their context listener. Shell and app must validate `event.source`, exact origin, and app ID before exchanging context.

## Acceptance

- Write everyday Indonesian that explains the action or consequence.
- Never expose internal architecture terms on business screens.
- Verify: one clear title; page-level hint is optional; field help is attached to its field; hover opens it after roughly one second and closes it when the pointer leaves; label/title click toggles a persistent open state; keyboard can perform the same toggle; action inside the card; desktop filters horizontal; no decorative navigation bullets; SDK font/theme inherited; context handshake completes; empty and error states readable.
