# @apperp/ui

Canonical generic UI for CoreERP and its apps.

```tsx
import { Input } from '@apperp/ui/input';
import { applyCoreErpTheme } from '@apperp/ui/theme';
import '@apperp/ui/styles.css';
```

React and ReactDOM are peer dependencies. Local development receives `apperp-ui.tgz` from `erp-dev/start.ps1`; no registry account is needed. Release CI publishes the same version to the artifact registry.

`PageDialog` dari `@apperp/ui/page-dialog` dipakai untuk kartu/form dan pemilih yang
memang dibuka sebagai halaman bertumpuk. Susun Root controlled open/onOpenChange,
`PageDialogContent` (content ref untuk portal), `PageDialogHeader` berisi Title/Description,
Body dan Footer/Action bila perlu. Header menyediakan Kembali, jumlah halaman, expand,
dan Tutup. `expanded`, `defaultExpanded`, dan `onExpandedChange` ada pada Content;
parameter `size` milik Dialog biasa tidak dipakai. Tidak memerlukan provider.

Di Shell, portal otomatis memakai outlet konten sehingga search/navbar tetap tersedia.
Halaman bawah tetap mounted, menjadi inert dan meredup; draft, scroll, dan ukuran
tersimpan ketika kembali. Escape menutup lapisan teratas, setelah popup field; fokus
kembali ke pemanggil. Di layar sempit halaman memenuhi konten, sedangkan jumlah lapisan
masih terlihat. Motion menghormati prefers-reduced-motion. Di luar Shell, portal
mengikuti viewport. Dialog konfirmasi kecil, AlertDialog, tooltip dan FieldHint tetap
memakai komponen masing-masing. Halaman penuh biasa tidak otomatis diubah menjadi modal.
Playground stack memakai mesin yang sama.
