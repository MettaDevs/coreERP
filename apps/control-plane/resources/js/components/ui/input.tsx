import * as React from "react"

import { cn } from "@/lib/utils"

type InputProps = React.ComponentProps<"input"> & {
  label?: string
}

function Input({
  className,
  type,
  id,
  label,
  placeholder,
  ...props
}: InputProps) {
  const generatedId = React.useId()
  const inputId = id ?? (label ? generatedId : undefined)

  const input = (
    <input
      id={inputId}
      type={type}
      data-slot="input"
      placeholder={label ? " " : placeholder}
      className={cn(
        "h-9 w-full min-w-0 rounded-md border border-[#d9dfe7] bg-white px-3 py-1 text-base text-[#1f2937] shadow-xs transition-[color,box-shadow] outline-none selection:bg-primary selection:text-primary-foreground file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-[#8a94a6] disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm dark:border-input dark:bg-input/30 dark:text-foreground",
        "focus-visible:border-[#0284c7] focus-visible:ring-2 focus-visible:ring-[#0284c7]/15",
        "focus:border-[#0284c7] focus:ring-2 focus:ring-[#0284c7]/15",
        "aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40",
        label && "peer",
        className
      )}
      {...props}
    />
  )

  if (!label) {
    return input
  }

  return (
    <div className="relative w-full">
      {input}
      <label
        htmlFor={inputId}
        className={cn(
          "pointer-events-none absolute start-3 top-0 z-10 -translate-y-1/2 bg-white px-1 text-xs leading-none text-foreground transition-all duration-150 ease-out dark:bg-input/30",
          "peer-placeholder-shown:top-1/2 peer-placeholder-shown:text-sm",
          "peer-focus:top-0 peer-focus:text-xs peer-focus:text-foreground",
          "peer-disabled:opacity-50 peer-aria-invalid:text-destructive"
        )}
      >
        {label}
      </label>
    </div>
  )
}

export { Input }
