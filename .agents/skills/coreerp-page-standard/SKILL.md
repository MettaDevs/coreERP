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

## Cards and actions

- A directory/list page uses one primary `Card`.
- Put its title in `CardHeader`. Add a hint there only when it explains the whole list, not an individual field.
- Put the primary action in `CardAction`, not in a detached page header.
- Put filters and data in `CardContent`; pagination belongs in `CardFooter`.
- Use the SDK `Empty` composition when the comparable Core page does.

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
