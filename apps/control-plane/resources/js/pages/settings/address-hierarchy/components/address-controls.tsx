import * as React from 'react';
import { useState, useMemo, useEffect, useRef } from 'react';
import { Check, ChevronDown, Search, X } from 'lucide-react';
import { Label } from '@apperp/ui/label';
import { Switch } from '@apperp/ui/switch';

export function AddressFieldGroup({
    label,
    children,
    className = '',
    floating = true,
    alwaysFloating = true,
    required = false,
    size = 'md',
}: {
    label: string;
    children: React.ReactNode;
    className?: string;
    floating?: boolean;
    alwaysFloating?: boolean;
    required?: boolean;
    size?: 'sm' | 'md';
}) {
    if (floating && React.isValidElement(children)) {
        // If child is a toggle/switch, render standard label next to/above it
        const isToggle = (children.props as any).checked !== undefined;

        if (!isToggle) {
            return (
                <div className={`relative ${className}`}>
                    {React.cloneElement(children as React.ReactElement<any>, {
                        label: (children.props as any).label ?? label,
                        required: (children.props as any).required ?? required,
                        alwaysFloating:
                            (children.props as any).alwaysFloating ??
                            alwaysFloating,
                        size: (children.props as any).size ?? size,
                    })}
                </div>
            );
        }
    }

    return (
        <div className={`flex flex-col gap-2 ${className}`}>
            <Label className="text-xs font-normal text-[#605e5c] select-none">
                {label}
                {required && <span className="text-red-500 font-semibold"> *</span>}
            </Label>
            {children}
        </div>
    );
}

export function AddressInput({
    label,
    value,
    onChange,
    onFocus,
    onBlur,
    disabled = false,
    placeholder = '',
    className = '',
    type = 'text',
    required = false,
    alwaysFloating = true,
    size = 'md',
}: {
    label?: string;
    value?: string;
    onChange?: (val: string) => void;
    onFocus?: (e: React.FocusEvent<HTMLInputElement>) => void;
    onBlur?: (e: React.FocusEvent<HTMLInputElement>) => void;
    disabled?: boolean;
    placeholder?: string;
    className?: string;
    type?: string;
    required?: boolean;
    alwaysFloating?: boolean;
    size?: 'sm' | 'md';
}) {
    const inputId = React.useId();
    const [isFocused, setIsFocused] = useState(false);
    const hasValue = Boolean(
        value !== undefined && value !== null && String(value).trim() !== '',
    );
    const isFloating = alwaysFloating || isFocused || hasValue;
    const isSm = size === 'sm';

    const inputElement = (
        <input
            id={inputId}
            type={type}
            value={value ?? ''}
            disabled={disabled}
            required={required}
            placeholder={isFloating ? placeholder : ' '}
            onFocus={(e) => {
                setIsFocused(true);
                onFocus?.(e);
            }}
            onBlur={(e) => {
                setIsFocused(false);
                onBlur?.(e);
            }}
            onChange={(e: React.ChangeEvent<HTMLInputElement>) =>
                onChange && onChange(e.target.value)
            }
            className={`peer ${
                isSm ? 'h-9 px-3 text-xs' : 'h-10 px-3.5 text-sm'
            } w-full rounded-md border border-[#d9dfe7] bg-white text-[#1f2937] shadow-2xs transition-all outline-none focus:border-[#0284c7] focus:ring-2 focus:ring-[#0284c7]/15 disabled:cursor-not-allowed disabled:border-[#d1d5db] disabled:bg-[#f3f2f1] disabled:text-[#605e5c] disabled:opacity-85 ${
                disabled ? 'cursor-not-allowed' : ''
            } ${className}`}
        />
    );

    if (!label) {
        return inputElement;
    }

    return (
        <div className="relative w-full">
            {inputElement}
            <label
                htmlFor={inputId}
                className={`pointer-events-none absolute z-10 leading-none transition-all duration-150 ease-out select-none ${
                    isFloating
                        ? `${
                              isSm
                                  ? 'start-2.5 top-0 text-[11px] px-1.5 py-0'
                                  : 'start-3 top-0 text-xs px-1.5 py-0'
                          } -translate-y-1/2 font-normal ${
                              disabled
                                  ? 'bg-[#f3f2f1] text-[#8a8886]'
                                  : isFocused
                                    ? 'bg-white text-[#0284c7]'
                                    : 'bg-white text-[#605e5c]'
                          }`
                        : `${
                              isSm
                                  ? 'start-3 top-1/2 text-xs'
                                  : 'start-3.5 top-1/2 text-sm'
                          } -translate-y-1/2 font-normal px-0 bg-transparent ${
                              disabled ? 'text-[#8a8886]' : 'text-[#605e5c]'
                          }`
                }`}
            >
                {label}
                {required && <span className="text-red-500"> *</span>}
            </label>
        </div>
    );
}

