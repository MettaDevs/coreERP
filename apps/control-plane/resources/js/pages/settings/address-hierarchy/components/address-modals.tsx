import * as React from 'react';
import { useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import { AddressSelect } from './address-controls';
import type { ExternalCode, TranslationItem } from '../types';

export function DeleteConfirmDialog({
    open,
    onOpenChange,
    targetName,
    isDeleting,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    targetName: string;
    isDeleting: boolean;
    onConfirm: () => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md bg-white text-[#242424]">
                <DialogHeader>
                    <DialogTitle className="text-sm font-semibold text-[#242424]">Confirm Deletion</DialogTitle>
                    <DialogDescription className="text-xs text-[#605e5c]">
                        Are you sure you want to delete &ldquo;{targetName}&rdquo;? Child records may block this action if dependencies exist.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter className="gap-2 sm:gap-0">
                    <button
                        type="button"
                        onClick={() => onOpenChange(false)}
                        className="px-3 py-1.5 text-xs bg-white border border-gray-300 rounded-[2px] hover:bg-gray-50 cursor-pointer"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={onConfirm}
                        disabled={isDeleting}
                        className="px-3 py-1.5 text-xs bg-red-600 text-white rounded-[2px] hover:bg-red-700 disabled:opacity-50 cursor-pointer"
                    >
                        {isDeleting ? 'Deleting...' : 'Delete'}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function ExternalCodesModal({
    open,
    onOpenChange,
    entityLabel,
    entityId,
    list,
    isLoading,
    onDelete,
    onAdd,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    entityLabel: string;
    entityId: string;
    list: ExternalCode[];
    isLoading: boolean;
    onDelete: (id: string) => void;
    onAdd: (system: string, code: string, desc: string) => void;
}) {
    const [system, setSystem] = useState('');
    const [code, setCode] = useState('');
    const [desc, setDesc] = useState('');

    const handleAdd = () => {
        if (!system.trim() || !code.trim()) return;
        onAdd(system, code, desc);
        setSystem('');
        setCode('');
        setDesc('');
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-2xl bg-white text-[#242424]">
                <DialogHeader>
                    <DialogTitle className="text-sm font-semibold text-[#242424]">External Codes Mapping</DialogTitle>
                    <DialogDescription className="text-xs text-[#605e5c]">
                        Map internal address codes to external systems (e.g. Tax Authority / DJP, EDI partners, DHL/Logistics, SAP Legacy).
                    </DialogDescription>
                </DialogHeader>

                <div className="py-2 space-y-3">
                    <div className="text-[11.5px] bg-[#f3f2f1] px-2.5 py-1.5 rounded-[2px] text-[#323130] font-medium flex items-center justify-between">
                        <span>Target Entity: <span className="font-semibold text-[#0078d4]">{entityLabel}</span></span>
                        <span className="text-gray-500 text-[11px]">ID: {entityId}</span>
                    </div>

                    <div className="border border-[#edebe9] rounded-[2px] overflow-hidden max-h-48 overflow-y-auto">
                        <table className="w-full text-left text-[11.5px] border-collapse">
                            <thead>
                                <tr className="bg-[#faf9f8] border-b border-[#edebe9] text-[#605e5c]">
                                    <th className="px-3 py-1.5 font-semibold w-1/3">External System</th>
                                    <th className="px-3 py-1.5 font-semibold w-1/3">External Code</th>
                                    <th className="px-3 py-1.5 font-semibold">Description</th>
                                    <th className="px-3 py-1.5 font-semibold w-12 text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {isLoading ? (
                                    <tr>
                                        <td colSpan={4} className="px-3 py-4 text-center text-gray-400">Loading mappings...</td>
                                    </tr>
                                ) : list.length === 0 ? (
                                    <tr>
                                        <td colSpan={4} className="px-3 py-4 text-center text-gray-400">No external codes mapped yet for this record.</td>
                                    </tr>
                                ) : (
                                    list.map((item) => (
                                        <tr key={item.id} className="border-b border-[#edebe9] hover:bg-[#f3f2f1]">
                                            <td className="px-3 py-1.5 font-medium font-mono text-[#0078d4]">{item.system}</td>
                                            <td className="px-3 py-1.5 font-medium text-[#242424]">{item.external_code}</td>
                                            <td className="px-3 py-1.5 text-gray-600">{item.description || '—'}</td>
                                            <td className="px-3 py-1.5 text-center">
                                                <button
                                                    type="button"
                                                    onClick={() => onDelete(item.id)}
                                                    className="text-red-500 hover:text-red-700 p-0.5 cursor-pointer"
                                                    title="Delete mapping"
                                                >
                                                    <Trash2 size={12} />
                                                </button>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className="bg-[#f8f9fa] border border-[#edebe9] p-2.5 rounded-[2px] space-y-2">
                        <div className="text-[11.5px] font-semibold text-[#323130] flex items-center gap-1.5">
                            <Plus size={12} className="text-[#0078d4]" />
                            <span>Add External Code Mapping</span>
                        </div>
                        <div className="grid grid-cols-3 gap-2">
                            <div>
                                <label className="block text-[10.5px] text-gray-500 mb-0.5">System</label>
                                <input
                                    type="text"
                                    value={system}
                                    onChange={(e) => setSystem(e.target.value)}
                                    placeholder="e.g. DJP_PAJAK, DHL"
                                    className="w-full h-[26px] px-2 text-[11.5px] border border-[#8a8886] rounded-[2px] bg-white text-[#242424]"
                                />
                            </div>
                            <div>
                                <label className="block text-[10.5px] text-gray-500 mb-0.5">External Code</label>
                                <input
                                    type="text"
                                    value={code}
                                    onChange={(e) => setCode(e.target.value)}
                                    placeholder="e.g. DJP-3200, CGK-01"
                                    className="w-full h-[26px] px-2 text-[11.5px] border border-[#8a8886] rounded-[2px] bg-white text-[#242424]"
                                />
                            </div>
                            <div>
                                <label className="block text-[10.5px] text-gray-500 mb-0.5">Description</label>
                                <input
                                    type="text"
                                    value={desc}
                                    onChange={(e) => setDesc(e.target.value)}
                                    placeholder="Optional note"
                                    className="w-full h-[26px] px-2 text-[11.5px] border border-[#8a8886] rounded-[2px] bg-white text-[#242424]"
                                />
                            </div>
                        </div>
                        <div className="flex justify-end">
                            <button
                                type="button"
                                onClick={handleAdd}
                                className="px-2.5 py-1 text-[11.5px] bg-[#0078d4] text-white rounded-[2px] hover:bg-[#106ebe] cursor-pointer"
                            >
                                + Add Code
                            </button>
                        </div>
                    </div>
                </div>

                <DialogFooter>
                    <button
                        type="button"
                        onClick={() => onOpenChange(false)}
                        className="px-3 py-1.5 text-xs bg-white border border-gray-300 rounded-[2px] hover:bg-gray-50 cursor-pointer"
                    >
                        Done
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

const LANGUAGE_OPTIONS = [
    { value: 'en-US', label: 'en-US (English - US)' },
    { value: 'id-ID', label: 'id-ID (Indonesian / Bahasa Indonesia)' },
    { value: 'ms-MY', label: 'ms-MY (Malay / Bahasa Melayu)' },
    { value: 'th-TH', label: 'th-TH (Thai / ไทย)' },
    { value: 'vi-VN', label: 'vi-VN (Vietnamese / Tiếng Việt)' },
    { value: 'tl-PH', label: 'tl-PH (Filipino / Tagalog)' },
    { value: 'ja-JP', label: 'ja-JP (Japanese / 日本語)' },
    { value: 'zh-CN', label: 'zh-CN (Chinese / 中文)' },
    { value: 'ar-SA', label: 'ar-SA (Arabic / العربية)' },
    { value: 'de-DE', label: 'de-DE (German / Deutsch)' },
    { value: 'fr-FR', label: 'fr-FR (French / Français)' },
    { value: 'es-ES', label: 'es-ES (Spanish / Español)' },
    { value: 'pt-BR', label: 'pt-BR (Portuguese)' },
];

export function TranslationsModal({
    open,
    onOpenChange,
    entityLabel,
    defaultName,
    list,
    isLoading,
    onDelete,
    onAdd,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    entityLabel: string;
    defaultName: string;
    list: TranslationItem[];
    isLoading: boolean;
    onDelete: (id: string) => void;
    onAdd: (locale: string, name: string, desc: string) => void;
}) {
    const [locale, setLocale] = useState('en-US');
    const [name, setName] = useState('');
    const [desc, setDesc] = useState('');

    const handleAdd = () => {
        if (!name.trim()) return;
        onAdd(locale, name, desc);
        setName('');
        setDesc('');
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-2xl bg-white text-[#242424]">
                <DialogHeader>
                    <DialogTitle className="text-sm font-semibold text-[#242424]">Multilingual Translations</DialogTitle>
                    <DialogDescription className="text-xs text-[#605e5c]">
                        Manage localized country and division names for international documents (invoices, packing slips, legal forms).
                    </DialogDescription>
                </DialogHeader>

                <div className="py-2 space-y-3">
                    <div className="text-[11.5px] bg-[#f3f2f1] px-2.5 py-1.5 rounded-[2px] text-[#323130] font-medium flex items-center justify-between">
                        <span>Target Entity: <span className="font-semibold text-[#0078d4]">{entityLabel}</span></span>
                        <span className="text-gray-500 text-[11px]">Default: {defaultName || '—'}</span>
                    </div>

                    <div className="border border-[#edebe9] rounded-[2px] overflow-hidden max-h-48 overflow-y-auto">
                        <table className="w-full text-left text-[11.5px] border-collapse">
                            <thead>
                                <tr className="bg-[#faf9f8] border-b border-[#edebe9] text-[#605e5c]">
                                    <th className="px-3 py-1.5 font-semibold w-1/4">Language / Locale</th>
                                    <th className="px-3 py-1.5 font-semibold w-1/3">Translated Text</th>
                                    <th className="px-3 py-1.5 font-semibold">Description</th>
                                    <th className="px-3 py-1.5 font-semibold w-12 text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {isLoading ? (
                                    <tr>
                                        <td colSpan={4} className="px-3 py-4 text-center text-gray-400">Loading translations...</td>
                                    </tr>
                                ) : list.length === 0 ? (
                                    <tr>
                                        <td colSpan={4} className="px-3 py-4 text-center text-gray-400">No translations added yet for this record.</td>
                                    </tr>
                                ) : (
                                    list.map((item) => (
                                        <tr key={item.id} className="border-b border-[#edebe9] hover:bg-[#f3f2f1]">
                                            <td className="px-3 py-1.5 font-medium font-mono text-[#0078d4]">{item.locale}</td>
                                            <td className="px-3 py-1.5 font-medium text-[#242424]">{item.name}</td>
                                            <td className="px-3 py-1.5 text-gray-600">{item.description || '—'}</td>
                                            <td className="px-3 py-1.5 text-center">
                                                <button
                                                    type="button"
                                                    onClick={() => onDelete(item.id)}
                                                    className="text-red-500 hover:text-red-700 p-0.5 cursor-pointer"
                                                    title="Delete translation"
                                                >
                                                    <Trash2 size={12} />
                                                </button>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className="bg-[#f8f9fa] border border-[#edebe9] p-2.5 rounded-[2px] space-y-2">
                        <div className="text-[11.5px] font-semibold text-[#323130] flex items-center gap-1.5">
                            <Plus size={12} className="text-[#0078d4]" />
                            <span>Add Translation</span>
                        </div>
                        <div className="grid grid-cols-3 gap-2">
                            <div>
                                <label className="block text-[10.5px] text-gray-500 mb-0.5">Language / Locale</label>
                                <AddressSelect
                                    value={locale}
                                    onChange={(val) => setLocale(val)}
                                    options={LANGUAGE_OPTIONS}
                                />
                            </div>
                            <div>
                                <label className="block text-[10.5px] text-gray-500 mb-0.5">Translated Name</label>
                                <input
                                    type="text"
                                    value={name}
                                    onChange={(e) => setName(e.target.value)}
                                    placeholder="e.g. 日本, جاكرتا, Indonesien"
                                    className="w-full h-[26px] px-2 text-[11.5px] border border-[#8a8886] rounded-[2px] bg-white text-[#242424]"
                                />
                            </div>
                            <div>
                                <label className="block text-[10.5px] text-gray-500 mb-0.5">Description</label>
                                <input
                                    type="text"
                                    value={desc}
                                    onChange={(e) => setDesc(e.target.value)}
                                    placeholder="Optional description"
                                    className="w-full h-[26px] px-2 text-[11.5px] border border-[#8a8886] rounded-[2px] bg-white text-[#242424]"
                                />
                            </div>
                        </div>
                        <div className="flex justify-end">
                            <button
                                type="button"
                                onClick={handleAdd}
                                className="px-2.5 py-1 text-[11.5px] bg-[#0078d4] text-white rounded-[2px] hover:bg-[#106ebe] cursor-pointer"
                            >
                                + Add Translation
                            </button>
                        </div>
                    </div>
                </div>

                <DialogFooter>
                    <button
                        type="button"
                        onClick={() => onOpenChange(false)}
                        className="px-3 py-1.5 text-xs bg-white border border-gray-300 rounded-[2px] hover:bg-gray-50 cursor-pointer"
                    >
                        Done
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
