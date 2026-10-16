import * as React from 'react';

import { cn } from '../utils';
import {
    Combobox,
    ComboboxCollection,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
    ComboboxTrigger,
    ComboboxValue,
} from './combobox';
import { IncrementalListStatus } from './incremental-list-status';
import { visibleListOptions } from './use-incremental-list';

type SelectItem = string | { value: string; label: string };

type SelectProps = {
    /** Hasil server terpisah dari record yang sudah pernah dipilih untuk menjaga labelnya. */
    visibleValues?: string[];
    knownValues?: string[];
    incremental?: {
        loading: boolean;
        error: string;
        hasMore: boolean;
        loadMore: () => void;
        retry: () => void;
    };
    onOpenChange?: (open: boolean) => void;
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
    searchValue?: string;
    emptyMessage?: string;
    ariaLabel?: string;
    className?: string;
    portalContainer?:
        | HTMLElement
        | ShadowRoot
        | null
        | React.RefObject<HTMLElement | ShadowRoot | null>;
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
    searchValue,
    emptyMessage = 'No items found.',
    ariaLabel,
    className,
    portalContainer,
    incremental,
    onOpenChange,
    visibleValues,
    knownValues,
}: SelectProps) {
    const normalizedItems = items.map((item) =>
        typeof item === 'string' ? { value: item, label: item } : item,
    );
    const displayedItems = visibleListOptions(
        normalizedItems,
        visibleValues,
        knownValues,
    );
    const [internalValue, setInternalValue] = React.useState<string | null>(
        defaultValue,
    );
    const selected = value === undefined ? internalValue : value;
    const selectedItem =
        normalizedItems.find((item) => item.value === selected) ?? null;

    const updateValue = (nextValue: string | null) => {
        if (value === undefined) {
            setInternalValue(nextValue);
        }

        onValueChange?.(nextValue);
    };

    const inputId = React.useId();
    const hasValue =
        selected !== null && selected !== undefined && selected !== '';

    return (
        <Combobox
            inputValue={searchValue}
            onInputValueChange={(value, details) => {
                if (
                    details.reason === 'input-change' ||
                    details.reason === 'input-clear'
                ) {
                    onSearchChange?.(value);
                }
            }}
            filter={incremental ? null : undefined}
            onOpenChange={onOpenChange}
            items={displayedItems}
            value={selectedItem}
            onValueChange={(item) => updateValue(item?.value ?? null)}
            itemToStringLabel={(item) => item.label}
            itemToStringValue={(item) => item.value}
            isItemEqualToValue={(item, selected) =>
                item.value === selected.value
            }
        >
            <div className="group/select relative w-full">
                <ComboboxTrigger
                    id={inputId}
                    aria-label={ariaLabel ?? label}
                    className={cn(
                        'dark:border-input dark:bg-input/30 dark:text-foreground flex h-10 w-full items-center justify-between gap-2 rounded-[4px] border border-[#d9dfe7] bg-white px-3 py-2 text-left text-sm text-[#1f2937] shadow-none outline-none transition-colors focus:border-[#0284c7] focus:ring-2 focus:ring-[#0284c7]/15 disabled:cursor-not-allowed disabled:opacity-50 data-[popup-open]:border-[#0284c7] data-[placeholder]:text-[#8a94a6] data-[popup-open]:ring-2 data-[popup-open]:ring-[#0284c7]/15 [&>[data-slot=combobox-trigger-icon]]:ml-auto [&>[data-slot=combobox-trigger-icon]]:shrink-0',
                        label && hasValue && 'pt-3',
                        className,
                    )}
                >
                    {/* Nilai tidak merender elemen sendiri; pembungkus ini yang memotong label panjang
                        dengan elipsis alih-alih membiarkannya turun baris keluar dari field. Label lengkapnya
                        tetap terbaca lewat tooltip saat kursor berhenti di atasnya. */}
                    <span
                        className="min-w-0 flex-1 truncate"
                        title={selectedItem?.label}
                    >
                        <ComboboxValue placeholder={label ? '' : placeholder} />
                    </span>
                </ComboboxTrigger>
                {label && (
                    <label
                        htmlFor={inputId}
                        className={cn(
                            'pointer-events-none absolute start-3 z-10 px-1 leading-none transition-all',
                            hasValue
                                ? 'text-foreground dark:bg-input/30 top-0 -translate-y-1/2 bg-white text-xs group-has-[button[data-popup-open]]/select:text-[#0284c7]'
                                : 'text-foreground top-1/2 -translate-y-1/2 text-sm',
                        )}
                    >
                        {label}
                        {required && (
                            <span className="text-destructive"> *</span>
                        )}
                    </label>
                )}
            </div>
            <ComboboxContent container={portalContainer}>
                <ComboboxInput
                    placeholder={searchPlaceholder}
                    showSearchIcon
                    showTrigger={false}
                />
                <ComboboxEmpty>
                    {incremental?.loading
                        ? 'Memuat pilihan…'
                        : incremental?.error || emptyMessage}
                </ComboboxEmpty>
                <ComboboxList>
                    <ComboboxCollection>
                        {(item) => (
                            <ComboboxItem key={item.value} value={item}>
                                {item.label}
                            </ComboboxItem>
                        )}
                    </ComboboxCollection>
                    {incremental && (
                        <IncrementalListStatus state={incremental} />
                    )}
                </ComboboxList>
            </ComboboxContent>
        </Combobox>
    );
}

export { Select };
export type { SelectItem };
