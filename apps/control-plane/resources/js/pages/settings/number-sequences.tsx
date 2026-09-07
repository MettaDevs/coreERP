import { Button } from '@apperp/ui/button';
import { Card, CardContent } from '@apperp/ui/card';
import { Checkbox } from '@apperp/ui/checkbox';
import { Input } from '@apperp/ui/input';
import { NativeSelect } from '@apperp/ui/native-select';
import { Tooltip, TooltipContent, TooltipTrigger } from '@apperp/ui/tooltip';
import { Head, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ChevronRight,
    CircleAlert,
    GripVertical,
    Hash,
    Plus,
    Save,
    Search,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import type { BreadcrumbItem } from '@/types/navigation';

type Segment = {
    type:
        | 'constant'
        | 'number'
        | 'year'
        | 'scope'
        | 'fiscal_year'
        | 'fiscal_period';
    value?: string;
    length?: number;
};
type Sequence = {
    id: string;
    reference_code: string;
    reference_name: string;
    app_name: string;
    allowed_scopes: string[];
    profile_code: string;
    scope_type: string;
    status: string;
    is_continuous: boolean;
    allow_manual: boolean;
    reset_period: string;
    preallocation_enabled: boolean;
    preallocation_quantity: number;
    minimum_number: number;
    maximum_number: number | null;
    segments: Segment[];
};
type Props = {
    canManage: boolean;
    tenant: { id: string; name: string };
    sequences: Sequence[];
    profiles: { code: string; name: string }[];
};

function SegmentEditor({
    value,
    onChange,
    disabled = false,
}: {
    value: Segment[];
    onChange: (segments: Segment[]) => void;
    disabled?: boolean;
}) {
    const [draggedIndex, setDraggedIndex] = useState<number | null>(null);

    const update = (index: number, patch: Partial<Segment>) =>
        onChange(
            value.map((segment, current) =>
                current === index ? { ...segment, ...patch } : segment,
            ),
        );

    const handleDragStart = (index: number) => {
        setDraggedIndex(index);
    };

    const handleDragOver = (event: React.DragEvent, targetIndex: number) => {
        event.preventDefault();

        if (draggedIndex === null || draggedIndex === targetIndex) {
            return;
        }

        const next = [...value];
        const [movedItem] = next.splice(draggedIndex, 1);
        next.splice(targetIndex, 0, movedItem);
        setDraggedIndex(targetIndex);
        onChange(next);
    };

    const handleDragEnd = () => {
        setDraggedIndex(null);
    };

    return (
        <div className="space-y-2.5">
            {value.map((segment, index) => (
                <div
                    key={`${segment.type}-${index}`}
                    draggable={!disabled}
                    onDragStart={() => handleDragStart(index)}
                    onDragOver={(e) => handleDragOver(e, index)}
                    onDragEnd={handleDragEnd}
                    className={`flex flex-wrap items-center gap-3 rounded-lg border border-border bg-card p-3 shadow-xs transition-all ${
                        draggedIndex === index
                            ? 'scale-[0.99] border-primary opacity-40 ring-2 ring-primary/20'
                            : 'hover:border-primary/40'
                    } ${!disabled ? 'cursor-grab active:cursor-grabbing' : ''}`}
                >
                    <div className="flex items-center gap-2">
                        <GripVertical className="size-4 shrink-0 text-muted-foreground/50 hover:text-foreground" />
                        <span className="min-w-[3.5rem] text-xs font-semibold text-muted-foreground">
                            Bagian {index + 1}
                        </span>
                    </div>

                    <div className="flex min-w-0 flex-1 items-center gap-3">
                        {/* Wrapper berukuran tetap untuk Dropdown Tipe */}
                        <div className="w-48 shrink-0">
                            <NativeSelect
                                value={segment.type}
                                disabled={disabled}
                                onChange={(event) =>
                                    update(index, {
                                        type: event.target
                                            .value as Segment['type'],
                                        value: '',
                                        length:
                                            event.target.value === 'number'
                                                ? 5
                                                : event.target.value ===
                                                    'fiscal_period'
                                                  ? 2
                                                  : undefined,
                                    })
                                }
                            >
                                <option value="constant">Teks tetap</option>
                                <option value="number">Nomor urut</option>
                                <option value="year">Tahun kalender</option>
                                <option value="scope">Kode organisasi</option>
                                <option value="fiscal_year">
                                    Tahun fiskal
                                </option>
                                <option value="fiscal_period">
                                    Periode fiskal
                                </option>
                            </NativeSelect>
                        </div>

                        {/* Input Textfield Mengisi Sisa Ruang (flex-1) */}
                        {segment.type === 'constant' && (
                            <div className="min-w-0 flex-1">
                                <Input
                                    aria-label="Teks tetap"
                                    placeholder="Masukkan nilai teks (contoh: MDLA)"
                                    className="w-full font-mono text-xs"
                                    disabled={disabled}
                                    value={segment.value ?? ''}
                                    onChange={(event) =>
                                        update(index, {
                                            value: event.target.value,
                                        })
                                    }
                                />
                            </div>
                        )}
                        {segment.type === 'number' && (
                            <div className="flex min-w-0 flex-1 items-center gap-2">
                                <Input
                                    aria-label="Jumlah digit"
                                    className="min-w-0 flex-1 font-mono text-xs"
                                    type="number"
                                    min="1"
                                    max="18"
                                    disabled={disabled}
                                    value={segment.length ?? 5}
                                    onChange={(event) =>
                                        update(index, {
                                            length: Number(event.target.value),
                                        })
                                    }
                                />
                                <span className="shrink-0 font-mono text-xs whitespace-nowrap text-muted-foreground">
                                    digit (
                                    {'0'.repeat(
                                        Math.min(segment.length ?? 5, 8),
                                    )}
                                    )
                                </span>
                            </div>
                        )}
                        {segment.type === 'fiscal_period' && (
                            <div className="flex min-w-0 flex-1 items-center gap-2">
                                <Input
                                    aria-label="Jumlah digit periode"
                                    className="min-w-0 flex-1 font-mono text-xs"
                                    type="number"
                                    min="1"
                                    max="4"
                                    disabled={disabled}
                                    value={segment.length ?? 2}
                                    onChange={(event) =>
                                        update(index, {
                                            length: Number(event.target.value),
                                        })
                                    }
                                />
                                <span className="shrink-0 text-xs whitespace-nowrap text-muted-foreground">
                                    digit
                                </span>
                            </div>
                        )}
                        {(segment.type === 'year' ||
                            segment.type === 'scope' ||
                            segment.type === 'fiscal_year') && (
                            <div className="min-w-0 flex-1">
                                <Input
                                    disabled
                                    className="w-full bg-muted/40 font-mono text-xs text-muted-foreground"
                                    value={
                                        segment.type === 'year'
                                            ? String(new Date().getFullYear())
                                            : segment.type === 'scope'
                                              ? 'ORG'
                                              : `FY${new Date().getFullYear()}`
                                    }
                                    aria-label="Nilai otomatis"
                                />
                            </div>
                        )}
                    </div>

                    {!disabled && (
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            className="ml-auto h-8 w-8 text-destructive hover:bg-destructive/10"
                            onClick={() =>
                                onChange(
                                    value.filter(
                                        (_, current) => current !== index,
                                    ),
                                )
                            }
                            title="Hapus bagian"
                        >
                            <Trash2 className="h-4 w-4" />
                        </Button>
                    )}
                </div>
            ))}

            {!disabled && (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="w-full justify-center border-dashed border-primary/40 py-2.5 text-xs font-medium text-primary transition-colors hover:bg-primary/5 hover:text-primary"
                    onClick={() =>
                        onChange([...value, { type: 'constant', value: '' }])
                    }
                >
                    <Plus className="mr-1.5 size-4" /> Tambah bagian format
                </Button>
            )}
        </div>
    );
}

function SequenceForm({
    sequence,
    profiles,
    canManage,
}: {
    sequence: Sequence;
    profiles: Props['profiles'];
    canManage: boolean;
}) {
    const form = useForm({
        profile_code: sequence.profile_code,
        scope_type: sequence.scope_type,
        status: sequence.status,
        is_continuous: sequence.is_continuous,
        allow_manual: sequence.allow_manual,
        reset_period: sequence.reset_period,
        preallocation_enabled: sequence.preallocation_enabled,
        preallocation_quantity: sequence.preallocation_quantity,
        minimum_number: sequence.minimum_number,
        maximum_number: sequence.maximum_number?.toString() ?? '',
        segments: sequence.segments,
    });
    const error = Object.values(form.errors)[0];

    return (
        <form
            className="flex min-h-0 flex-1 flex-col"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) => ({
                    ...data,
                    maximum_number:
                        data.maximum_number === ''
                            ? null
                            : Number(data.maximum_number),
                }));
                form.patch(`/settings/number-sequences/${sequence.id}`);
            }}
        >
            {/* Header Sticky: Icon Badge & Judul dengan Icon Info Penjelas */}
            <div className="sticky top-0 z-10 flex shrink-0 flex-wrap items-center justify-between gap-4 border-b border-border bg-card px-6 py-4">
                <div className="flex items-center gap-3.5">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <Hash className="size-5" />
                    </div>
                    <div className="flex items-center gap-2">
                        <h2 className="text-lg font-bold tracking-tight text-foreground">
                            {sequence.reference_name}
                        </h2>
                        <Tooltip clickToPin>
                            <TooltipTrigger asChild>
                                <button
                                    type="button"
                                    aria-label="Lihat penjelasan"
                                    className="shrink-0 cursor-pointer rounded-full p-0.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                >
                                    <CircleAlert className="size-4" />
                                </button>
                            </TooltipTrigger>
                            <TooltipContent side="right" className="max-w-72">
                                Konfigurasi format nomor dokumen untuk{' '}
                                <span className="font-semibold">
                                    {sequence.app_name}
                                </span>
                            </TooltipContent>
                        </Tooltip>
                    </div>
                </div>
            </div>

            {/* Form Body - Mengikuti Browser Default Scroll */}
            <div className="space-y-6 px-6 py-6">
                {/* Section 1: Aturan & Status Nomor */}
                <div className="space-y-3">
                    <h3 className="text-xs font-bold tracking-wider text-muted-foreground uppercase">
                        ATURAN & STATUS NOMOR
                    </h3>
                    <div className="grid gap-4 md:grid-cols-3">
                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-foreground">
                                Mode dasar
                            </label>
                            <NativeSelect
                                value={form.data.profile_code}
                                disabled={!canManage}
                                onChange={(event) =>
                                    form.setData(
                                        'profile_code',
                                        event.target.value,
                                    )
                                }
                            >
                                {profiles.map((profile) => (
                                    <option
                                        key={profile.code}
                                        value={profile.code}
                                    >
                                        {profile.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-foreground">
                                Berlaku untuk
                            </label>
                            <NativeSelect
                                value={form.data.scope_type}
                                disabled={!canManage}
                                onChange={(event) =>
                                    form.setData(
                                        'scope_type',
                                        event.target.value,
                                    )
                                }
                            >
                                {sequence.allowed_scopes.map((scope) => (
                                    <option key={scope} value={scope}>
                                        {scope === 'tenant'
                                            ? 'Seluruh bisnis'
                                            : scope === 'legal_entity'
                                              ? 'Entitas hukum'
                                              : 'Unit operasi'}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-foreground">
                                Status
                            </label>
                            <NativeSelect
                                value={form.data.status}
                                disabled={!canManage}
                                onChange={(event) =>
                                    form.setData('status', event.target.value)
                                }
                            >
                                <option value="draft">Belum aktif</option>
                                <option value="active">Aktif</option>
                                <option value="stopped">Dihentikan</option>
                            </NativeSelect>
                        </div>
                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-foreground">
                                Mulai ulang nomor
                            </label>
                            <NativeSelect
                                value={form.data.reset_period}
                                disabled={!canManage}
                                onChange={(event) =>
                                    form.setData(
                                        'reset_period',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="never">Tidak pernah</option>
                                <option value="year">Setiap tahun</option>
                                <option value="fiscal_year">
                                    Setiap tahun fiskal
                                </option>
                                <option value="fiscal_period">
                                    Setiap periode fiskal
                                </option>
                            </NativeSelect>
                        </div>
                    </div>
                </div>

                {/* Section 2: Kapasitas & Parameter */}
                <div className="space-y-3">
                    <h3 className="text-xs font-bold tracking-wider text-muted-foreground uppercase">
                        KAPASITAS & PARAMETER
                    </h3>
                    <div className="grid gap-4 md:grid-cols-3">
                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-foreground">
                                Nomor awal
                            </label>
                            <Input
                                type="number"
                                min="0"
                                disabled={!canManage}
                                value={form.data.minimum_number}
                                onChange={(event) =>
                                    form.setData(
                                        'minimum_number',
                                        Number(event.target.value),
                                    )
                                }
                            />
                        </div>
                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-foreground">
                                Batas akhir (opsional)
                            </label>
                            <Input
                                type="number"
                                min="1"
                                placeholder="Tanpa batas"
                                disabled={!canManage}
                                value={form.data.maximum_number}
                                onChange={(event) =>
                                    form.setData(
                                        'maximum_number',
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-foreground">
                                Jumlah nomor disiapkan
                            </label>
                            <Input
                                type="number"
                                min="1"
                                max="1000"
                                disabled={
                                    !canManage ||
                                    !form.data.preallocation_enabled
                                }
                                value={form.data.preallocation_quantity}
                                onChange={(event) =>
                                    form.setData(
                                        'preallocation_quantity',
                                        Number(event.target.value),
                                    )
                                }
                            />
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-6 pt-1 text-sm">
                        <label className="flex cursor-pointer items-center gap-2 text-xs font-medium">
                            <Checkbox
                                checked={form.data.is_continuous}
                                disabled={!canManage}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'is_continuous',
                                        checked === true,
                                    )
                                }
                            />{' '}
                            Nomor berkelanjutan
                        </label>
                        <label className="flex cursor-pointer items-center gap-2 text-xs font-medium">
                            <Checkbox
                                checked={form.data.allow_manual}
                                disabled={!canManage || form.data.is_continuous}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'allow_manual',
                                        checked === true,
                                    )
                                }
                            />{' '}
                            Izinkan nomor manual
                        </label>
                        <label className="flex cursor-pointer items-center gap-2 text-xs font-medium">
                            <Checkbox
                                checked={form.data.preallocation_enabled}
                                disabled={!canManage}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'preallocation_enabled',
                                        checked === true,
                                    )
                                }
                            />{' '}
                            Siapkan nomor lebih awal
                        </label>
                    </div>
                </div>

                {/* Section 3: Susunan Format Nomor */}
                <div className="space-y-3">
                    <h3 className="text-xs font-bold tracking-wider text-muted-foreground uppercase">
                        SUSUNAN FORMAT NOMOR
                    </h3>
                    <SegmentEditor
                        value={form.data.segments}
                        disabled={!canManage}
                        onChange={(segments) =>
                            form.setData('segments', segments)
                        }
                    />
                </div>

                {error && (
                    <p className="text-sm font-medium text-destructive">
                        {error}
                    </p>
                )}
            </div>

            {/* Action Footer */}
            <div className="sticky bottom-0 z-10 flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-border bg-card px-6 py-4">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="text-xs font-medium"
                    onClick={() => form.reset()}
                    disabled={!form.isDirty || form.processing}
                >
                    <ArrowLeft className="mr-1.5 size-3.5" /> Batal / Reset
                </Button>

                {canManage && (
                    <div className="flex items-center gap-3">
                        {form.recentlySuccessful && (
                            <span className="mr-2 flex items-center gap-1 text-xs font-medium text-primary">
                                ✓ Tersimpan dengan sukses.
                            </span>
                        )}
                        <Button
                            type="submit"
                            variant="default"
                            size="sm"
                            disabled={!form.isDirty || form.processing}
                            className="px-5 font-medium shadow-xs"
                        >
                            <Save className="mr-1.5 size-3.5" /> Simpan
                        </Button>
                    </div>
                )}
            </div>
        </form>
    );
}

export default function NumberSequences({
    canManage,
    sequences,
    profiles,
}: Props) {
    const [selected, setSelected] = useState(sequences[0]?.id ?? '');
    const [search, setSearch] = useState('');
    const sequence = sequences.find((item) => item.id === selected);

    const filteredSequences = sequences.filter(
        (item) =>
            item.reference_name.toLowerCase().includes(search.toLowerCase()) ||
            item.app_name.toLowerCase().includes(search.toLowerCase()),
    );

    return (
        <>
            <Head title="Nomor dokumen" />
            <main className="mx-auto flex w-full min-w-0 flex-col gap-6 p-6">
                <Heading
                    title="Nomor dokumen"
                    description="Atur format dan susunan nomor otomatis dokumen operasional bisnis."
                    icon={Hash}
                />
                <div className="grid items-start gap-6 lg:grid-cols-[22rem_1fr]">
                    {/* Sidebar Jenis Nomor Scrollable Memanjang Ke Paling Bawah Layar User */}
                    <Card className="sticky top-6 flex h-[calc(100vh-5.5rem)] flex-col overflow-hidden">
                        <div className="shrink-0 space-y-3 border-b border-border bg-card p-4">
                            <h3 className="text-base font-bold tracking-tight text-foreground">
                                Jenis nomor
                            </h3>
                            <div className="relative">
                                <Search className="absolute top-2.5 left-2.5 h-3.5 w-3.5 text-muted-foreground" />
                                <Input
                                    type="search"
                                    placeholder="Cari jenis nomor…"
                                    className="h-8 w-full bg-background pl-8 text-xs"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                />
                            </div>
                        </div>
                        <CardContent className="min-h-0 flex-1 space-y-1 overflow-y-auto p-2">
                            {filteredSequences.map((item) => {
                                const isSelected = item.id === selected;

                                return (
                                    <button
                                        key={item.id}
                                        type="button"
                                        className={`group flex w-full items-center justify-between rounded-md border-l-4 p-3 text-left transition-all duration-150 ${
                                            isSelected
                                                ? 'border-l-primary bg-primary/10 font-semibold text-primary shadow-xs'
                                                : 'border-l-transparent text-foreground hover:bg-accent/60'
                                        }`}
                                        onClick={() => setSelected(item.id)}
                                    >
                                        <div className="min-w-0 flex-1 pr-2">
                                            <span className="block truncate text-sm leading-tight font-medium">
                                                {item.reference_name}
                                            </span>
                                            <span className="mt-0.5 block truncate text-xs font-normal text-muted-foreground">
                                                {item.app_name}
                                            </span>
                                        </div>
                                        <ChevronRight className="size-4 shrink-0 text-muted-foreground/60 group-hover:text-foreground" />
                                    </button>
                                );
                            })}
                            {filteredSequences.length === 0 && (
                                <p className="p-4 text-center text-xs text-muted-foreground italic">
                                    {search
                                        ? 'Tidak ada nomor yang cocok.'
                                        : 'Belum ada aplikasi yang mendaftarkan nomor.'}
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Main Form Config Panel Mengikuti Default Browser Scroll */}
                    {sequence && (
                        <Card className="gap-0 overflow-hidden border border-border bg-card p-0 shadow-xs">
                            <SequenceForm
                                key={sequence.id}
                                sequence={sequence}
                                profiles={profiles}
                                canManage={canManage}
                            />
                        </Card>
                    )}
                </div>
            </main>
        </>
    );
}

NumberSequences.layout = {
    breadcrumbs: [
        { title: 'Nomor dokumen', href: '/settings/number-sequences' },
    ] satisfies BreadcrumbItem[],
};
