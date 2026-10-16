import * as React from 'react';

import { cn } from '../utils';
import { useRecordEditActivation } from './record-edit-boundary';

type TextareaProps = React.ComponentProps<'textarea'> & {
    label?: string;
    editActivation?: boolean;
};

function Textarea({
    className,
    id,
    label,
    placeholder,
    required,
    editActivation = true,
    onClick,
    onKeyDown,
    ...props
}: TextareaProps) {
    const generatedId = React.useId();
    const textareaId = id ?? (label ? generatedId : undefined);
    const activation = useRecordEditActivation();
    const canActivate =
        editActivation &&
        props.readOnly &&
        !props.disabled &&
        activation?.canActivate;

    const textarea = (
        <textarea
            id={textareaId}
            data-slot="textarea"
            required={required}
            placeholder={label ? ' ' : placeholder}
            className={cn(
                'field-sizing-content shadow-xs aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:border-input dark:bg-input/30 dark:text-foreground dark:aria-invalid:ring-destructive/40 flex min-h-16 w-full rounded-md border border-[#d9dfe7] bg-white px-3 py-2 text-base text-[#1f2937] outline-none transition-[color,box-shadow] placeholder:text-[#8a94a6] focus:border-[#0284c7] focus:ring-2 focus:ring-[#0284c7]/15 focus-visible:border-[#0284c7] focus-visible:ring-2 focus-visible:ring-[#0284c7]/15 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                label && 'peer',
                className,
            )}
            {...props}
            onClick={(event) => {
                onClick?.(event);

                if (!event.defaultPrevented && canActivate) {
                    activation?.activate(event.currentTarget, true);
                }
            }}
            onKeyDown={(event) => {
                onKeyDown?.(event);

                if (
                    !event.defaultPrevented &&
                    canActivate &&
                    !event.nativeEvent.isComposing &&
                    (event.key === 'Enter' || event.key === ' ') &&
                    activation?.activate(event.currentTarget, true)
                ) {
                    event.preventDefault();
                }
            }}
        />
    );

    if (!label) {
        return textarea;
    }

    return (
        <div className="relative w-full">
            {textarea}
            <label
                htmlFor={textareaId}
                className="text-foreground dark:bg-input/30 absolute start-3 top-0 z-10 -translate-y-1/2 bg-white px-1 text-xs leading-none"
            >
                {label}
                {required && <span className="text-destructive"> *</span>}
            </label>
        </div>
    );
}

export { Textarea };
