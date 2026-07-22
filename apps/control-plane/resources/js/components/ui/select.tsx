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
} from '@/components/ui/combobox';
import { cn } from '@/lib/utils';

type SelectProps = {
    items: string[];
    value?: string;
    defaultValue?: string | null;
    onValueChange?: (value: string | null) => void;
    placeholder?: string;
    searchPlaceholder?: string;
    emptyMessage?: string;
    className?: string;
};

function Select({
    items,
    value,
    defaultValue = null,
    onValueChange,
    placeholder = 'Select an item',
    searchPlaceholder = 'Search...',
    emptyMessage = 'No items found.',
    className,
}: SelectProps) {
    const [internalValue, setInternalValue] = React.useState<string | null>(
        defaultValue,
    );
    const selected = value ?? internalValue;

    const updateValue = (nextValue: string | null) => {
        if (value === undefined) setInternalValue(nextValue);
        onValueChange?.(nextValue);
    };

    return (
        <Combobox
            items={items}
            value={selected}
            onValueChange={updateValue}
        >
            <ComboboxTrigger
                className={cn(
                    'flex h-9 w-full items-center justify-between rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 data-[placeholder]:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50 dark:bg-input/30',
                    className,
                )}
            >
                <ComboboxValue placeholder={placeholder} />
            </ComboboxTrigger>
            <ComboboxContent>
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
    );
}

export { Select };
