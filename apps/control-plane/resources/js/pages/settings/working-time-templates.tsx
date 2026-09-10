import { ActionButton } from '@apperp/ui/action-button';
import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import {
    Dialog,
    DialogAction,
    DialogBody,
    DialogCancel,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogToolbar,
} from '@apperp/ui/dialog';
import { Input } from '@apperp/ui/input';
import { Switch } from '@apperp/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Head, router } from '@inertiajs/react';
import { Copy, Plus, Save, Search, Trash2, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types/navigation';

// Urutan hari standar Dynamics 365: Senin, Selasa, Rabu, Kamis, Jumat, Sabtu, Minggu
const DAYS = [
    { index: 0, name: 'Senin' },
    { index: 1, name: 'Selasa' },
    { index: 2, name: 'Rabu' },
    { index: 3, name: 'Kamis' },
    { index: 4, name: 'Jumat' },
    { index: 5, name: 'Sabtu' },
    { index: 6, name: 'Minggu' },
];

function formatTimeString(raw: string): string {
    const clean = raw.trim().replace(/[^0-9:]/g, '');

    if (!clean) {
        return '';
    }

    if (clean === '24' || clean === '24:00' || clean === '2400') {
        return '24:00';
    }

    if (clean.includes(':')) {
        const parts = clean.split(':');
        const h = parseInt(parts[0], 10);
        const m = parseInt(parts[1], 10);

        if (isNaN(h) || isNaN(m)) {
            return '';
        }

        if (h === 24 && m === 0) {
            return '24:00';
        }

        const validH = Math.min(23, Math.max(0, h));
        const validM = Math.min(59, Math.max(0, m));

        return `${String(validH).padStart(2, '0')}:${String(validM).padStart(2, '0')}`;
    }

    if (/^\d{1,2}$/.test(clean)) {
        const h = Math.min(23, Math.max(0, parseInt(clean, 10)));

        return `${String(h).padStart(2, '0')}:00`;
    }

    if (/^\d{3}$/.test(clean)) {
        const h = parseInt(clean.slice(0, 1), 10);
        const m = Math.min(59, Math.max(0, parseInt(clean.slice(1, 3), 10)));

        return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
    }

    if (/^\d{4}$/.test(clean)) {
        const h = Math.min(23, Math.max(0, parseInt(clean.slice(0, 2), 10)));
        const m = Math.min(59, Math.max(0, parseInt(clean.slice(2, 4), 10)));

        return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
    }

    return '';
}

function computeHours(fromTime: string | null, toTime: string | null): number {
    if (!fromTime || !toTime) {
        return 0;
    }

    const f = formatTimeString(fromTime);
    const t = formatTimeString(toTime);

    if (!f || !t) {
        return 0;
    }

    const [h1, m1] = f.split(':').map(Number);
    const [h2, m2] = t.split(':').map(Number);
    const min1 = h1 * 60 + m1;
    const min2 = h2 * 60 + m2;

    if (min2 > min1) {
        return Math.round(((min2 - min1) / 60) * 100) / 100;
    }

    return 0;
}

type WorkingTimeLine = {
    id?: string;
    day_of_week: number;
    from_time: string | null;
    to_time: string | null;
    efficiency: number;
    property: string | null;
    closed_for_pickup: boolean;
    hours: number;
};

type WorkingTimeTemplate = {
    id: string;
    code: string;
    name: string;
    description: string | null;
    legal_entity_id: string;
    legal_entity_name?: string | null;
    company_code?: string | null;
    is_active: boolean;
    lines: WorkingTimeLine[];
};

type LegalEntity = {
    id: string;
    name: string;
    company_code: string | null;
};

type Props = {
    canManage: boolean;
    templates: WorkingTimeTemplate[];
    currentLegalEntity?: LegalEntity | null;
};

export default function WorkingTimeTemplates({
    canManage,
    templates,
    currentLegalEntity,
}: Props) {
    const [searchFilter, setSearchFilter] = useState('');
    const [selectedTemplateId, setSelectedTemplateId] = useState<string | null>(
        templates.length > 0
            ? (templates.find((t) => t.code === 'PROD-DAY')?.id ??
                  templates[0].id)
            : null,
    );

    const activeTemplate = useMemo(() => {
        return (
            templates.find((t) => t.id === selectedTemplateId) ??
            (templates.length > 0 ? templates[0] : null)
        );
    }, [templates, selectedTemplateId]);

    // Active Template Draft Lines & Headers
    const [draftLines, setDraftLines] = useState<WorkingTimeLine[]>(
        activeTemplate?.lines ?? [],
    );

    // CollapsibleSectionGroup uses string[] values
    const [expandedDays, setExpandedDays] = useState<string[]>(['1']);
    const [activeDay, setActiveDay] = useState<number>(1);
    const [selectedRowIndex, setSelectedRowIndex] = useState<number | null>(0);
    const [isSaving, setIsSaving] = useState(false);

    // Edit Mode State: view vs edit mode
    const [isEditing, setIsEditing] = useState(false);
    const [headerCode, setHeaderCode] = useState(activeTemplate?.code ?? '');
    const [headerName, setHeaderName] = useState(activeTemplate?.name ?? '');

    // Adjust state during render when activeTemplate changes
    const [prevTemplateId, setPrevTemplateId] = useState(activeTemplate?.id);

    if (activeTemplate && activeTemplate.id !== prevTemplateId) {
        setPrevTemplateId(activeTemplate.id);
        setDraftLines(activeTemplate.lines);
        setHeaderCode(activeTemplate.code);
        setHeaderName(activeTemplate.name);
        setIsEditing(false);
        setSelectedRowIndex(0);
    }

    const handleCancelEdit = () => {
        if (activeTemplate) {
            setDraftLines(activeTemplate.lines);
            setHeaderCode(activeTemplate.code);
            setHeaderName(activeTemplate.name);
        }

        setIsEditing(false);
    };

    // Create Template Dialog
    const [isCreateOpen, setIsCreateOpen] = useState(false);
    const [newCode, setNewCode] = useState('');
    const [newName, setNewName] = useState('');
    const [isCreating, setIsCreating] = useState(false);

    // Copy Template Dialog
    const [isCopyTemplateOpen, setIsCopyTemplateOpen] = useState(false);
    const [copyNewCode, setCopyNewCode] = useState('');
    const [copyNewName, setCopyNewName] = useState('');
    const [isCopying, setIsCopying] = useState(false);

    // Copy Day Modal state
    const [isCopyDayOpen, setIsCopyDayOpen] = useState(false);
    const [copyFromDay, setCopyFromDay] = useState<number>(1);
    const [copyToDays, setCopyToDays] = useState<number[]>([]);

    const filteredTemplates = useMemo(() => {
        return templates.filter((t) => {
            return (
                t.code.toLowerCase().includes(searchFilter.toLowerCase()) ||
                t.name.toLowerCase().includes(searchFilter.toLowerCase())
            );
        });
    }, [templates, searchFilter]);

    const getDayLines = (dayIndex: number) => {
        return draftLines.filter((l) => l.day_of_week === dayIndex);
    };

    const getDayClosed = (dayIndex: number) => {
        return draftLines.some(
            (l) => l.day_of_week === dayIndex && l.closed_for_pickup,
        );
    };

    const calculateDayHours = (dayIndex: number) => {
        const lines = getDayLines(dayIndex);
        const closed = getDayClosed(dayIndex);

        if (closed) {
            return 0;
        }

        return lines.reduce((acc, line) => {
            if (line.from_time && line.to_time) {
                const hrs = computeHours(line.from_time, line.to_time);

                if (hrs > 0) {
                    return acc + hrs;
                }
            }

            return acc + (line.hours || 0);
        }, 0);
    };

    const handleAddLine = (dayIndex: number) => {
        setIsEditing(true);
        const newLine: WorkingTimeLine = {
            day_of_week: dayIndex,
            from_time: '',
            to_time: '',
            efficiency: 100.0,
            property: null,
            closed_for_pickup: false,
            hours: 0.0,
        };

        setDraftLines((prev) => [...prev, newLine]);
        const dayCount = getDayLines(dayIndex).length;

        setSelectedRowIndex(dayCount);
        setActiveDay(dayIndex);
    };

    const handleRemoveLine = (dayIndex: number) => {
        setIsEditing(true);
        const dayIndices = draftLines
            .map((l, i) => (l.day_of_week === dayIndex ? i : -1))
            .filter((i) => i !== -1);

        if (dayIndices.length === 0) {
            return;
        }

        const targetIdx =
            selectedRowIndex !== null &&
            activeDay === dayIndex &&
            dayIndices[selectedRowIndex] !== undefined
                ? dayIndices[selectedRowIndex]
                : dayIndices[dayIndices.length - 1];

        setDraftLines((prev) => prev.filter((_, i) => i !== targetIdx));
        setSelectedRowIndex(null);
    };

    const handleDeleteSpecificLine = (dayIndex: number, indexInDay: number) => {
        setIsEditing(true);
        const dayIndices = draftLines
            .map((l, i) => (l.day_of_week === dayIndex ? i : -1))
            .filter((i) => i !== -1);
        const targetIdx = dayIndices[indexInDay];

        if (targetIdx !== undefined) {
            setDraftLines((prev) => prev.filter((_, i) => i !== targetIdx));
            setSelectedRowIndex(null);
        }
    };

    const handleUpdateLine = (
        dayIndex: number,
        indexInDay: number,
        field: keyof WorkingTimeLine,
        value: unknown,
    ) => {
        const dayIndices = draftLines
            .map((l, i) => (l.day_of_week === dayIndex ? i : -1))
            .filter((l) => l !== -1);
        const actualIdx = dayIndices[indexInDay];

        if (actualIdx !== undefined) {
            setDraftLines((prev) =>
                prev.map((line, i) =>
                    i === actualIdx ? { ...line, [field]: value } : line,
                ),
            );
        }
    };

    const handleToggleClosed = (dayIndex: number, closed: boolean) => {
        setIsEditing(true);
        const dayLines = getDayLines(dayIndex);

        if (dayLines.length === 0) {
            setDraftLines((prev) => [
                ...prev,
                {
                    day_of_week: dayIndex,
                    from_time: null,
                    to_time: null,
                    efficiency: 100,
                    property: null,
                    closed_for_pickup: closed,
                    hours: 0,
                },
            ]);
        } else {
            setDraftLines((prev) =>
                prev.map((line) =>
                    line.day_of_week === dayIndex
                        ? { ...line, closed_for_pickup: closed }
                        : line,
                ),
            );
        }
    };

    const handleSaveAll = () => {
        if (!activeTemplate) {
            return;
        }

        setIsSaving(true);

        const sanitizedLines = draftLines
            .filter(
                (l) =>
                    l.closed_for_pickup ||
                    (l.from_time && l.from_time.trim()) ||
                    (l.to_time && l.to_time.trim()) ||
                    (l.hours && l.hours > 0),
            )
            .map((l) => {
                const from = l.from_time ? formatTimeString(l.from_time) : null;
                const to = l.to_time ? formatTimeString(l.to_time) : null;
                const hrs = computeHours(from, to) || Number(l.hours) || 0;

                return {
                    day_of_week: l.day_of_week,
                    from_time: from || null,
                    to_time: to || null,
                    efficiency: Number(l.efficiency) || 100,
                    property: l.property ? l.property.trim() : null,
                    closed_for_pickup: Boolean(l.closed_for_pickup),
                    hours: hrs,
                };
            });

        router.put(
            `/settings/working-time-templates/${activeTemplate.id}`,
            {
                code: headerCode.toUpperCase().trim(),
                name: headerName.trim(),
                lines: sanitizedLines,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setIsEditing(false);
                },
                onFinish: () => setIsSaving(false),
                onError: (err) => {
                    console.error('Gagal menyimpan pola jam kerja:', err);
                },
            },
        );
    };

    const handleExecuteCopyDay = () => {
        setIsEditing(true);
        const sourceLines = draftLines.filter(
            (l) => l.day_of_week === copyFromDay,
        );
        const updated = draftLines.filter(
            (l) => !copyToDays.includes(l.day_of_week),
        );

        for (const destDay of copyToDays) {
            for (const line of sourceLines) {
                updated.push({
                    ...line,
                    id: undefined,
                    day_of_week: destDay,
                });
            }
        }

        setDraftLines(updated);
        setIsCopyDayOpen(false);
        setCopyToDays([]);
    };

    const handleCreateTemplate = () => {
        if (!newCode.trim() || !newName.trim()) {
            return;
        }

        setIsCreating(true);
        router.post(
            '/settings/working-time-templates',
            {
                code: newCode.trim().toUpperCase(),
                name: newName.trim(),
                ...(currentLegalEntity
                    ? { legal_entity_id: currentLegalEntity.id }
                    : {}),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setIsCreateOpen(false);
                    setNewCode('');
                    setNewName('');
                },
                onFinish: () => setIsCreating(false),
            },
        );
    };

    const handleDeleteTemplate = () => {
        if (!activeTemplate) {
            return;
        }

        if (
            !confirm(
                `Arsipkan pola jam kerja "${activeTemplate.name}" (${activeTemplate.code})?`,
            )
        ) {
            return;
        }

        router.delete(`/settings/working-time-templates/${activeTemplate.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                setSelectedTemplateId(null);
            },
        });
    };

    const handleOpenCopyTemplate = () => {
        if (!activeTemplate) {
            return;
        }

        setCopyNewCode(`${activeTemplate.code}-SALIN`);
        setCopyNewName(`${activeTemplate.name} (Salinan)`);
        setIsCopyTemplateOpen(true);
    };

    const handleExecuteCopyTemplate = () => {
        if (!activeTemplate || !copyNewCode.trim() || !copyNewName.trim()) {
            return;
        }

        setIsCopying(true);
        router.post(
            `/settings/working-time-templates/${activeTemplate.id}/copy`,
            {
                code: copyNewCode.trim().toUpperCase(),
                name: copyNewName.trim(),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setIsCopyTemplateOpen(false);
                },
                onFinish: () => setIsCopying(false),
            },
        );
    };

    return (
        <>
            <Head title="Pola jam kerja" />

            <div className="flex min-h-[calc(100vh-4rem)] flex-col bg-background">
                {/* Command Bar / Action Ribbon: Standard CoreERP Action Toolbar */}
                <div className="sticky top-0 z-20 flex shrink-0 flex-wrap items-center gap-2 border-b border-border bg-card px-4 py-2 text-xs">
                    <span className="mr-2 text-sm font-semibold text-foreground">
                        Pola jam kerja
                    </span>

                    {isEditing ? (
                        <>
                            <Button
                                variant="outline"
                                size="sm"
                                type="button"
                                onClick={handleSaveAll}
                                disabled={isSaving || !activeTemplate}
                                className="border-success/40 text-success hover:bg-success/10 hover:text-success focus-visible:ring-success/30"
                            >
                                <Save className="size-4" />
                                <span>
                                    {isSaving ? 'Menyimpan…' : 'Simpan'}
                                </span>
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                type="button"
                                onClick={handleCancelEdit}
                                disabled={isSaving}
                                className="border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive focus-visible:ring-destructive/30"
                            >
                                <X className="size-4" />
                                <span>Batal</span>
                            </Button>
                        </>
                    ) : (
                        <>
                            {canManage && (
                                <ActionButton
                                    action="edit"
                                    size="sm"
                                    type="button"
                                    disabled={!activeTemplate}
                                    onClick={() => setIsEditing(true)}
                                >
                                    Ubah
                                </ActionButton>
                            )}

                            {canManage && (
                                <ActionButton
                                    action="create"
                                    size="sm"
                                    type="button"
                                    onClick={() => setIsCreateOpen(true)}
                                >
                                    Tambah template
                                </ActionButton>
                            )}

                            {canManage && (
                                <ActionButton
                                    action="archive"
                                    size="sm"
                                    type="button"
                                    disabled={!activeTemplate}
                                    onClick={handleDeleteTemplate}
                                >
                                    Arsipkan
                                </ActionButton>
                            )}

                            {canManage && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    type="button"
                                    disabled={!activeTemplate}
                                    onClick={handleOpenCopyTemplate}
                                >
                                    <Copy className="size-4" />
                                    <span>Salin template</span>
                                </Button>
                            )}
                        </>
                    )}
                </div>

                {/* Main 2-Pane Layout: Left Pane Master List, Right Pane Detail Section */}
                <div className="flex flex-1 flex-col divide-y divide-border md:flex-row md:divide-x md:divide-y-0">
                    {/* Left Pane (Master List) */}
                    <aside className="flex w-full shrink-0 flex-col bg-card md:w-64 lg:w-72">
                        {/* Search Filter Box */}
                        <div className="border-b border-border p-2.5">
                            <div className="relative">
                                <Search className="absolute top-2.5 left-2.5 size-3.5 text-muted-foreground" />
                                <Input
                                    type="search"
                                    placeholder="Cari template…"
                                    value={searchFilter}
                                    onChange={(e) =>
                                        setSearchFilter(e.target.value)
                                    }
                                    className="h-8 bg-background pl-8 text-xs"
                                />
                            </div>
                        </div>

                        {/* List of Templates */}
                        <div className="divide-y divide-border overflow-y-auto">
                            {filteredTemplates.length === 0 ? (
                                <div className="p-4 text-center text-xs text-muted-foreground">
                                    Tidak ada template ditemukan.
                                </div>
                            ) : (
                                filteredTemplates.map((tmpl) => {
                                    const isSelected =
                                        tmpl.id === activeTemplate?.id;

                                    return (
                                        <button
                                            key={tmpl.id}
                                            type="button"
                                            onClick={() =>
                                                setSelectedTemplateId(tmpl.id)
                                            }
                                            className={`relative flex w-full flex-col px-3.5 py-2.5 text-left transition-colors ${
                                                isSelected
                                                    ? 'border-l-4 border-l-primary bg-accent/60'
                                                    : 'border-l-4 border-l-transparent hover:bg-muted/50'
                                            }`}
                                        >
                                            <span
                                                className={`text-xs font-semibold ${
                                                    isSelected
                                                        ? 'text-primary'
                                                        : 'text-foreground'
                                                }`}
                                            >
                                                {tmpl.code}
                                            </span>
                                            <span className="line-clamp-1 text-[11px] text-muted-foreground">
                                                {tmpl.name}
                                            </span>
                                        </button>
                                    );
                                })
                            )}
                        </div>
                    </aside>

                    {/* Right Pane (Detail Panel) */}
                    <main className="min-w-0 flex-1 bg-background p-4 lg:p-6">
                        {activeTemplate ? (
                            <div className="max-w-4xl space-y-4">
                                {/* Page Heading — text-xl per coreerp-page-standard */}
                                <h1 className="text-xl font-semibold tracking-tight">
                                    Pola jam kerja
                                </h1>

                                {/* Form Fields: Standard Input Component */}
                                <div className="grid max-w-xl gap-4 sm:grid-cols-2">
                                    <Input
                                        label="Pola jam kerja"
                                        value={
                                            isEditing
                                                ? headerCode
                                                : activeTemplate.code
                                        }
                                        disabled={!isEditing || !canManage}
                                        onChange={(e) =>
                                            setHeaderCode(
                                                e.target.value.toUpperCase(),
                                            )
                                        }
                                        className="font-medium uppercase"
                                    />

                                    <Input
                                        label="Nama"
                                        value={
                                            isEditing
                                                ? headerName
                                                : activeTemplate.name
                                        }
                                        disabled={!isEditing || !canManage}
                                        onChange={(e) =>
                                            setHeaderName(e.target.value)
                                        }
                                        className="font-medium"
                                    />
                                </div>

                                {/* Standard Collapsible Section Group */}
                                <CollapsibleSectionGroup
                                    value={expandedDays}
                                    onValueChange={setExpandedDays}
                                    className="space-y-3"
                                >
                                    {DAYS.map((day) => {
                                        const dayLines = getDayLines(day.index);
                                        const isClosed = getDayClosed(
                                            day.index,
                                        );
                                        const totalHours = calculateDayHours(
                                            day.index,
                                        );

                                        return (
                                            <CollapsibleSection
                                                key={day.index}
                                                value={String(day.index)}
                                                title={day.name}
                                            >
                                                {/* Day Toolbar: Tambah, Hapus, Salin hari — clean flat actions */}
                                                <div className="flex items-center gap-1 pb-2">
                                                    {canManage && (
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="xs"
                                                            onClick={() =>
                                                                handleAddLine(
                                                                    day.index,
                                                                )
                                                            }
                                                            disabled={isClosed}
                                                            className="gap-1.5 font-medium text-primary hover:bg-primary/10 hover:text-primary"
                                                        >
                                                            <Plus className="size-3.5" />
                                                            Tambah
                                                        </Button>
                                                    )}

                                                    {canManage && (
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="xs"
                                                            onClick={() =>
                                                                handleRemoveLine(
                                                                    day.index,
                                                                )
                                                            }
                                                            disabled={
                                                                dayLines.length ===
                                                                    0 ||
                                                                isClosed
                                                            }
                                                            className="gap-1.5 font-medium text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                                        >
                                                            <Trash2 className="size-3.5" />
                                                            Hapus
                                                        </Button>
                                                    )}

                                                    {canManage && (
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="xs"
                                                            onClick={() => {
                                                                setCopyFromDay(
                                                                    day.index,
                                                                );
                                                                setCopyToDays(
                                                                    [],
                                                                );
                                                                setIsCopyDayOpen(
                                                                    true,
                                                                );
                                                            }}
                                                            disabled={isClosed}
                                                            className="gap-1.5 font-medium text-muted-foreground hover:bg-muted hover:text-foreground"
                                                        >
                                                            <Copy className="size-3.5" />
                                                            Salin hari
                                                        </Button>
                                                    )}
                                                </div>

                                                {/* Lines Table using Standard @apperp/ui/table */}
                                                <div className="overflow-x-auto rounded-md border border-border bg-card">
                                                    <Table>
                                                        <TableHeader>
                                                            <TableRow>
                                                                <TableHead className="w-10 text-center"></TableHead>
                                                                <TableHead className="w-28 font-medium">
                                                                    Dari
                                                                </TableHead>
                                                                <TableHead className="w-28 font-medium">
                                                                    Sampai
                                                                </TableHead>
                                                                <TableHead className="w-28 font-medium">
                                                                    Efisiensi
                                                                    (%)
                                                                </TableHead>
                                                                <TableHead className="font-medium">
                                                                    Properti
                                                                </TableHead>
                                                                {isEditing && (
                                                                    <TableHead className="w-10 text-center"></TableHead>
                                                                )}
                                                            </TableRow>
                                                        </TableHeader>
                                                        <TableBody>
                                                            {dayLines.length ===
                                                            0 ? (
                                                                <TableRow>
                                                                    <TableCell
                                                                        colSpan={
                                                                            isEditing
                                                                                ? 6
                                                                                : 5
                                                                        }
                                                                        className="py-6 text-center text-xs text-muted-foreground"
                                                                    >
                                                                        Belum
                                                                        ada jam
                                                                        kerja
                                                                        untuk
                                                                        hari
                                                                        ini.
                                                                        {isEditing &&
                                                                            ' Klik "Tambah" di atas untuk menambahkan.'}
                                                                    </TableCell>
                                                                </TableRow>
                                                            ) : (
                                                                dayLines.map(
                                                                    (
                                                                        line,
                                                                        idx,
                                                                    ) => {
                                                                        const isSelected =
                                                                            selectedRowIndex ===
                                                                                idx &&
                                                                            activeDay ===
                                                                                day.index;

                                                                        return (
                                                                            <TableRow
                                                                                key={
                                                                                    idx
                                                                                }
                                                                                onClick={() => {
                                                                                    setSelectedRowIndex(
                                                                                        idx,
                                                                                    );
                                                                                    setActiveDay(
                                                                                        day.index,
                                                                                    );
                                                                                }}
                                                                                className={`cursor-pointer ${
                                                                                    isSelected
                                                                                        ? 'bg-accent/60'
                                                                                        : ''
                                                                                }`}
                                                                            >
                                                                                <TableCell className="text-center">
                                                                                    <input
                                                                                        type="radio"
                                                                                        name={`select-row-${day.index}`}
                                                                                        checked={
                                                                                            isSelected
                                                                                        }
                                                                                        onChange={() => {
                                                                                            setSelectedRowIndex(
                                                                                                idx,
                                                                                            );
                                                                                            setActiveDay(
                                                                                                day.index,
                                                                                            );
                                                                                        }}
                                                                                        className="size-3.5 accent-primary"
                                                                                    />
                                                                                </TableCell>
                                                                                {isEditing ? (
                                                                                    <>
                                                                                        <TableCell>
                                                                                            <Input
                                                                                                type="text"
                                                                                                inputMode="numeric"
                                                                                                maxLength={
                                                                                                    5
                                                                                                }
                                                                                                value={
                                                                                                    line.from_time
                                                                                                        ? line.from_time.slice(
                                                                                                              0,
                                                                                                              5,
                                                                                                          )
                                                                                                        : ''
                                                                                                }
                                                                                                disabled={
                                                                                                    isClosed
                                                                                                }
                                                                                                onChange={(
                                                                                                    e,
                                                                                                ) => {
                                                                                                    let val =
                                                                                                        e.target.value.replace(
                                                                                                            /[^0-9:]/g,
                                                                                                            '',
                                                                                                        );

                                                                                                    if (
                                                                                                        val.length >
                                                                                                        5
                                                                                                    ) {
                                                                                                        val =
                                                                                                            val.slice(
                                                                                                                0,
                                                                                                                5,
                                                                                                            );
                                                                                                    }

                                                                                                    handleUpdateLine(
                                                                                                        day.index,
                                                                                                        idx,
                                                                                                        'from_time',
                                                                                                        val,
                                                                                                    );
                                                                                                }}
                                                                                                onBlur={(
                                                                                                    e,
                                                                                                ) => {
                                                                                                    const formatted =
                                                                                                        formatTimeString(
                                                                                                            e
                                                                                                                .target
                                                                                                                .value,
                                                                                                        );
                                                                                                    handleUpdateLine(
                                                                                                        day.index,
                                                                                                        idx,
                                                                                                        'from_time',
                                                                                                        formatted,
                                                                                                    );

                                                                                                    if (
                                                                                                        formatted &&
                                                                                                        line.to_time
                                                                                                    ) {
                                                                                                        const hrs =
                                                                                                            computeHours(
                                                                                                                formatted,
                                                                                                                line.to_time,
                                                                                                            );
                                                                                                        handleUpdateLine(
                                                                                                            day.index,
                                                                                                            idx,
                                                                                                            'hours',
                                                                                                            hrs,
                                                                                                        );
                                                                                                    }
                                                                                                }}
                                                                                                className="h-7 w-20 text-xs"
                                                                                                placeholder="08:00"
                                                                                            />
                                                                                        </TableCell>
                                                                                        <TableCell>
                                                                                            <Input
                                                                                                type="text"
                                                                                                inputMode="numeric"
                                                                                                maxLength={
                                                                                                    5
                                                                                                }
                                                                                                value={
                                                                                                    line.to_time
                                                                                                        ? line.to_time.slice(
                                                                                                              0,
                                                                                                              5,
                                                                                                          )
                                                                                                        : ''
                                                                                                }
                                                                                                disabled={
                                                                                                    isClosed
                                                                                                }
                                                                                                onChange={(
                                                                                                    e,
                                                                                                ) => {
                                                                                                    let val =
                                                                                                        e.target.value.replace(
                                                                                                            /[^0-9:]/g,
                                                                                                            '',
                                                                                                        );

                                                                                                    if (
                                                                                                        val.length >
                                                                                                        5
                                                                                                    ) {
                                                                                                        val =
                                                                                                            val.slice(
                                                                                                                0,
                                                                                                                5,
                                                                                                            );
                                                                                                    }

                                                                                                    handleUpdateLine(
                                                                                                        day.index,
                                                                                                        idx,
                                                                                                        'to_time',
                                                                                                        val,
                                                                                                    );
                                                                                                }}
                                                                                                onBlur={(
                                                                                                    e,
                                                                                                ) => {
                                                                                                    const formatted =
                                                                                                        formatTimeString(
                                                                                                            e
                                                                                                                .target
                                                                                                                .value,
                                                                                                        );
                                                                                                    handleUpdateLine(
                                                                                                        day.index,
                                                                                                        idx,
                                                                                                        'to_time',
                                                                                                        formatted,
                                                                                                    );

                                                                                                    if (
                                                                                                        line.from_time &&
                                                                                                        formatted
                                                                                                    ) {
                                                                                                        const hrs =
                                                                                                            computeHours(
                                                                                                                line.from_time,
                                                                                                                formatted,
                                                                                                            );
                                                                                                        handleUpdateLine(
                                                                                                            day.index,
                                                                                                            idx,
                                                                                                            'hours',
                                                                                                            hrs,
                                                                                                        );
                                                                                                    }
                                                                                                }}
                                                                                                className="h-7 w-20 text-xs"
                                                                                                placeholder="17:00"
                                                                                            />
                                                                                        </TableCell>
                                                                                        <TableCell>
                                                                                            <Input
                                                                                                type="text"
                                                                                                inputMode="decimal"
                                                                                                value={
                                                                                                    line.efficiency ??
                                                                                                    ''
                                                                                                }
                                                                                                disabled={
                                                                                                    isClosed
                                                                                                }
                                                                                                onChange={(
                                                                                                    e,
                                                                                                ) => {
                                                                                                    const val =
                                                                                                        e.target.value.replace(
                                                                                                            /[^0-9.]/g,
                                                                                                            '',
                                                                                                        );
                                                                                                    handleUpdateLine(
                                                                                                        day.index,
                                                                                                        idx,
                                                                                                        'efficiency',
                                                                                                        val,
                                                                                                    );
                                                                                                }}
                                                                                                onBlur={(
                                                                                                    e,
                                                                                                ) => {
                                                                                                    const val =
                                                                                                        e.target.value.trim();

                                                                                                    if (
                                                                                                        !val ||
                                                                                                        isNaN(
                                                                                                            parseFloat(
                                                                                                                val,
                                                                                                            ),
                                                                                                        )
                                                                                                    ) {
                                                                                                        handleUpdateLine(
                                                                                                            day.index,
                                                                                                            idx,
                                                                                                            'efficiency',
                                                                                                            100,
                                                                                                        );
                                                                                                    } else {
                                                                                                        const num =
                                                                                                            Math.min(
                                                                                                                100,
                                                                                                                Math.max(
                                                                                                                    0,
                                                                                                                    parseFloat(
                                                                                                                        val,
                                                                                                                    ),
                                                                                                                ),
                                                                                                            );
                                                                                                        handleUpdateLine(
                                                                                                            day.index,
                                                                                                            idx,
                                                                                                            'efficiency',
                                                                                                            num,
                                                                                                        );
                                                                                                    }
                                                                                                }}
                                                                                                className="h-7 w-20 text-xs"
                                                                                                placeholder="100"
                                                                                            />
                                                                                        </TableCell>
                                                                                        <TableCell>
                                                                                            <Input
                                                                                                type="text"
                                                                                                value={
                                                                                                    line.property ??
                                                                                                    ''
                                                                                                }
                                                                                                disabled={
                                                                                                    isClosed
                                                                                                }
                                                                                                onChange={(
                                                                                                    e,
                                                                                                ) =>
                                                                                                    handleUpdateLine(
                                                                                                        day.index,
                                                                                                        idx,
                                                                                                        'property',
                                                                                                        e
                                                                                                            .target
                                                                                                            .value,
                                                                                                    )
                                                                                                }
                                                                                                className="h-7 w-full min-w-28 text-xs"
                                                                                                placeholder="Catatan / jenis pekerjaan"
                                                                                            />
                                                                                        </TableCell>
                                                                                    </>
                                                                                ) : (
                                                                                    <>
                                                                                        <TableCell className="text-xs">
                                                                                            {line.from_time
                                                                                                ? line.from_time.slice(
                                                                                                      0,
                                                                                                      5,
                                                                                                  )
                                                                                                : '-'}
                                                                                        </TableCell>
                                                                                        <TableCell className="text-xs">
                                                                                            {line.to_time
                                                                                                ? line.to_time.slice(
                                                                                                      0,
                                                                                                      5,
                                                                                                  )
                                                                                                : '-'}
                                                                                        </TableCell>
                                                                                        <TableCell className="text-xs">
                                                                                            {`${Number(
                                                                                                line.efficiency ||
                                                                                                    0,
                                                                                            ).toFixed(
                                                                                                2,
                                                                                            )}%`}
                                                                                        </TableCell>
                                                                                        <TableCell className="text-xs">
                                                                                            {line.property ||
                                                                                                '-'}
                                                                                        </TableCell>
                                                                                    </>
                                                                                )}
                                                                                {isEditing && (
                                                                                    <TableCell className="text-center">
                                                                                        <button
                                                                                            type="button"
                                                                                            title="Hapus baris ini"
                                                                                            onClick={(
                                                                                                e,
                                                                                            ) => {
                                                                                                e.stopPropagation();
                                                                                                handleDeleteSpecificLine(
                                                                                                    day.index,
                                                                                                    idx,
                                                                                                );
                                                                                            }}
                                                                                            className="inline-flex size-6 items-center justify-center rounded text-muted-foreground hover:bg-destructive/10 hover:text-destructive focus:outline-hidden"
                                                                                        >
                                                                                            <Trash2 className="size-3.5" />
                                                                                        </button>
                                                                                    </TableCell>
                                                                                )}
                                                                            </TableRow>
                                                                        );
                                                                    },
                                                                )
                                                            )}
                                                        </TableBody>
                                                    </Table>
                                                </div>

                                                {/* Bottom Row: Tutup untuk pengambilan (left) & Jam kerja (right) */}
                                                <div className="mt-3 flex flex-wrap items-center justify-between gap-3 pt-2">
                                                    <div className="flex items-center gap-2">
                                                        <Switch
                                                            id={`closed-${day.index}`}
                                                            checked={isClosed}
                                                            disabled={
                                                                !isEditing ||
                                                                !canManage
                                                            }
                                                            onCheckedChange={(
                                                                checked,
                                                            ) =>
                                                                handleToggleClosed(
                                                                    day.index,
                                                                    checked,
                                                                )
                                                            }
                                                        />
                                                        <label
                                                            htmlFor={`closed-${day.index}`}
                                                            className="cursor-pointer text-xs font-medium text-muted-foreground select-none"
                                                        >
                                                            Tutup untuk
                                                            pengambilan
                                                        </label>
                                                    </div>

                                                    <div className="flex items-center gap-2">
                                                        <span className="text-xs text-muted-foreground">
                                                            Total jam kerja:
                                                        </span>
                                                        <span className="inline-flex items-center rounded-md border border-border bg-muted/60 px-2.5 py-1 text-xs font-semibold text-foreground">
                                                            {totalHours.toFixed(
                                                                2,
                                                            )}{' '}
                                                            jam
                                                        </span>
                                                    </div>
                                                </div>
                                            </CollapsibleSection>
                                        );
                                    })}
                                </CollapsibleSectionGroup>
                            </div>
                        ) : (
                            <div className="flex h-64 items-center justify-center text-xs text-muted-foreground">
                                Pilih template atau buat template baru.
                            </div>
                        )}
                    </main>
                </div>
            </div>

            {/* Create Template Modal */}
            <Dialog open={isCreateOpen} onOpenChange={setIsCreateOpen}>
                <DialogContent size="compact">
                    <DialogHeader>
                        <div className="flex items-center gap-3">
                            <div className="flex size-10 shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                                <Plus className="size-5" />
                            </div>
                            <div>
                                <DialogTitle>Pola Jam Kerja Baru</DialogTitle>
                                <DialogDescription>
                                    Buat template pola jam kerja baru untuk
                                    kalender kerja.
                                </DialogDescription>
                            </div>
                        </div>
                    </DialogHeader>

                    <DialogBody className="space-y-4 py-4">
                        <Input
                            label="Pola jam kerja"
                            placeholder="mis. 24HR-DAY, PROD-DAY, STD-DAY"
                            value={newCode}
                            onChange={(e) => setNewCode(e.target.value)}
                            className="text-xs uppercase"
                            required
                        />

                        <Input
                            label="Nama"
                            placeholder="mis. Hari Kerja Produksi"
                            value={newName}
                            onChange={(e) => setNewName(e.target.value)}
                            className="text-xs"
                            required
                        />
                    </DialogBody>

                    <DialogFooter>
                        <DialogAction
                            onClick={handleCreateTemplate}
                            disabled={
                                isCreating || !newCode.trim() || !newName.trim()
                            }
                        >
                            {isCreating ? 'Menyimpan...' : 'Tambah template'}
                        </DialogAction>
                        <DialogCancel onClick={() => setIsCreateOpen(false)} />
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Copy Template Modal */}
            <Dialog
                open={isCopyTemplateOpen}
                onOpenChange={setIsCopyTemplateOpen}
            >
                <DialogContent size="compact">
                    <DialogHeader>
                        <div className="flex items-center gap-3">
                            <div className="flex size-10 shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                                <Copy className="size-5" />
                            </div>
                            <div>
                                <DialogTitle>Salin Pola Jam Kerja</DialogTitle>
                                <DialogDescription>
                                    Duplikasi pola jam kerja{' '}
                                    <span className="font-semibold text-primary">
                                        {activeTemplate?.code}
                                    </span>{' '}
                                    beserta seluruh baris jam kerja harian.
                                </DialogDescription>
                            </div>
                        </div>
                    </DialogHeader>

                    <DialogBody className="space-y-4 py-4">
                        <Input
                            label="Kode Template Baru"
                            value={copyNewCode}
                            onChange={(e) => setCopyNewCode(e.target.value)}
                            className="text-xs uppercase"
                            required
                        />

                        <Input
                            label="Nama Template Baru"
                            value={copyNewName}
                            onChange={(e) => setCopyNewName(e.target.value)}
                            className="text-xs"
                            required
                        />
                    </DialogBody>

                    <DialogFooter>
                        <DialogAction
                            onClick={handleExecuteCopyTemplate}
                            disabled={
                                isCopying ||
                                !copyNewCode.trim() ||
                                !copyNewName.trim()
                            }
                        >
                            {isCopying ? 'Menyalin...' : 'Salin template'}
                        </DialogAction>
                        <DialogCancel
                            onClick={() => setIsCopyTemplateOpen(false)}
                        />
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Copy Day Modal */}
            <Dialog open={isCopyDayOpen} onOpenChange={setIsCopyDayOpen}>
                <DialogContent size="compact">
                    <DialogHeader>
                        <div className="flex items-center gap-3">
                            <div className="flex size-10 shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                                <Copy className="size-5" />
                            </div>
                            <div>
                                <DialogTitle>
                                    Salin Jam Kerja Hari{' '}
                                    {
                                        DAYS.find(
                                            (d) => d.index === copyFromDay,
                                        )?.name
                                    }
                                </DialogTitle>
                                <DialogDescription>
                                    Salin jadwal jam kerja dari hari{' '}
                                    <span className="font-semibold text-primary">
                                        {
                                            DAYS.find(
                                                (d) => d.index === copyFromDay,
                                            )?.name
                                        }
                                    </span>{' '}
                                    ke hari lainnya.
                                </DialogDescription>
                            </div>
                        </div>
                    </DialogHeader>

                    <DialogToolbar className="flex items-center gap-2 px-6 py-2.5">
                        <span className="mr-1 text-xs font-medium text-muted-foreground">
                            Pilih cepat:
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            size="xs"
                            className="h-7 text-xs font-normal"
                            onClick={() => {
                                setCopyToDays(
                                    [0, 1, 2, 3, 4].filter(
                                        (idx) => idx !== copyFromDay,
                                    ),
                                );
                            }}
                        >
                            Senin – Jumat
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="xs"
                            className="h-7 text-xs font-normal"
                            onClick={() => {
                                setCopyToDays(
                                    DAYS.map((d) => d.index).filter(
                                        (idx) => idx !== copyFromDay,
                                    ),
                                );
                            }}
                        >
                            Semua Hari
                        </Button>
                        {copyToDays.length > 0 && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="xs"
                                className="h-7 text-xs text-muted-foreground hover:text-destructive"
                                onClick={() => setCopyToDays([])}
                            >
                                Reset
                            </Button>
                        )}
                    </DialogToolbar>

                    <DialogBody className="py-4">
                        <div className="grid grid-cols-2 gap-2.5">
                            {DAYS.filter((d) => d.index !== copyFromDay).map(
                                (d) => {
                                    const isChecked = copyToDays.includes(
                                        d.index,
                                    );

                                    return (
                                        <label
                                            key={d.index}
                                            className={cn(
                                                'flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-xs transition-colors select-none',
                                                isChecked
                                                    ? 'border-primary/50 bg-primary/5 font-medium text-foreground shadow-xs'
                                                    : 'border-border/70 bg-card text-foreground hover:border-border hover:bg-accent/40',
                                            )}
                                        >
                                            <Checkbox
                                                checked={isChecked}
                                                onCheckedChange={(checked) => {
                                                    if (checked === true) {
                                                        setCopyToDays(
                                                            (prev) => [
                                                                ...prev,
                                                                d.index,
                                                            ],
                                                        );
                                                    } else {
                                                        setCopyToDays((prev) =>
                                                            prev.filter(
                                                                (idx) =>
                                                                    idx !==
                                                                    d.index,
                                                            ),
                                                        );
                                                    }
                                                }}
                                            />
                                            <span className="text-xs">
                                                {d.name}
                                            </span>
                                        </label>
                                    );
                                },
                            )}
                        </div>
                    </DialogBody>

                    <DialogFooter>
                        <DialogAction
                            disabled={copyToDays.length === 0}
                            onClick={handleExecuteCopyDay}
                        >
                            {copyToDays.length > 0
                                ? `Salin ke ${copyToDays.length} Hari`
                                : 'Salin Jam Kerja'}
                        </DialogAction>
                        <DialogCancel onClick={() => setIsCopyDayOpen(false)} />
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

WorkingTimeTemplates.layout = {
    breadcrumbs: [
        { title: 'Kalender', href: '/settings/working-time-templates' },
        { title: 'Pola jam kerja', href: '/settings/working-time-templates' },
    ] satisfies BreadcrumbItem[],
};
