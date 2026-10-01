import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';
import {
    Plus,
    Save,
    ExternalLink,
    Languages,
    Filter,
    Search,
    X,
    Pencil,
    Archive,
} from 'lucide-react';
import * as React from 'react';

export function AddressToolbar({
    title,
    newLabel,
    saveLabel,
    saveVariant = 'amber',
    onNew,
    onDelete,
    onSave,
    onExternalCodes,
    onTranslations,
    onFilterToggle,
    searchTerm = '',
    onSearchChange,
    isNew = false,
    isSaving = false,
    isDeleting = false,
    canDelete = false,
    canSave,
    canManageRelations = false,
    showTranslations = true,
    showNew = true,
    showDelete = true,
    showSave = true,
    showSearch = true,
    isFilterActive = false,
}: {
    title?: string;
    newLabel?: string;
    saveLabel?: string;
    saveVariant?: 'blue' | 'amber';
    onNew?: () => void;
    onDelete?: () => void;
    onSave?: () => void;
    onExternalCodes?: () => void;
    onTranslations?: () => void;
    onFilterToggle?: () => void;
    searchTerm?: string;
    onSearchChange?: (val: string) => void;
    isNew?: boolean;
    isSaving?: boolean;
    isDeleting?: boolean;
    canDelete?: boolean;
    canSave?: boolean;
    canManageRelations?: boolean;
    showTranslations?: boolean;
    showNew?: boolean;
    showDelete?: boolean;
    showSave?: boolean;
    showSearch?: boolean;
    isFilterActive?: boolean;
}) {
    return (
        <div className="flex min-h-[44px] shrink-0 flex-wrap items-center justify-between gap-2 border-b border-[#e5e7eb] bg-white px-4 py-2">
            <div className="flex flex-wrap items-center gap-2">
                {title && (
                    <span className="mr-2 text-sm font-semibold tracking-tight text-[#111827] select-none">
                        {title}
                    </span>
                )}

                {isNew ? (
                    <>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={onSave}
                            disabled={
                                isSaving || (canSave !== undefined && !canSave)
                            }
                            className="h-8 cursor-pointer gap-1.5 rounded-md border-[#0284c7]/40 bg-white px-3 text-xs font-medium text-[#0284c7] shadow-none transition-colors hover:bg-[#f0f9ff] hover:text-[#0369a1] disabled:opacity-40"
                        >
                            <Save className="size-3.5 stroke-[1.8] text-[#0284c7]" />
                            <span>
                                {isSaving
                                    ? 'Menyimpan…'
                                    : saveLabel || 'Simpan'}
                            </span>
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={onDelete}
                            className="h-8 cursor-pointer gap-1.5 rounded-md border-[#d1d5db] bg-white px-3 text-xs font-normal text-[#374151] shadow-none transition-colors hover:bg-[#f9fafb] hover:text-[#111827] disabled:opacity-40"
                        >
                            <X className="size-3.5 text-[#6b7280]" />
                            <span>Batal</span>
                        </Button>
                    </>
                ) : (
                    <>
                        {showSave && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={onSave}
                                disabled={
                                    isSaving ||
                                    (canSave !== undefined
                                        ? !canSave
                                        : !canDelete)
                                }
                                className={`h-8 cursor-pointer gap-1.5 rounded-md ${
                                    saveVariant === 'blue'
                                        ? 'border-[#0284c7]/40 text-[#0284c7] hover:bg-[#f0f9ff] hover:text-[#0369a1]'
                                        : 'border-[#f59e0b]/40 text-[#b45309] hover:bg-[#fffbeb] hover:text-[#92400e]'
                                } bg-white px-3 text-xs font-medium shadow-none transition-colors disabled:opacity-40`}
                            >
                                {saveVariant === 'blue' ? (
                                    <Save className="size-3.5 stroke-[1.8] text-[#0284c7]" />
                                ) : (
                                    <Pencil className="size-3.5 stroke-[1.8] text-[#b45309]" />
                                )}
                                <span>
                                    {isSaving
                                        ? 'Menyimpan…'
                                        : saveLabel || 'Ubah'}
                                </span>
                            </Button>
                        )}

                        {showNew && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={onNew}
                                disabled={isSaving || isDeleting}
                                className="h-8 cursor-pointer gap-1.5 rounded-md border-[#10b981]/40 bg-white px-3 text-xs font-medium text-[#047857] shadow-none transition-colors hover:bg-[#ecfdf5] hover:text-[#065f46] disabled:opacity-40"
                            >
                                <Plus className="size-3.5 stroke-[2.2] text-[#047857]" />
                                <span>
                                    {newLabel ||
                                        (title ? `Tambah ${title}` : 'Tambah')}
                                </span>
                            </Button>
                        )}

                        {onExternalCodes && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={onExternalCodes}
                                disabled={!canManageRelations}
                                className="h-8 cursor-pointer gap-1.5 rounded-md border-[#d1d5db] bg-white px-3 text-xs font-normal text-[#374151] shadow-none transition-colors hover:bg-[#f9fafb] hover:text-[#111827] disabled:opacity-40"
                            >
                                <ExternalLink className="size-3.5 text-[#6b7280]" />
                                <span>External Codes</span>
                            </Button>
                        )}

                        {showTranslations && onTranslations && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={onTranslations}
                                disabled={!canManageRelations}
                                className="h-8 cursor-pointer gap-1.5 rounded-md border-[#d1d5db] bg-white px-3 text-xs font-normal text-[#374151] shadow-none transition-colors hover:bg-[#f9fafb] hover:text-[#111827] disabled:opacity-40"
                            >
                                <Languages className="size-3.5 text-[#6b7280]" />
                                <span>Translations</span>
                            </Button>
                        )}

                        {onFilterToggle && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={onFilterToggle}
                                className={`h-8 cursor-pointer gap-1.5 rounded-md border-[#d1d5db] bg-white px-3 text-xs font-normal text-[#374151] shadow-none transition-colors hover:bg-[#f9fafb] hover:text-[#111827] ${
                                    isFilterActive
                                        ? 'border-[#9ca3af] bg-[#f3f4f6] font-medium text-[#111827]'
                                        : ''
                                }`}
                            >
                                <Filter className="size-3.5 text-[#6b7280]" />
                                <span>Filter</span>
                            </Button>
                        )}

                        {showDelete && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={onDelete}
                                disabled={!canDelete || isDeleting}
                                className="h-8 cursor-pointer gap-1.5 rounded-md border-[#f43f5e]/40 bg-white px-3 text-xs font-medium text-[#e11d48] shadow-none transition-colors hover:bg-[#fff1f2] hover:text-[#be123c] disabled:opacity-40"
                            >
                                <Archive className="size-3.5 stroke-[1.8] text-[#e11d48]" />
                                <span>
                                    {isDeleting
                                        ? 'Mengarsipkan...'
                                        : 'Arsipkan'}
                                </span>
                            </Button>
                        )}
                    </>
                )}
            </div>

            {showSearch && onSearchChange && (
                <div className="relative flex items-center">
                    <Search
                        size={13}
                        className="pointer-events-none absolute left-2.5 text-[#8a94a6]"
                    />
                    <Input
                        value={searchTerm}
                        onChange={(e: React.ChangeEvent<HTMLInputElement>) =>
                            onSearchChange(e.target.value)
                        }
                        placeholder="Search..."
                        className="h-8 w-[160px] pr-6 pl-8 text-xs lg:w-[220px]"
                    />
                    {searchTerm && (
                        <button
                            type="button"
                            onClick={() => onSearchChange('')}
                            className="absolute right-2 cursor-pointer text-[#8a94a6] hover:text-[#1f2937]"
                        >
                            <X size={12} />
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
