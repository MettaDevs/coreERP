import * as React from 'react';

import {
    Combobox,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
    ComboboxTrigger,
    ComboboxValue,
} from './combobox';
import { cn } from '../utils';

type SelectItem = string | { value: string; label: string };

type SelectProps = {
    items: SelectItem[];
    /** `undefined` memakai state internal; `null` mengosongkan pilihan secara controlled. */
    value?: string | null;
    defaultValue?: string | null;
    onValueChange?: (value: string | null) => void;
    label?: string;
    required?: boolean;
    placeholder?: string;
    searchPlaceholder?: string;
    onSearchChange?: (query: string) => void;
    emptyMessage?: string;
    ariaLabel?: string;
    className?: string;
    portalContainer?: HTMLElement | ShadowRoot | null | React.RefObject<HTMLElement | ShadowRoot | null>;
};

function Select({
    items,
    value,
    defaultValue = null,
    onValueChange,
    label,
    required = false,
    placeholder = 'Select an item',
    searchPlaceholder = 'Search...',
    onSearchChange,
    emptyMessage = 'No items found.',
    ariaLabel,
    className,
    portalContainer,
}: SelectProps) {
    const normalizedItems = items.map((item) =>
        typeof item === 'string' ? { value: item, label: item } : item,
    );
    const [internalValue, setInternalValue] = React.useState<string | null>(
        defaultValue,
    );
    const selected = value === undefined ? internalValue : value;
    const selectedItem = normalizedItems.find(
        (item) => item.value === selected,
    ) ?? null;

    const updateValue = (nextValue: string | null) => {
        if (value === undefined) setInternalValue(nextValue);
        onValueChange?.(nextValue);
    };

    const inputId = React.useId();
    const hasValue = selected !== null && selected !== undefined && selected !== '';

    return (
        <Combobox
            items={normalizedItems}
            value={selectedItem}
            onValueChange={(item) => updateValue(item?.value ?? null)}
            itemToStringLabel={(item) => item.label}
            itemToStringValue={(item) => item.value}
        >
            <div className="group/select relative w-full">
                <ComboboxTrigger
                    id={inputId}
                    aria-label={ariaLabel ?? label}
                    className={cn(
                        'flex h-10 w-full items-center justify-between gap-2 rounded-[4px] border border-[#d9dfe7] bg-white px-3 py-2 text-left text-sm text-[#1f2937] shadow-none outline-none transition-colors focus:border-[#0284c7] focus:ring-2 focus:ring-[#0284c7]/15 data-[popup-open]:border-[#0284c7] data-[popup-open]:ring-2 data-[popup-open]:ring-[#0284c7]/15 data-[placeholder]:text-[#8a94a6] disabled:cursor-not-allowed disabled:opacity-50 dark:border-input dark:bg-input/30 dark:text-foreground [&>[data-slot=combobox-trigger-icon]]:ml-auto [&>[data-slot=combobox-trigger-icon]]:shrink-0',
                        label && hasValue && 'pt-3',
                        className,
                    )}
                >
                    <ComboboxValue placeholder={label ? '' : placeholder} />
                </ComboboxTrigger>
                {label && (
                    <label
                        htmlFor={inputId}
                        className={cn(
                            'pointer-events-none absolute start-3 z-10 px-1 leading-none transition-all',
                            hasValue
                                ? 'top-0 -translate-y-1/2 bg-white text-xs text-foreground group-has-[button[data-popup-open]]/select:text-[#0284c7] dark:bg-input/30'
                                : 'top-1/2 -translate-y-1/2 text-sm text-[#8a94a6]',
                        )}
                    >
                        {label}{required && <span className="text-destructive"> *</span>}
                    </label>
                )}
            </div>
            <ComboboxContent container={portalContainer}>
                <ComboboxInput
                    placeholder={searchPlaceholder}
                    onChange={(event) => onSearchChange?.(event.target.value)}
                    showSearchIcon
                    showTrigger={false}
                />
                <ComboboxEmpty>{emptyMessage}</ComboboxEmpty>
                <ComboboxList>
                    {(item) => (
                        <ComboboxItem key={item.value} value={item}>
                            {item.label}
                        </ComboboxItem>
                    )}
                </ComboboxList>
            </ComboboxContent>
        </Combobox>
    );
}

export { Select };
export type { SelectItem };
