import * as React from "react"

import { cn } from "../utils"

/**
 * Baris aksi yang menempel di atas halaman record: nama layar di kiri, lalu tombol yang
 * bekerja pada record yang sedang dibuka — ubah, tambah, ubah status, arsipkan, atau
 * simpan dan batal ketika record sedang disunting.
 *
 * Ini pembungkus tipis; tombolnya tetap `Button` dan `ActionButton` biasa yang dikirim
 * pemanggil. Yang distandarkan adalah letaknya: bar tetap terlihat saat isi halaman
 * digulir, tingginya sama di setiap layar, dan tombol membungkus ke baris berikutnya
 * pada layar sempit alih-alih terpotong. Ditulis ulang per halaman, tiga hal itu pasti
 * melenceng, dan pengguna kehilangan tombol simpan tepat ketika formulirnya panjang.
 *
 * Judul di sini menamai layar, bukan record yang sedang dibuka; identitas record adalah
 * urusan panel detail di bawahnya.
 */
type RecordActionBarProps = React.ComponentProps<"div"> & {
  /** Nama layar, misalnya "Group aset" atau "Work order". */
  title?: React.ReactNode
  /** Isi yang didorong ke ujung baris, misalnya lencana status record. */
  trailing?: React.ReactNode
}

function RecordActionBar({
  title,
  trailing,
  className,
  children,
  ...props
}: RecordActionBarProps) {
  return (
    <div
      data-slot="record-action-bar"
      className={cn(
        "sticky top-0 z-20 flex shrink-0 flex-wrap items-center gap-2 border-b bg-background px-4 py-2",
        className,
      )}
      {...props}
    >
      {title ? <span className="mr-2 font-semibold">{title}</span> : null}
      {children}
      {trailing ? (
        <div className="ms-auto flex flex-wrap items-center gap-2">{trailing}</div>
      ) : null}
    </div>
  )
}

export { RecordActionBar }
export type { RecordActionBarProps }
