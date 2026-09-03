import * as React from 'react';
import { useState, useMemo, useEffect, useRef } from 'react';
import { Check, ChevronDown, Search, X } from 'lucide-react';
import { Input } from '@apperp/ui/input';
import { Label } from '@apperp/ui/label';
import { Switch } from '@apperp/ui/switch';

export function AddressFieldGroup({
    label,
    children,
    className = '',
}: {
    label: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <div className={`flex flex-col gap-1.5 ${className}`}>
            <Label className="text-xs font-normal text-[#1f2937] select-none leading-none">{label}</Label>
            {children}
        </div>
    );
}

export function AddressInput({
    value,
    onChange,
    disabled = false,
    placeholder = '',
    className = '',
    type = 'text',
}: {
    value: string;
    onChange?: (val: string) => void;
    disabled?: boolean;
    placeholder?: string;
    className?: string;
    type?: string;
}) {
    return (
        <Input
            type={type}
            value={value ?? ''}
            disabled={disabled}
            placeholder={placeholder}
            onChange={(e: React.ChangeEvent<HTMLInputElement>) => onChange && onChange(e.target.value)}
            className={`h-9 w-full rounded-md border border-[#d9dfe7] bg-white px-3 py-1 text-sm text-[#1f2937] shadow-xs transition-all outline-none focus-visible:border-[#0284c7] focus-visible:ring-2 focus-visible:ring-[#0284c7]/15 disabled:bg-[#f3f2f1] disabled:border-[#d1d5db] disabled:text-[#605e5c] disabled:opacity-75 ${className}`}
        />
    );
}

export function AddressSelect({
    value,
    onChange,
    options,
    disabled = false,
    className = '',
    placeholder = '— Select —',
}: {
    value: string;
    onChange?: (val: string) => void;
    options: { value: string; label: string }[];
    disabled?: boolean;
    className?: string;
    placeholder?: string;
}) {
    const [isOpen, setIsOpen] = useState<boolean>(false);
    const [searchQuery, setSearchQuery] = useState<string>('');
    const containerRef = useRef<HTMLDivElement>(null);
    const searchInputRef = useRef<HTMLInputElement>(null);

    const selectedOption = useMemo(() => {
        return options.find((opt) => opt.value === value);
    }, [options, value]);

    const filteredOptions = useMemo(() => {
        const q = searchQuery.trim().toLowerCase();
        if (!q) return options;
        return options.filter((opt) => opt.label.toLowerCase().includes(q));
    }, [options, searchQuery]);

    useEffect(() => {
        if (!isOpen) return;
        const handleOutsideClick = (e: MouseEvent) => {
            if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
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
            searchInputRef.current.focus();
        }
    }, [isOpen]);

    return (
        <div ref={containerRef} className={`relative w-full ${className}`}>
            <select
                aria-hidden="true"
                tabIndex={-1}
                value={value ?? ''}
                disabled={disabled}
                onChange={(e) => onChange && onChange(e.target.value)}
                className="sr-only pointer-events-none"
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
                    if (disabled) return;
                    setIsOpen((prev) => !prev);
                    setSearchQuery('');
                }}
                className={`h-9 w-full pl-3 pr-8 text-sm bg-white border ${
                    isOpen ? 'border-[#0284c7] ring-2 ring-[#0284c7]/15' : 'border-[#d9dfe7] hover:border-[#b0b8c4]'
                } rounded-md text-[#1f2937] text-left flex items-center justify-between shadow-xs focus:outline-none focus:border-[#0284c7] focus:ring-2 focus:ring-[#0284c7]/15 disabled:bg-[#f3f2f1] disabled:border-[#d1d5db] disabled:text-[#605e5c] disabled:opacity-75 transition-all cursor-pointer select-none`}
            >
                <span className="truncate">
                    {selectedOption ? selectedOption.label : <span className="text-[#8a94a6]">{placeholder}</span>}
                </span>
                <ChevronDown
                    size={15}
                    className={`absolute right-2.5 top-2.5 pointer-events-none text-[#605e5c] transition-transform duration-150 ${
                        isOpen ? 'rotate-180 text-[#0284c7]' : ''
                    }`}
                />
            </button>

            {isOpen && (
                <div className="absolute left-0 top-[100%] mt-1 min-w-full w-max max-w-[420px] bg-white border border-[#d9dfe7] rounded-md shadow-lg z-50 py-1 flex flex-col text-[#1f2937] overflow-hidden">
                    {options.length > 5 && (
                        <div className="p-2 border-b border-[#edebe9] bg-[#faf9f8]">
                            <div className="relative flex items-center">
                                <Search size={14} className="absolute left-2.5 text-[#8a94a6] pointer-events-none" />
                                <input
                                    ref={searchInputRef}
                                    type="text"
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    placeholder="Search..."
                                    className="w-full h-8 pl-8 pr-7 text-xs bg-white border border-[#d9dfe7] rounded-md text-[#1f2937] placeholder:text-[#8a94a6] focus:outline-none focus:border-[#0284c7] focus:ring-2 focus:ring-[#0284c7]/15"
                                    onClick={(e) => e.stopPropagation()}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Escape') {
                                            setIsOpen(false);
                                            setSearchQuery('');
                                        } else if (e.key === 'Enter' && filteredOptions.length > 0) {
                                            e.preventDefault();
                                            onChange && onChange(filteredOptions[0].value);
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
                                        className="absolute right-2 text-[#8a94a6] hover:text-[#1f2937] cursor-pointer"
                                    >
                                        <X size={12} />
                                    </button>
                                )}
                            </div>
                        </div>
                    )}

                    <div className="max-h-60 overflow-y-auto divide-y divide-[#f8f9fa]">
                        {filteredOptions.length === 0 ? (
                            <div className="px-3 py-3 text-center text-[#8a94a6] text-xs">
                                No matches found for &ldquo;{searchQuery}&rdquo;
                            </div>
                        ) : (
                            filteredOptions.map((opt) => {
                                const isSelected = opt.value === value;
                                return (
                                    <button
                                        key={opt.value}
                                        type="button"
                                        onClick={() => {
                                            onChange && onChange(opt.value);
                                            setIsOpen(false);
                                            setSearchQuery('');
                                        }}
                                        className={`w-full text-left px-3 py-2 text-xs flex items-center justify-between transition-colors cursor-pointer ${
                                            isSelected
                                                ? 'bg-[#eff6fc] text-[#0284c7] font-medium'
                                                : 'hover:bg-[#f3f4f6] text-[#1f2937]'
                                        }`}
                                    >
                                        <span className="truncate pr-2">{opt.label}</span>
                                        {isSelected && (
                                            <Check size={14} className="text-[#0284c7] shrink-0" />
                                        )}
                                    </button>
                                );
                            })
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
        <div className="flex items-center gap-2.5 h-9 select-none">
            <Switch
                checked={checked}
                onCheckedChange={onChange}
                disabled={disabled}
            />
            <span className="text-sm text-[#1f2937] font-normal">
                {checked ? labelYes : labelNo}
            </span>
        </div>
    );
}
