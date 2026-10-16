import { ActionButton } from '@apperp/ui/action-button';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@apperp/ui/alert-dialog';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import { DataTable, DataTableText } from '@apperp/ui/data-table';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { FieldHint, FieldLabel } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Label } from '@apperp/ui/label';
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import { MasterDetailLayout } from '@apperp/ui/master-detail-layout';
import {
    PageDialog,
    PageDialogAction,
    PageDialogBody,
    PageDialogContent,
    PageDialogDescription,
    PageDialogFooter,
    PageDialogHeader,
    PageDialogTitle,
} from '@apperp/ui/page-dialog';
import { RadioGroup, RadioGroupItem } from '@apperp/ui/radio-group';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { RecordEditBoundary } from '@apperp/ui/record-edit-boundary';
import { Switch } from '@apperp/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { useIncrementalList } from '@apperp/ui/use-incremental-list';
import type { ListLoader } from '@apperp/ui/use-incremental-list';
import { Head } from '@inertiajs/react';
import { Copy, Info, Plus, Save, Search, Trash2, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { apiJson, errorText } from '@/lib/core-api';
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
    const clean = raw.trim();

    if (!clean) {
        return '';
    }

    let candidate = clean;

    if (/^\d{1,2}$/.test(clean)) {
        candidate = `${clean.padStart(2, '0')}:00`;
    } else if (/^\d{3,4}$/.test(clean)) {
        candidate = `${clean.slice(0, -2).padStart(2, '0')}:${clean.slice(-2)}`;
    } else if (/^\d{1,2}:\d{2}$/.test(clean)) {
        candidate = clean.padStart(5, '0');
    }

    return /^(?:(?:[01]\d|2[0-3]):[0-5]\d|24:00)$/.test(candidate)
        ? candidate
        : clean;
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

    if (
        !/^(?:(?:[01]\d|2[0-3]):[0-5]\d|24:00)$/.test(f) ||
        !/^(?:(?:[01]\d|2[0-3]):[0-5]\d|24:00)$/.test(t)
    ) {
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
    version: number;
    lines: WorkingTimeLine[];
};

type LegalEntity = {
    id: string;
    name: string;
    company_code: string | null;
};

type Props = {
    canManage: boolean;
    currentLegalEntity?: LegalEntity | null;
};

const TEMPLATE_URL = '/settings/working-time-templates';
type TemplateHeader = Pick<WorkingTimeTemplate, 'id' | 'code' | 'name'>;

export default function WorkingTimeTemplates(props: Props) {
    return (
        <WorkingTimeTemplatePage
            key={props.currentLegalEntity?.id ?? 'none'}
            {...props}
        />
    );
}

function WorkingTimeTemplatePage({ canManage, currentLegalEntity }: Props) {
    const [searchFilter, setSearchFilter] = useState('');
    const [showDetail, setShowDetail] = useState(false);
    const loader = useCallback<ListLoader<TemplateHeader>>(
        async ({ query, cursor, sort, signal }) => {
            const params = new URLSearchParams({ incremental: '1', q: query });

            if (cursor) {
                params.set('cursor', cursor);
            }

            if (sort) {
                params.set('sort', sort.columnId);
                params.set('direction', sort.direction);
            }

            const result = await apiJson<{
                data: TemplateHeader[];
                next_cursor: string | null;
            }>(`${TEMPLATE_URL}?${params}`, { signal });

            return { items: result.data, nextCursor: result.next_cursor };
        },
        [],
    );
    const list = useIncrementalList({
        loadItems: loader,
        query: searchFilter,
        active: !!currentLegalEntity,
        getKey: (item) => item.id,
    });
    const [activeTemplate, setActiveTemplate] =
        useState<WorkingTimeTemplate | null>(null);
    const [draftLines, setDraftLines] = useState<WorkingTimeLine[]>([]);
    const [expandedDays, setExpandedDays] = useState<string[]>(['0']);
    const [activeDay, setActiveDay] = useState(0);
    const [selectedRowIndex, setSelectedRowIndex] = useState<number | null>(
        null,
    );
    const [isSaving, setIsSaving] = useState(false);
    const [isEditing, setIsEditing] = useState(false);
    const [headerCode, setHeaderCode] = useState('');
    const [headerName, setHeaderName] = useState('');
    const [isLoadingDetail, setIsLoadingDetail] = useState(false);
    const [error, setError] = useState('');
    const [modalError, setModalError] = useState('');
    const detailRequest = useRef<AbortController | null>(null);
    useEffect(() => () => detailRequest.current?.abort(), []);

    const openRecord = (record: WorkingTimeTemplate, edit = false) => {
        setActiveTemplate(record);
        setShowDetail(true);
        setDraftLines(record.lines);
        setHeaderCode(record.code);
        setHeaderName(record.name);
        setIsEditing(edit);
        setSelectedRowIndex(null);
        setError('');
    };
    const selectTemplate = async (id: string) => {
        detailRequest.current?.abort();
        const controller = new AbortController();
        detailRequest.current = controller;
        setActiveTemplate(null);
        setIsLoadingDetail(true);
        setError('');

        try {
            const result = await apiJson<{ data: WorkingTimeTemplate[] }>(
                `${TEMPLATE_URL}?incremental=1&record_id=${encodeURIComponent(id)}`,
                { signal: controller.signal },
            );

            if (!controller.signal.aborted) {
                openRecord(result.data[0]);
            }
        } catch (caught) {
            if (!controller.signal.aborted) {
                setError(
                    errorText(
                        caught,
                        'Pola jam kerja belum dapat dibuka. Pilih kembali untuk mencoba lagi.',
                    ),
                );
            }
        } finally {
            if (!controller.signal.aborted) {
                setIsLoadingDetail(false);
            }
        }
    };
    const [pendingSelection, setPendingSelection] = useState<string | null>(
        null,
    );
    const handleSelectTemplate = (record: TemplateHeader) => {
        if (isSaving) {
            return;
        }

        if (record.id === activeTemplate?.id) {
            setShowDetail(true);

            return;
        }

        if (isEditing) {
            setPendingSelection(record.id);
        } else {
            void selectTemplate(record.id);
        }
    };
    const handleCancelEdit = () => {
        if (activeTemplate) {
            openRecord(activeTemplate);
        }
    };
    const [isCreateOpen, setIsCreateOpen] = useState(false);
    const [newCode, setNewCode] = useState('');
    const [newName, setNewName] = useState('');
    const [isCreating, setIsCreating] = useState(false);
    const [isCopyTemplateOpen, setIsCopyTemplateOpen] = useState(false);
    const [copyNewCode, setCopyNewCode] = useState('');
    const [copyNewName, setCopyNewName] = useState('');
    const [isCopying, setIsCopying] = useState(false);
    const [isArchiveOpen, setIsArchiveOpen] = useState(false);
    const [isArchiving, setIsArchiving] = useState(false);
    const [isCopyDayOpen, setIsCopyDayOpen] = useState(false);
    const [copyFromDay, setCopyFromDay] = useState(0);
    const [copyToDays, setCopyToDays] = useState<number[]>([]);

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
            closed_for_pickup: getDayClosed(dayIndex),
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

    const handleSaveAll = async () => {
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
                    efficiency:
                        l.efficiency === null ||
                        l.efficiency === undefined ||
                        String(l.efficiency).trim() === ''
                            ? 100
                            : Number(l.efficiency),
                    property: l.property ? l.property.trim() : null,
                    closed_for_pickup: Boolean(l.closed_for_pickup),
                    hours: hrs,
                };
            });

        setError('');

        try {
            const result = await apiJson<{ data: WorkingTimeTemplate }>(
                `${TEMPLATE_URL}/${activeTemplate.id}`,
                {
                    method: 'PUT',
                    body: JSON.stringify({
                        code: headerCode.toUpperCase().trim(),
                        name: headerName.trim(),
                        lines: sanitizedLines,
                        version: activeTemplate.version,
                    }),
                },
            );
            openRecord(result.data);
            list.reload();
        } catch (caught) {
            setError(
                errorText(
                    caught,
                    'Pola jam kerja belum berhasil disimpan. Isianmu tetap tersedia untuk dicoba lagi.',
                ),
            );
        } finally {
            setIsSaving(false);
        }
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
        setSelectedRowIndex(null);
    };

    const handleCreateTemplate = async () => {
        if (!newCode.trim() || !newName.trim() || isCreating) {
            return;
        }

        setIsCreating(true);
        setModalError('');

        try {
            const result = await apiJson<{ data: WorkingTimeTemplate }>(
                TEMPLATE_URL,
                {
                    method: 'POST',
                    body: JSON.stringify({
                        code: newCode.trim().toUpperCase(),
                        name: newName.trim(),
                        legal_entity_id: currentLegalEntity?.id,
                    }),
                },
            );
            openRecord(result.data, true);
            list.reload();
            setIsCreateOpen(false);
            setNewCode('');
            setNewName('');
        } catch (caught) {
            setModalError(
                errorText(
                    caught,
                    'Pola jam kerja belum berhasil dibuat. Coba lagi.',
                ),
            );
        } finally {
            setIsCreating(false);
        }
    };
    const handleDeleteTemplate = async () => {
        if (!activeTemplate || isArchiving) {
            return;
        }

        setIsArchiving(true);
        setModalError('');

        try {
            await apiJson(`${TEMPLATE_URL}/${activeTemplate.id}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: activeTemplate.version }),
            });
            setActiveTemplate(null);
            setIsArchiveOpen(false);
            list.reload();
        } catch (caught) {
            setModalError(
                errorText(
                    caught,
                    'Pola jam kerja belum berhasil diarsipkan. Coba lagi.',
                ),
            );
        } finally {
            setIsArchiving(false);
        }
    };
    const handleOpenCopyTemplate = () => {
        if (!activeTemplate) {
            return;
        }

        setCopyNewCode(`${activeTemplate.code}-SALIN`);
        setCopyNewName(`${activeTemplate.name} (Salinan)`);
        setModalError('');
        setIsCopyTemplateOpen(true);
    };
    const handleExecuteCopyTemplate = async () => {
        if (
            !activeTemplate ||
            !copyNewCode.trim() ||
            !copyNewName.trim() ||
            isCopying
        ) {
            return;
        }

        setIsCopying(true);
        setModalError('');

        try {
            const result = await apiJson<{ data: WorkingTimeTemplate }>(
                `${TEMPLATE_URL}/${activeTemplate.id}/copy`,
                {
                    method: 'POST',
                    body: JSON.stringify({
                        code: copyNewCode.trim().toUpperCase(),
                        name: copyNewName.trim(),
                    }),
                },
            );
            openRecord(result.data, true);
            list.reload();
            setIsCopyTemplateOpen(false);
        } catch (caught) {
            setModalError(
                errorText(
                    caught,
                    'Pola jam kerja belum berhasil disalin. Coba lagi.',
                ),
            );
        } finally {
            setIsCopying(false);
        }
    };

    return (
        <>
            <Head title="Pola jam kerja" />

            <ListPageLayout
                header={
                    <RecordActionBar title="Pola jam kerja">
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
                                        onClick={() => {
                                            setIsEditing(true);
                                            setShowDetail(true);
                                        }}
                                    >
                                        Ubah
                                    </ActionButton>
                                )}

                                {canManage && (
                                    <ActionButton
                                        action="create"
                                        size="sm"
                                        type="button"
                                        disabled={isLoadingDetail}
                                        onClick={() => {
                                            setModalError('');
                                            setIsCreateOpen(true);
                                        }}
                                    >
                                        Tambah pola
                                    </ActionButton>
                                )}

                                {canManage && (
                                    <ActionButton
                                        action="archive"
                                        size="sm"
                                        type="button"
                                        disabled={!activeTemplate}
                                        onClick={() => {
                                            setModalError('');
                                            setIsArchiveOpen(true);
                                        }}
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
                                        <span>Salin pola</span>
                                    </Button>
                                )}
                            </>
                        )}
                    </RecordActionBar>
                }
            >
                {/* Main 2-Pane Layout: Left Pane Master List, Right Pane Detail Section */}
                <MasterDetailLayout
                    detailOpen={showDetail}
                    onDetailOpenChange={setShowDetail}
                    list={
                        <aside className="flex h-full min-h-0 min-w-0 flex-col">
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
                                        aria-label="Cari pola jam kerja"
                                        className="bg-background pl-8"
                                    />
                                </div>
                            </div>

                            <DataTable
                                data={list.items}
                                getRowKey={(item) => item.id}
                                columns={[
                                    {
                                        id: 'code',
                                        header: 'Pola jam kerja',
                                        sortValue: (item) => item.code,
                                        cell: (item) => (
                                            <div>
                                                <DataTableText>
                                                    {item.code}
                                                </DataTableText>
                                                <span className="text-muted-foreground">
                                                    <DataTableText>
                                                        {item.name}
                                                    </DataTableText>
                                                </span>
                                            </div>
                                        ),
                                    },
                                ]}
                                activeRowKey={activeTemplate?.id}
                                rowActivation="single-click"
                                onRowClick={handleSelectTemplate}
                                sort={list.sort}
                                onSortChange={list.onSortChange}
                                serverSort
                                incremental={list}
                                emptyMessage={
                                    currentLegalEntity
                                        ? 'Belum ada pola jam kerja yang cocok.'
                                        : 'Pilih perusahaan terlebih dahulu.'
                                }
                            />
                        </aside>
                    }
                >
                    <RecordEditBoundary
                        editing={isEditing}
                        canEdit={canManage && !!activeTemplate}
                        disabled={isSaving}
                        onEdit={() => setIsEditing(true)}
                        className="flex h-full min-h-0 min-w-0 flex-col"
                    >
                        <main
                            className="min-h-0 min-w-0 flex-1 overflow-auto bg-background p-4 lg:p-6"
                            scroll-region=""
                        >
                            {error && (
                                <p
                                    role="alert"
                                    className="mb-4 text-destructive"
                                >
                                    {error}
                                </p>
                            )}
                            {activeTemplate ? (
                                <fieldset
                                    className="w-full min-w-0 space-y-4"
                                    disabled={isSaving}
                                >
                                    {/* Form Fields: Standard Input Component */}
                                    <div className="grid w-full gap-4 sm:grid-cols-2">
                                        <Input
                                            label="Kode pola jam kerja"
                                            required
                                            maxLength={50}
                                            value={
                                                isEditing
                                                    ? headerCode
                                                    : activeTemplate.code
                                            }
                                            readOnly={!isEditing || !canManage}
                                            onChange={(e) =>
                                                setHeaderCode(
                                                    e.target.value.toUpperCase(),
                                                )
                                            }
                                            className="font-medium uppercase"
                                        />

                                        <Input
                                            label="Nama"
                                            required
                                            maxLength={150}
                                            value={
                                                isEditing
                                                    ? headerName
                                                    : activeTemplate.name
                                            }
                                            readOnly={!isEditing || !canManage}
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
                                            const dayLines = getDayLines(
                                                day.index,
                                            );
                                            const isClosed = getDayClosed(
                                                day.index,
                                            );
                                            const totalHours =
                                                calculateDayHours(day.index);

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
                                                                    0
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

                                                                className="gap-1.5 font-medium text-muted-foreground hover:bg-muted hover:text-foreground"
                                                            >
                                                                <Copy className="size-3.5" />
                                                                Salin hari
                                                            </Button>
                                                        )}
                                                    </div>

                                                    {/* Lines Table using Standard @apperp/ui/table */}
                                                    <div className="overflow-x-auto rounded-md border border-border bg-card">
                                                        <RadioGroup
                                                            value={
                                                                activeDay ===
                                                                    day.index &&
                                                                selectedRowIndex !==
                                                                    null
                                                                    ? String(
                                                                          selectedRowIndex,
                                                                      )
                                                                    : ''
                                                            }
                                                            onValueChange={(
                                                                value,
                                                            ) => {
                                                                setActiveDay(
                                                                    day.index,
                                                                );
                                                                setSelectedRowIndex(
                                                                    Number(
                                                                        value,
                                                                    ),
                                                                );
                                                            }}
                                                            aria-label={`Pilih baris ${day.name}`}
                                                        >
                                                            <Table>
                                                                <TableHeader>
                                                                    <TableRow>
                                                                        <TableHead className="w-10 text-center">
                                                                            <span className="sr-only">
                                                                                Pilih
                                                                                baris
                                                                            </span>
                                                                        </TableHead>
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
                                                                            <TableHead className="w-10 text-center">
                                                                                <span className="sr-only">
                                                                                    Aksi
                                                                                </span>
                                                                            </TableHead>
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
                                                                                className="py-6 text-center text-muted-foreground"
                                                                            >
                                                                                Belum
                                                                                ada
                                                                                jam
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
                                                                                            <RadioGroupItem
                                                                                                value={String(
                                                                                                    idx,
                                                                                                )}
                                                                                                aria-label={`Baris ${idx + 1}, ${day.name}`}
                                                                                            />
                                                                                        </TableCell>
                                                                                        {isEditing ? (
                                                                                            <>
                                                                                                <TableCell>
                                                                                                    <Input
                                                                                                        aria-label={`Dari jam ${day.name}, baris ${idx + 1}`}
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
                                                                                                        className="w-20"
                                                                                                        placeholder="08:00"
                                                                                                    />
                                                                                                </TableCell>
                                                                                                <TableCell>
                                                                                                    <Input
                                                                                                        aria-label={`Sampai jam ${day.name}, baris ${idx + 1}`}
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
                                                                                                        className="w-20"
                                                                                                        placeholder="17:00"
                                                                                                    />
                                                                                                </TableCell>
                                                                                                <TableCell>
                                                                                                    <Input
                                                                                                        aria-label={`Efisiensi ${day.name}, baris ${idx + 1}`}
                                                                                                        type="text"
                                                                                                        inputMode="decimal"
                                                                                                        value={
                                                                                                            line.efficiency ??
                                                                                                            ''
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
                                                                                                        className="w-20"
                                                                                                        placeholder="100"
                                                                                                    />
                                                                                                </TableCell>
                                                                                                <TableCell>
                                                                                                    <Input
                                                                                                        aria-label={`Properti ${day.name}, baris ${idx + 1}`}
                                                                                                        type="text"
                                                                                                        value={
                                                                                                            line.property ??
                                                                                                            ''
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
                                                                                                        className="w-full min-w-28"
                                                                                                        placeholder="Catatan / jenis pekerjaan"
                                                                                                    />
                                                                                                </TableCell>
                                                                                            </>
                                                                                        ) : (
                                                                                            <>
                                                                                                <TableCell>
                                                                                                    {line.from_time
                                                                                                        ? line.from_time.slice(
                                                                                                              0,
                                                                                                              5,
                                                                                                          )
                                                                                                        : '-'}
                                                                                                </TableCell>
                                                                                                <TableCell>
                                                                                                    {line.to_time
                                                                                                        ? line.to_time.slice(
                                                                                                              0,
                                                                                                              5,
                                                                                                          )
                                                                                                        : '-'}
                                                                                                </TableCell>
                                                                                                <TableCell>
                                                                                                    {`${Number(
                                                                                                        line.efficiency ||
                                                                                                            0,
                                                                                                    ).toFixed(
                                                                                                        2,
                                                                                                    )}%`}
                                                                                                </TableCell>
                                                                                                <TableCell>
                                                                                                    {line.property ||
                                                                                                        '-'}
                                                                                                </TableCell>
                                                                                            </>
                                                                                        )}
                                                                                        {isEditing && (
                                                                                            <TableCell className="text-center">
                                                                                                <Button
                                                                                                    variant="ghost"
                                                                                                    size="icon"
                                                                                                    aria-label="Hapus baris ini"
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
                                                                                                    className="text-destructive"
                                                                                                >
                                                                                                    <Trash2 className="size-3.5" />
                                                                                                </Button>
                                                                                            </TableCell>
                                                                                        )}
                                                                                    </TableRow>
                                                                                );
                                                                            },
                                                                        )
                                                                    )}
                                                                </TableBody>
                                                            </Table>
                                                        </RadioGroup>
                                                    </div>

                                                    {/* Bottom Row: Tutup untuk pengambilan (left) & Jam kerja (right) */}
                                                    <div className="mt-3 flex flex-wrap items-center justify-between gap-3 pt-2">
                                                        <div className="flex items-center gap-2">
                                                            <Switch
                                                                id={`closed-${day.index}`}
                                                                checked={
                                                                    isClosed
                                                                }
                                                                disabled={
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
                                                            <FieldLabel
                                                                htmlFor={`closed-${day.index}`}
                                                                className="cursor-pointer font-medium text-muted-foreground select-none"
                                                            >
                                                                Tutup untuk
                                                                pengambilan
                                                            </FieldLabel>
                                                            <FieldHint hint="Membatasi pengambilan barang pada hari ini. Jam kerja tetap berlaku.">
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    aria-label="Bantuan tutup untuk pengambilan"
                                                                >
                                                                    <Info className="size-4" />
                                                                </Button>
                                                            </FieldHint>
                                                        </div>

                                                        <div className="flex items-center gap-2">
                                                            <span className="text-muted-foreground">
                                                                Total jam kerja:
                                                            </span>
                                                            <Badge variant="secondary">
                                                                {totalHours.toFixed(
                                                                    2,
                                                                )}{' '}
                                                                jam
                                                            </Badge>
                                                        </div>
                                                    </div>
                                                </CollapsibleSection>
                                            );
                                        })}
                                    </CollapsibleSectionGroup>
                                </fieldset>
                            ) : (
                                <Empty>
                                    <EmptyHeader>
                                        <EmptyTitle>
                                            {isLoadingDetail
                                                ? 'Memuat pola jam kerja...'
                                                : 'Pilih pola jam kerja'}
                                        </EmptyTitle>
                                        <EmptyDescription>
                                            {isLoadingDetail
                                                ? 'Rincian hari sedang dimuat.'
                                                : 'Pilih pola dari daftar untuk melihat jam kerja setiap harinya.'}
                                        </EmptyDescription>
                                    </EmptyHeader>
                                </Empty>
                            )}
                        </main>
                    </RecordEditBoundary>
                </MasterDetailLayout>
            </ListPageLayout>

            <PageDialog
                open={isCreateOpen}
                onOpenChange={(open) => {
                    if (!isCreating) {
                        setIsCreateOpen(open);
                    }
                }}
            >
                <PageDialogContent>
                    <PageDialogHeader>
                        <PageDialogTitle>Pola jam kerja baru</PageDialogTitle>
                        <PageDialogDescription>
                            Buat pola, lalu isi jam kerja setiap harinya.
                        </PageDialogDescription>
                    </PageDialogHeader>
                    <PageDialogBody className="space-y-4">
                        {modalError && (
                            <p role="alert" className="text-destructive">
                                {modalError}
                            </p>
                        )}
                        <Input
                            label="Kode pola jam kerja"
                            value={newCode}
                            onChange={(event) =>
                                setNewCode(event.target.value.toUpperCase())
                            }
                            maxLength={50}
                            required
                            autoFocus
                            readOnly={isCreating}
                        />
                        <Input
                            label="Nama"
                            value={newName}
                            onChange={(event) => setNewName(event.target.value)}
                            maxLength={150}
                            required
                            readOnly={isCreating}
                        />
                    </PageDialogBody>
                    <PageDialogFooter>
                        <PageDialogAction
                            onClick={handleCreateTemplate}
                            disabled={
                                isCreating || !newCode.trim() || !newName.trim()
                            }
                        >
                            {isCreating ? 'Menyimpan...' : 'Buat pola'}
                        </PageDialogAction>
                        <Button
                            variant="outline"
                            onClick={() => setIsCreateOpen(false)}
                            disabled={isCreating}
                        >
                            Batal
                        </Button>
                    </PageDialogFooter>
                </PageDialogContent>
            </PageDialog>

            <PageDialog
                open={isCopyTemplateOpen}
                onOpenChange={(open) => {
                    if (!isCopying) {
                        setIsCopyTemplateOpen(open);
                    }
                }}
            >
                <PageDialogContent>
                    <PageDialogHeader>
                        <PageDialogTitle>Salin pola jam kerja</PageDialogTitle>
                        <PageDialogDescription>
                            Salin {activeTemplate?.code} beserta seluruh jam
                            kerja hariannya ke pola baru.
                        </PageDialogDescription>
                    </PageDialogHeader>
                    <PageDialogBody className="space-y-4">
                        {modalError && (
                            <p role="alert" className="text-destructive">
                                {modalError}
                            </p>
                        )}
                        <Input
                            label="Kode pola baru"
                            value={copyNewCode}
                            onChange={(event) =>
                                setCopyNewCode(event.target.value.toUpperCase())
                            }
                            maxLength={50}
                            required
                            autoFocus
                            readOnly={isCopying}
                        />
                        <Input
                            label="Nama pola baru"
                            value={copyNewName}
                            onChange={(event) =>
                                setCopyNewName(event.target.value)
                            }
                            maxLength={150}
                            required
                            readOnly={isCopying}
                        />
                    </PageDialogBody>
                    <PageDialogFooter>
                        <PageDialogAction
                            onClick={handleExecuteCopyTemplate}
                            disabled={
                                isCopying ||
                                !copyNewCode.trim() ||
                                !copyNewName.trim()
                            }
                        >
                            {isCopying ? 'Menyalin...' : 'Salin pola'}
                        </PageDialogAction>
                        <Button
                            variant="outline"
                            onClick={() => setIsCopyTemplateOpen(false)}
                            disabled={isCopying}
                        >
                            Batal
                        </Button>
                    </PageDialogFooter>
                </PageDialogContent>
            </PageDialog>

            <PageDialog open={isCopyDayOpen} onOpenChange={setIsCopyDayOpen}>
                <PageDialogContent>
                    <PageDialogHeader>
                        <PageDialogTitle>
                            Salin jam kerja{' '}
                            {
                                DAYS.find((day) => day.index === copyFromDay)
                                    ?.name
                            }
                        </PageDialogTitle>
                        <PageDialogDescription>
                            Jam kerja pada hari tujuan akan diganti. Perubahan
                            baru tersimpan setelah menekan Simpan pada pola jam
                            kerja.
                        </PageDialogDescription>
                    </PageDialogHeader>
                    <PageDialogBody className="space-y-4">
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                onClick={() =>
                                    setCopyToDays(
                                        [0, 1, 2, 3, 4].filter(
                                            (day) => day !== copyFromDay,
                                        ),
                                    )
                                }
                            >
                                Senin-Jumat
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    setCopyToDays(
                                        DAYS.map((day) => day.index).filter(
                                            (day) => day !== copyFromDay,
                                        ),
                                    )
                                }
                            >
                                Semua hari
                            </Button>
                            <Button
                                variant="ghost"
                                onClick={() => setCopyToDays([])}
                                disabled={!copyToDays.length}
                            >
                                Kosongkan pilihan
                            </Button>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {DAYS.filter(
                                (day) => day.index !== copyFromDay,
                            ).map((day) => (
                                <Label
                                    key={day.index}
                                    className="flex items-center gap-2"
                                >
                                    <Checkbox
                                        checked={copyToDays.includes(day.index)}
                                        onCheckedChange={(checked) =>
                                            setCopyToDays((previous) =>
                                                checked === true
                                                    ? [...previous, day.index]
                                                    : previous.filter(
                                                          (index) =>
                                                              index !==
                                                              day.index,
                                                      ),
                                            )
                                        }
                                    />
                                    {day.name}
                                </Label>
                            ))}
                        </div>
                    </PageDialogBody>
                    <PageDialogFooter>
                        <PageDialogAction
                            disabled={!copyToDays.length}
                            onClick={handleExecuteCopyDay}
                        >
                            Salin ke {copyToDays.length} hari
                        </PageDialogAction>
                        <Button
                            variant="outline"
                            onClick={() => setIsCopyDayOpen(false)}
                        >
                            Batal
                        </Button>
                    </PageDialogFooter>
                </PageDialogContent>
            </PageDialog>

            <AlertDialog
                open={isArchiveOpen}
                onOpenChange={(open) => {
                    if (!isArchiving) {
                        setIsArchiveOpen(open);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Arsipkan pola jam kerja?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {activeTemplate?.code} - {activeTemplate?.name} akan
                            disembunyikan dari daftar pola yang tersedia.
                            Kalender yang sudah menggunakannya tetap tersimpan.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    {modalError && (
                        <p role="alert" className="text-destructive">
                            {modalError}
                        </p>
                    )}
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isArchiving}>
                            Batal
                        </AlertDialogCancel>
                        <AlertDialogAction
                            disabled={isArchiving}
                            onClick={(event) => {
                                event.preventDefault();
                                void handleDeleteTemplate();
                            }}
                        >
                            {isArchiving ? 'Mengarsipkan...' : 'Arsipkan'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <AlertDialog
                open={pendingSelection !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingSelection(null);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Tinggalkan perubahan?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Perubahan yang belum disimpan akan hilang jika
                            membuka pola lain.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>
                            Kembali menyunting
                        </AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => {
                                if (pendingSelection) {
                                    setIsEditing(false);
                                    void selectTemplate(pendingSelection);
                                    setPendingSelection(null);
                                }
                            }}
                        >
                            Tinggalkan perubahan
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

WorkingTimeTemplates.layout = {
    breadcrumbs: [
        { title: 'Kalender', href: '/settings/working-time-templates' },
        { title: 'Pola jam kerja', href: '/settings/working-time-templates' },
    ] satisfies BreadcrumbItem[],
};
