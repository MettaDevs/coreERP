import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { Slot } from "radix-ui"

import { cn } from "../utils"

const buttonVariants = cva(
  "inline-flex shrink-0 items-center justify-center gap-2 rounded-full text-xs font-bold whitespace-nowrap transition-all outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-40 disabled:shadow-none aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 active:scale-[0.98] cursor-pointer [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
  {
    variants: {
      variant: {
        default:
          "bg-[linear-gradient(135deg,#007C89_0%,#08BFC3_100%)] text-white hover:bg-[linear-gradient(135deg,#006570_0%,#00A5AA_100%)] shadow-md shadow-cyan-800/25 hover:shadow-lg hover:shadow-cyan-800/35 border-none transition-all",
        warning:
          "bg-gradient-to-r from-[#F59E0B] to-[#ED6C02] text-white hover:from-[#D97706] hover:to-[#D95D00] shadow-md shadow-amber-600/25 hover:shadow-lg hover:shadow-amber-600/35 border-none",
        destructive:
          "bg-[#FF4D4F] text-white hover:bg-[#DC2626] shadow-xs border-none font-bold transition-all",
        outline:
          "border-2 border-[#08BFC3] bg-white text-[#007C89] hover:bg-[#C8F1F5] hover:border-[#007C89] hover:text-[#005B65] dark:bg-slate-900 dark:hover:bg-cyan-950/40 shadow-xs",
        "outline-warning":
          "border border-[#F59E0B] bg-white text-[#F59E0B] hover:bg-amber-50 hover:border-[#D97706] hover:text-[#D97706] dark:bg-slate-900 dark:hover:bg-amber-950/40 shadow-xs",
        "outline-destructive":
          "border border-[#FF4D4F] bg-white text-[#FF4D4F] hover:bg-red-50 hover:border-[#DC2626] hover:text-[#DC2626] dark:bg-slate-900 dark:hover:bg-red-950/40 shadow-xs",
        secondary:
          "bg-slate-100 text-slate-800 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700",
        ghost:
          "text-[#007C89] hover:bg-[#C8F1F5] hover:text-[#005B65] dark:hover:bg-slate-800",
        link: "text-[#007C89] hover:text-[#08BFC3] underline-offset-4 hover:underline font-bold",
      },
      size: {
        default: "h-9.5 px-4 py-2 rounded-full text-xs font-bold has-[>svg]:px-3",
        xs: "h-7 gap-1 rounded-full px-2.5 text-[10px] font-bold has-[>svg]:px-2 [&_svg:not([class*='size-'])]:size-3",
        sm: "h-8 gap-1.5 rounded-full px-3 text-[11px] font-bold has-[>svg]:px-2.5",
        lg: "h-11 sm:h-12 rounded-full px-6 text-sm font-bold has-[>svg]:px-5",
        icon: "size-9.5 rounded-full p-0 flex items-center justify-center shrink-0",
        "icon-xs": "size-7 rounded-full p-0 flex items-center justify-center shrink-0 [&_svg:not([class*='size-'])]:size-3",
        "icon-sm": "size-8 rounded-full p-0 flex items-center justify-center shrink-0",
        "icon-lg": "size-11 rounded-full p-0 flex items-center justify-center shrink-0",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

function Button({
  className,
  variant = "default",
  size = "default",
  asChild = false,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean
  }) {
  const Comp = asChild ? Slot.Root : "button"

  return (
    <Comp
      data-slot="button"
      data-variant={variant}
      data-size={size}
      className={cn(buttonVariants({ variant, size, className }))}
      {...props}
    />
  )
}

export { Button, buttonVariants }
