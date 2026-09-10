import { ArchiveIcon, PencilIcon, PlusIcon, Trash2Icon } from "lucide-react"
import * as React from "react"

import { cn } from "../utils"
import { Button } from "./button"

/**
 * Tombol untuk aksi record yang berulang di seluruh aplikasi: tambah, ubah, arsipkan,
 * hapus. Ikon dan warnanya ditetapkan di sini, sekali, supaya "kuning" pada satu layar
 * tidak pernah berbeda dari "kuning" di layar lain.
 *
 * Ini pembungkus tipis di atas `Button`; tidak ada perilaku baru. Yang distandarkan adalah
 * pemasangan niat aksi dengan ikon dan warnanya, hal yang kalau ditulis ulang per halaman
 * pasti melenceng. Warnanya memakai token tema, bukan warna mentah, sehingga mode gelap
 * ikut benar tanpa usaha tambahan.
 *
 * Warna tidak pernah menjadi satu-satunya penanda: setiap niat membawa ikon dan teks.
 * Pengguna yang tidak dapat membedakan merah dari hijau tetap dapat membaca tombolnya.
 */
const ACTION_INTENTS = {
  /** Menambah record baru. */
  create: {
    icon: PlusIcon,
    variant: "outline",
    className:
      "border-success/40 text-success hover:bg-success/10 hover:text-success focus-visible:ring-success/30",
  },
  /** Menyunting record yang sedang dibuka. */
  edit: {
    icon: PencilIcon,
    variant: "outline",
    className:
      "border-warning/40 text-warning hover:bg-warning/10 hover:text-warning focus-visible:ring-warning/30",
  },
  /**
   * Mengarsipkan: record tetap ada dan tetap dirujuk data lama, hanya hilang dari daftar
   * pilihan. Karena itu merahnya ditahan — ini tindakan yang dapat dianulir.
   */
  archive: {
    icon: ArchiveIcon,
    variant: "outline",
    className:
      "border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive focus-visible:ring-destructive/30",
  },
  /**
   * Menghapus permanen. Satu-satunya niat yang tampil sebagai tombol berisi penuh, supaya
   * tidak pernah tertukar dengan arsip yang duduk di sebelahnya.
   */
  delete: {
    icon: Trash2Icon,
    variant: "destructive",
    className: "",
  },
} as const

type ActionIntent = keyof typeof ACTION_INTENTS
type ActionButtonProps = Omit<React.ComponentProps<typeof Button>, "variant"> & {
  action: ActionIntent
}

function ActionButton({
  action,
  className,
  children,
  ...props
}: ActionButtonProps) {
  const { icon: Icon, variant, className: intentClassName } = ACTION_INTENTS[action]

  return (
    <Button
      data-slot="action-button"
      data-action={action}
      variant={variant}
      className={cn(intentClassName, className)}
      {...props}
    >
      <Icon aria-hidden="true" />
      {children}
    </Button>
  )
}

export { ActionButton }
export type { ActionButtonProps, ActionIntent }
