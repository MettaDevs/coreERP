import * as React from "react"

import {
  Combobox,
  ComboboxContent,
  ComboboxEmpty,
  ComboboxInput,
  ComboboxItem,
  ComboboxList,
  ComboboxTrigger,
  ComboboxValue,
} from "./combobox"
import { cn } from "../utils"

type MultiSelectProps = {
  items: string[]
  value?: string[]
  defaultValue?: string[]
  onValueChange?: (value: string[]) => void
  placeholder?: string
  searchPlaceholder?: string
  emptyMessage?: string
  className?: string
  /**
   * Wajib diisi saat komponen berada di dalam dialog. Tanpa ini popup ikut
   * portal ke `document.body`, berada di luar dialog, dan klik pada item
   * ditelan oleh focus trap dialog.
   */
  portalContainer?: HTMLElement | ShadowRoot | null | React.RefObject<HTMLElement | ShadowRoot | null>
}

function MultiSelect({
  items,
  value,
  defaultValue = [],
  onValueChange,
  placeholder = "Select items",
  searchPlaceholder = "Search...",
  emptyMessage = "No items found.",
  className,
  portalContainer,
}: MultiSelectProps) {
  const [internalValue, setInternalValue] = React.useState(defaultValue)
  const selected = value ?? internalValue

  const updateValue = (nextValue: string[]) => {
    if (value === undefined) setInternalValue(nextValue)
    onValueChange?.(nextValue)
  }

  return (
    <Combobox
      items={items}
      multiple
      value={selected}
      onValueChange={updateValue}
    >
      <ComboboxTrigger
        className={cn(
          "flex min-h-9 w-full items-center justify-between rounded-md border border-input bg-transparent px-3 py-1.5 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 data-[placeholder]:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50 dark:bg-input/30",
          className
        )}
      >
        <ComboboxValue placeholder={placeholder}>
          {(values) => values?.join(", ") || placeholder}
        </ComboboxValue>
      </ComboboxTrigger>
      <ComboboxContent container={portalContainer}>
        <ComboboxInput
          placeholder={searchPlaceholder}
          showSearchIcon
          showTrigger={false}
        />
        <ComboboxEmpty>{emptyMessage}</ComboboxEmpty>
        <ComboboxList>
          {(item) => (
            <ComboboxItem key={item} value={item}>
              {item}
            </ComboboxItem>
          )}
        </ComboboxList>
      </ComboboxContent>
    </Combobox>
  )
}

export { MultiSelect }