export function AddressSelect({
    label,
    value,
    onChange,
    options,
    disabled = false,
    className = '',
    placeholder = '— Select —',
    required = false,
    alwaysFloating = true,
    size = 'md',
}: {
    label?: string;
    value: string;
    onChange?: (val: string) => void;
    options: { value: string; label: string }[];
    disabled?: boolean;
    className?: string;
    placeholder?: string;
    required?: boolean;
    alwaysFloating?: boolean;
    size?: 'sm' | 'md';
}) {
    const [isOpen, setIsOpen] = useState<boolean>(false);
    const [searchQuery, setSearchQuery] = useState<string>('');
    const [displayLimit, setDisplayLimit] = useState<number>(200);
    const containerRef = useRef<HTMLDivElement>(null);
    const searchInputRef = useRef<HTMLInputElement>(null);

    const selectedOption = useMemo(() => {
        return options.find((opt) => opt.value === value);
    }, [options, value]);

    const hasValue = Boolean(
        (value !== null && value !== undefined && value !== '') ||
        (selectedOption && selectedOption.value !== ''),
    );
    const isFloating = Boolean(
        alwaysFloating ||
        isOpen ||
        hasValue ||
        (selectedOption && selectedOption.label),
    );
    const isSm = size === 'sm';

    const filteredOptions = useMemo(() => {
        const q = searchQuery.trim().toLowerCase();

        if (!q) {
            return options;
        }

        return options.filter((opt) => opt.label.toLowerCase().includes(q));
    }, [options, searchQuery]);

    const handleScroll = (e: React.UIEvent<HTMLDivElement>) => {
        const { scrollTop, scrollHeight, clientHeight } = e.currentTarget;

        if (scrollTop + clientHeight >= scrollHeight - 80) {
            setDisplayLimit((prev) =>
                Math.min(prev + 200, filteredOptions.length),
            );
        }
    };

    useEffect(() => {
        if (!isOpen) {
            return;
        }

        const handleOutsideClick = (e: MouseEvent) => {
            if (
                containerRef.current &&
                !containerRef.current.contains(e.target as Node)
            ) {
                setIsOpen(false);
                setSearchQuery('');
            }
        };
        document.addEventListener('mousedown', handleOutsideClick);

        return () => {
            document.removeEventListener('mousedown', handleOutsideClick);
        };
    }, [isOpen]);

    useEffect(() => {
        if (isOpen && searchInputRef.current) {
            searchInputRef.current.focus({ preventScroll: true });
        }
    }, [isOpen]);

    return (
        <div ref={containerRef} className={`relative w-full ${className}`}>
            <select
                aria-hidden="true"
                tabIndex={-1}
                value={value ?? ''}
                disabled={disabled}
                onChange={(e) => onChange?.(e.target.value)}
                className="pointer-events-none sr-only"
            >
                {options.map((opt) => (
                    <option key={opt.value} value={opt.value}>
                        {opt.label}
                    </option>
                ))}
            </select>

            <button
                type="button"
                disabled={disabled}
                onClick={() => {
                    if (disabled) {
                        return;
                    }

                    setIsOpen((prev) => {
                        if (!prev) {
                            setDisplayLimit(200);
                        }

                        return !prev;
                    });
                    setSearchQuery('');
                }}
                className={`${
                    isSm ? 'h-9 pl-3 pr-7 text-xs' : 'h-10 pl-3.5 pr-9 text-sm'
                } w-full border bg-white ${
                    isOpen
                        ? 'border-[#0284c7] ring-2 ring-[#0284c7]/15'
                        : 'border-[#d9dfe7] hover:border-[#b0b8c4]'
                } flex items-center justify-between rounded-md text-left text-[#1f2937] shadow-2xs transition-all select-none focus:border-[#0284c7] focus:ring-2 focus:ring-[#0284c7]/15 focus:outline-none disabled:cursor-not-allowed disabled:border-[#d1d5db] disabled:bg-[#f3f2f1] disabled:text-[#605e5c] disabled:opacity-85 ${
                    disabled ? 'cursor-not-allowed' : 'cursor-pointer'
                }`}
            >
                <span className="truncate">
                    {selectedOption ? (
                        selectedOption.label
                    ) : !label ? (
                        <span className="text-[#8a94a6]">{placeholder}</span>
                    ) : isFloating ? (
                        <span className="text-[#8a94a6]">{placeholder}</span>
                    ) : null}
                </span>
                <ChevronDown
                    size={isSm ? 13 : 15}
                    className={`pointer-events-none absolute ${
                        isSm ? 'top-2.5 right-2' : 'top-3 right-3'
                    } text-[#605e5c] transition-transform duration-150 ${
                        isOpen ? 'rotate-180 text-[#0284c7]' : ''
                    }`}
                />
            </button>

            {label && (
                <label
                    className={`pointer-events-none absolute z-10 leading-none transition-all duration-150 ease-out select-none ${
                        isFloating
                            ? `${
                                  isSm
                                      ? 'start-2.5 top-0 text-[11px] px-1.5 py-0'
                                      : 'start-3 top-0 text-xs px-1.5 py-0'
                              } -translate-y-1/2 font-normal ${
                                  disabled
                                      ? 'bg-[#f3f2f1] text-[#8a8886]'
                                      : isOpen
                                        ? 'bg-white text-[#0284c7]'
                                        : 'bg-white text-[#605e5c]'
                              }`
                            : `${
                                  isSm
                                      ? 'start-3 top-1/2 text-xs'
                                      : 'start-3.5 top-1/2 text-sm'
                              } -translate-y-1/2 font-normal px-0 bg-transparent ${
                                  disabled ? 'text-[#8a8886]' : 'text-[#605e5c]'
                              }`
                    }`}
                >
                    {label}
                    {required && <span className="text-red-500"> *</span>}
                </label>
            )}

            {isOpen && (
                <div className="absolute top-[100%] left-0 z-50 mt-1 flex w-max max-w-[420px] min-w-full flex-col overflow-hidden rounded-md border border-[#d9dfe7] bg-white py-1 text-[#1f2937] shadow-lg">
                    {options.length > 5 && (
                        <div className="border-b border-[#edebe9] bg-[#faf9f8] p-2">
                            <div className="relative flex items-center">
                                <Search
                                    size={14}
                                    className="pointer-events-none absolute left-2.5 text-[#8a94a6]"
                                />
                                <input
                                    ref={searchInputRef}
                                    type="text"
                                    value={searchQuery}
                                    onChange={(e) => {
                                        setSearchQuery(e.target.value);
                                        setDisplayLimit(200);
                                    }}
                                    placeholder="Search..."
                                    className="h-8 w-full rounded-md border border-[#d9dfe7] bg-white pr-7 pl-8 text-xs text-[#1f2937] placeholder:text-[#8a94a6] focus:border-[#0284c7] focus:ring-2 focus:ring-[#0284c7]/15 focus:outline-none"
                                    onClick={(e) => e.stopPropagation()}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Escape') {
                                            setIsOpen(false);
                                            setSearchQuery('');
                                        } else if (
                                            e.key === 'Enter' &&
                                            filteredOptions.length > 0
                                        ) {
                                            e.preventDefault();
                                            onChange?.(
                                                filteredOptions[0].value,
                                            );
                                            setIsOpen(false);
                                            setSearchQuery('');
                                        }
                                    }}
                                />
                                {searchQuery && (
                                    <button
                                        type="button"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            setSearchQuery('');
                                            searchInputRef.current?.focus();
                                        }}
                                        className="absolute right-2 cursor-pointer text-[#8a94a6] hover:text-[#1f2937]"
                                    >
                                        <X size={12} />
                                    </button>
                                )}
                            </div>
                        </div>
                    )}

                    <div
                        onScroll={handleScroll}
                        className="max-h-60 divide-y divide-[#f8f9fa] overflow-y-auto"
                    >
                        {filteredOptions.length === 0 ? (
                            <div className="px-3 py-3 text-center text-xs text-[#8a94a6]">
                                No matches found for &ldquo;{searchQuery}&rdquo;
                            </div>
                        ) : (
                            <>
                                {filteredOptions
                                    .slice(0, displayLimit)
                                    .map((opt) => {
                                        const isSelected = opt.value === value;

                                        return (
                                            <button
                                                key={opt.value}
                                                type="button"
                                                onClick={() => {
                                                    onChange?.(opt.value);
                                                    setIsOpen(false);
                                                    setSearchQuery('');
                                                }}
                                                className={`flex w-full cursor-pointer items-center justify-between px-3 py-2 text-left text-xs transition-colors ${
                                                    isSelected
                                                        ? 'bg-[#eff6fc] font-medium text-[#0284c7]'
                                                        : 'text-[#1f2937] hover:bg-[#f3f4f6]'
                                                }`}
                                            >
                                                <span className="truncate pr-2">
                                                    {opt.label}
                                                </span>
                                                {isSelected && (
                                                    <Check
                                                        size={14}
                                                        className="shrink-0 text-[#0284c7]"
                                                    />
                                                )}
                                            </button>
                                        );
                                    })}
                                {filteredOptions.length > displayLimit && (
                                    <div className="bg-[#faf9f8] px-3 py-1.5 text-center text-[11px] text-[#8a94a6] select-none">
                                        Showing {displayLimit} of{' '}
                                        {filteredOptions.length} (scroll for
                                        more)
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

export function AddressToggle({
    checked,
    onChange,
    labelNo = 'No',
    labelYes = 'Yes',
    disabled = false,
}: {
    checked: boolean;
    onChange: (val: boolean) => void;
    labelNo?: string;
    labelYes?: string;
    disabled?: boolean;
}) {
    return (
        <div
            className={`flex h-10 items-center gap-2.5 select-none ${disabled ? 'cursor-not-allowed opacity-60' : ''}`}
        >
            <Switch
                checked={checked}
                onCheckedChange={onChange}
                disabled={disabled}
            />
            <span
                className={`text-sm font-normal ${disabled ? 'text-[#8a8886]' : 'text-[#1f2937]'}`}
            >
                {checked ? labelYes : labelNo}
            </span>
        </div>
    );
}
