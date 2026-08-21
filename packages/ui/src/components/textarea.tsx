import * as React from "react"

import { cn } from "../utils"

type TextareaProps = React.ComponentProps<"textarea"> & {
  label?: string
}

function Textarea({ className, id, label, placeholder, required, ...props }: TextareaProps) {
  const generatedId = React.useId()
  const textareaId = id ?? (label ? generatedId : undefined)

  const textarea = (
    <textarea
      id={textareaId}
      data-slot="textarea"
      required={required}
      placeholder={label ? " " : placeholder}
      className={cn(
        "flex field-sizing-content min-h-16 w-full rounded-md border border-[#d9dfe7] bg-white px-3 py-2 text-base text-[#1f2937] shadow-xs transition-[color,box-shadow] outline-none placeholder:text-[#8a94a6] focus-visible:border-[#0284c7] focus-visible:ring-2 focus-visible:ring-[#0284c7]/15 focus:border-[#0284c7] focus:ring-2 focus:ring-[#0284c7]/15 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:border-input dark:bg-input/30 dark:text-foreground dark:aria-invalid:ring-destructive/40",
        label && "peer",
        className
      )}
      {...props}
    />
  )

  if (!label) {
    return textarea
  }

  return (
    <div className="relative w-full">
      {textarea}
      <label
        htmlFor={textareaId}
        className="pointer-events-none absolute start-3 top-0 z-10 -translate-y-1/2 bg-white px-1 text-xs leading-none text-foreground dark:bg-input/30"
      >
        {label}{required && <span className="text-destructive"> *</span>}
      </label>
    </div>
  )
}

export { Textarea }
