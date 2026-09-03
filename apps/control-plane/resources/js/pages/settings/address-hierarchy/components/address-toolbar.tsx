import * as React from 'react';
import { Plus, Trash2, Save, ExternalLink, Languages, Filter, Search, X } from 'lucide-react';
import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';

export function AddressPageHeader({ title }: { title: string }) {
    return (
        <div className="px-4 pt-3 pb-1 shrink-0">
            <h1 className="text-[15px] font-semibold text-[#242424] tracking-tight">{title}</h1>
        </div>
    );
}

export function AddressToolbar({
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
    canManageRelations = false,
    showTranslations = true,
    showNew = true,
    showDelete = true,
    showSave = true,
    showSearch = true,
}: {
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
    canManageRelations?: boolean;
    showTranslations?: boolean;
    showNew?: boolean;
    showDelete?: boolean;
    showSave?: boolean;
    showSearch?: boolean;
}) {
    return (
        <div className="px-4 py-1.5 border-b border-[#edebe9] flex items-center justify-between gap-4 text-xs shrink-0 bg-white min-h-[42px]">
            <div className="flex items-center gap-1.5 flex-wrap">
                {showNew && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={onNew}
                        disabled={isSaving || isDeleting}
                        className="h-8 px-2.5 text-xs text-[#0078d4] hover:bg-[#f3f2f1] hover:text-[#0078d4] font-normal gap-1.5 cursor-pointer rounded-md"
                    >
                        <Plus className="size-3.5 text-[#0078d4] stroke-[2.5]" />
                        <span>New</span>
                    </Button>
                )}

                {showDelete && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={onDelete}
                        disabled={(!canDelete && !isNew) || isDeleting}
                        className="h-8 px-2.5 text-xs text-[#0078d4] hover:bg-[#f3f2f1] hover:text-[#0078d4] font-normal gap-1.5 cursor-pointer disabled:opacity-40 rounded-md"
                    >
                        {isNew ? (
                            <>
                                <X className="size-3.5 text-[#0078d4]" />
                                <span>Discard</span>
                            </>
                        ) : (
                            <>
                                <Trash2 className="size-3.5 text-[#0078d4]" />
                                <span>{isDeleting ? 'Deleting...' : 'Delete'}</span>
                            </>
                        )}
                    </Button>
                )}

                {showSave && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={onSave}
                        disabled={isSaving}
                        className="h-8 px-2.5 text-xs text-[#0078d4] hover:bg-[#f3f2f1] hover:text-[#0078d4] font-normal gap-1.5 cursor-pointer disabled:opacity-40 rounded-md"
                    >
                        <Save className="size-3.5 text-[#0078d4]" />
                        <span>{isSaving ? 'Saving...' : 'Save'}</span>
                    </Button>
                )}

                {onExternalCodes && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={onExternalCodes}
                        disabled={!canManageRelations}
                        className="h-8 px-2.5 text-xs text-[#0078d4] hover:bg-[#f3f2f1] hover:text-[#0078d4] font-normal gap-1.5 cursor-pointer disabled:opacity-40 rounded-md"
                    >
                        <ExternalLink className="size-3.5 text-[#0078d4]" />
                        <span>External codes</span>
                    </Button>
                )}

                {showTranslations && onTranslations && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={onTranslations}
                        disabled={!canManageRelations}
                        className="h-8 px-2.5 text-xs text-[#0078d4] hover:bg-[#f3f2f1] hover:text-[#0078d4] font-normal gap-1.5 cursor-pointer disabled:opacity-40 rounded-md"
                    >
                        <Languages className="size-3.5 text-[#0078d4]" />
                        <span>Translations</span>
                    </Button>
                )}

                {onFilterToggle && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={onFilterToggle}
                        className="h-8 px-2.5 text-xs text-[#0078d4] hover:bg-[#f3f2f1] hover:text-[#0078d4] font-normal gap-1.5 cursor-pointer rounded-md"
                    >
                        <Filter className="size-3.5 text-[#0078d4]" />
                        <span>Filter</span>
                    </Button>
                )}
            </div>

            {showSearch && onSearchChange && (
                <div className="relative flex items-center">
                    <Search size={13} className="absolute left-2.5 text-[#8a94a6] pointer-events-none" />
                    <Input
                        value={searchTerm}
                        onChange={(e: React.ChangeEvent<HTMLInputElement>) => onSearchChange(e.target.value)}
                        placeholder="Search..."
                        className="h-8 pl-8 pr-6 text-xs w-[160px] lg:w-[220px]"
                    />
                    {searchTerm && (
                        <button
                            type="button"
                            onClick={() => onSearchChange('')}
                            className="absolute right-2 text-[#8a94a6] hover:text-[#1f2937] cursor-pointer"
                        >
                            <X size={12} />
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
