import { Head, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus, Save, Search, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@apperp/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import { Checkbox } from '@apperp/ui/checkbox';
import { Input } from '@apperp/ui/input';
import { NativeSelect } from '@apperp/ui/native-select';
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

function previewNumber(segments: Segment[], number: number): string {
    const year = new Date().getFullYear();

    return segments
        .map((segment) => {
            if (segment.type === 'constant') return segment.value ?? '';
            if (segment.type === 'number')
                return String(number).padStart(segment.length ?? 1, '0');
            if (segment.type === 'year') return String(year);
            if (segment.type === 'scope') return 'ORG';
            if (segment.type === 'fiscal_year') return `FY${year}`;

            return '01'.padStart(segment.length ?? 2, '0');
        })
        .join('');
}

function SegmentEditor({
    value,
    onChange,
}: {
    value: Segment[];
    onChange: (segments: Segment[]) => void;
}) {
    const update = (index: number, patch: Partial<Segment>) =>
        onChange(
            value.map((segment, current) =>
                current === index ? { ...segment, ...patch } : segment,
            ),
        );
    const move = (index: number, direction: -1 | 1) => {
        const next = [...value];
        const target = index + direction;
        if (target < 0 || target >= next.length) return;
        [next[index], next[target]] = [next[target], next[index]];
        onChange(next);
    };

    return (
        <div className="space-y-2.5">
            {value.map((segment, index) => (
                <div
                    key={`${segment.type}-${index}`}
                    className="flex flex-wrap items-center gap-2 rounded-lg border bg-card p-3 shadow-xs transition-colors hover:border-primary/40"
                >
                    <NativeSelect
                        label={`Bagian ${index + 1}`}
                        value={segment.type}
                        onChange={(event) =>
                            update(index, {
                                type: event.target.value as Segment['type'],
                                value: '',
                                length:
                                    event.target.value === 'number'
                                        ? 6
                                        : event.target.value === 'fiscal_period'
                                            ? 2
                                            : undefined,
                            })
                        }
                    >
                        <option value="constant">Teks tetap</option>
                        <option value="number">Nomor</option>
                        <option value="year">Tahun kalender</option>
                        <option value="scope">Kode organisasi</option>
                        <option value="fiscal_year">Tahun fiskal</option>
                        <option value="fiscal_period">Periode fiskal</option>
                    </NativeSelect>
                    {segment.type === 'constant' && (
                        <Input
                            aria-label="Teks tetap"
                            placeholder="Contoh: INV-"
                            className="text-xs"
                            value={segment.value ?? ''}
                            onChange={(event) =>
                                update(index, { value: event.target.value })
                            }
                        />
                    )}
                    {segment.type === 'number' && (
                        <Input
                            aria-label="Jumlah digit"
                            className="w-28 text-xs font-mono"
                            type="number"
                            min="1"
                            max="18"
                            value={segment.length ?? 6}
                            onChange={(event) =>
                                update(index, {
                                    length: Number(event.target.value),
                                })
                            }
                        />
                    )}
                    {segment.type === 'fiscal_period' && (
                        <Input
                            aria-label="Jumlah digit periode"
                            className="w-28 text-xs font-mono"
                            type="number"
                            min="1"
                            max="4"
                            value={segment.length ?? 2}
                            onChange={(event) =>
                                update(index, {
                                    length: Number(event.target.value),
                                })
                            }
                        />
                    )}
                    <div className="ml-auto flex items-center gap-1">
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            className="h-8 w-8"
                            onClick={() => move(index, -1)}
                            aria-label="Naikkan urutan"
                        >
                            <ArrowUp className="h-3.5 w-3.5" />
                        </Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            className="h-8 w-8"
                            onClick={() => move(index, 1)}
                            aria-label="Turunkan urutan"
                        >
                            <ArrowDown className="h-3.5 w-3.5" />
                        </Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            className="h-8 w-8 text-destructive hover:bg-destructive/10"
                            onClick={() =>
                                onChange(
                                    value.filter((_, current) => current !== index),
                                )
                            }
                            aria-label="Hapus bagian"
                        >
                            <Trash2 className="h-3.5 w-3.5" />
                        </Button>
                    </div>
                </div>
            ))}
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="text-xs font-medium"
                onClick={() =>
                    onChange([...value, { type: 'constant', value: '' }])
                }
            >
                <Plus className="mr-1 h-3.5 w-3.5" /> Tambah bagian
            </Button>
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
    const maximum =
        form.data.maximum_number === ''
            ? null
            : Number(form.data.maximum_number);
    const error = Object.values(form.errors)[0];

    return (
        <form
            className="flex flex-col min-h-0 flex-1 overflow-hidden"
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
            {/* Header Sticky: Judul Kode & Live Preview Format Sejajar */}
            <div className="shrink-0 border-b border-border bg-card px-6 py-4 flex flex-wrap items-center justify-between gap-4 sticky top-0 z-10">
                <div>
                    <h2 className="text-lg font-semibold tracking-tight text-foreground">{sequence.reference_name}</h2>
                    <p className="text-xs text-muted-foreground mt-0.5">
                        Konfigurasi format nomor dokumen untuk <span className="font-medium text-foreground">{sequence.app_name}</span>
                    </p>
                </div>

                {/* Pratinjau Format Real-Time (Tetap Terlihat Saat Form Discroll) */}
                <div className="rounded-xl border border-primary/20 bg-primary/5 px-4 py-2 flex flex-col items-end shrink-0 shadow-xs">
                    <span className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                        Pratinjau Format
                    </span>
                    <output className="font-mono text-base font-bold tracking-wider text-primary">
                        {previewNumber(form.data.segments, form.data.minimum_number)}
                        {maximum === null
                            ? ' ...'
                            : ` – ${previewNumber(form.data.segments, maximum)}`}
                    </output>
                </div>
            </div>

            {/* Scrollable Form Body */}
            <div className="min-h-0 flex-1 overflow-y-auto px-6 py-6 space-y-6">
                {/* Kartu Section 1: Aturan & Status Nomor */}
                <div className="space-y-3">
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                        Aturan & Status Nomor
                    </h3>
                    <div className="grid gap-4 md:grid-cols-3">
                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-foreground">Mode dasar</label>
                            <NativeSelect
                                value={form.data.profile_code}
                                disabled={!canManage}
                                onChange={(event) =>
                                    form.setData('profile_code', event.target.value)
                                }
                            >
                                {profiles.map((profile) => (
                                    <option key={profile.code} value={profile.code}>
                                        {profile.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-foreground">Berlaku untuk</label>
                            <NativeSelect
                                value={form.data.scope_type}
                                disabled={!canManage}
                                onChange={(event) =>
                                    form.setData('scope_type', event.target.value)
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
                            <label className="text-xs font-medium text-foreground">Status</label>
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
                            <label className="text-xs font-medium text-foreground">Mulai ulang nomor</label>
                            <NativeSelect
                                value={form.data.reset_period}
                                disabled={!canManage}
                                onChange={(event) =>
                                    form.setData('reset_period', event.target.value)
                                }
                            >
                                <option value="never">Tidak pernah</option>
                                <option value="calendar_year">Tiap tahun kalender</option>
                                <option value="fiscal_year">Tiap tahun fiskal</option>
                                <option value="fiscal_period">Tiap periode fiskal</option>
                            </NativeSelect>
                        </div>
                    </div>
                </div>

                {/* Kartu Section 2: Kapasitas & Parameter */}
                <div className="space-y-3">
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                        Kapasitas & Parameter
                    </h3>
                    <div className="grid gap-4 md:grid-cols-3">
                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-foreground">Nomor awal</label>
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
                            <label className="text-xs font-medium text-foreground">Batas akhir <span className="font-normal text-muted-foreground">(opsional)</span></label>
                            <Input
                                type="number"
                                min="0"
                                disabled={!canManage}
                                value={form.data.maximum_number}
                                onChange={(event) =>
                                    form.setData('maximum_number', event.target.value)
                                }
                            />
                        </div>
                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-foreground">Jumlah nomor disiapkan</label>
                            <Input
                                type="number"
                                min="1"
                                max="1000"
                                disabled={!canManage || !form.data.preallocation_enabled}
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
                    <div className="flex flex-wrap gap-5 pt-1 text-sm">
                        <label className="flex items-center gap-2 cursor-pointer">
                            <Checkbox
                                checked={form.data.is_continuous}
                                disabled={!canManage}
                                onCheckedChange={(checked) =>
                                    form.setData('is_continuous', checked === true)
                                }
                            />{' '}
                            Nomor berkelanjutan
                        </label>
                        <label className="flex items-center gap-2 cursor-pointer">
                            <Checkbox
                                checked={form.data.allow_manual}
                                disabled={!canManage || form.data.is_continuous}
                                onCheckedChange={(checked) =>
                                    form.setData('allow_manual', checked === true)
                                }
                            />{' '}
                            Izinkan nomor manual
                        </label>
                        <label className="flex items-center gap-2 cursor-pointer">
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

                {/* Kartu Section 3: Susunan Format Nomor */}
                <div className="space-y-3">
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                        Susunan Format Nomor
                    </h3>
                    <SegmentEditor
                        value={form.data.segments}
                        onChange={(segments) => form.setData('segments', segments)}
                    />
                </div>

                {error && <p className="text-sm text-destructive font-medium">{error}</p>}
                {canManage && (
                    <div className="flex items-center gap-3 pt-2">
                        <Button type="submit" disabled={form.processing} className="px-5">
                            <Save className="mr-1.5 h-4 w-4" /> Simpan Perubahan
                        </Button>
                        {form.recentlySuccessful && (
                            <span className="text-sm font-medium text-emerald-600 dark:text-emerald-400 flex items-center gap-1">
                                ✓ Tersimpan dengan sukses.
                            </span>
                        )}
                    </div>
                )}
            </div>
        </form>
    );
}

export default function NumberSequences({
    canManage,
    tenant,
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
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-6 p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Nomor dokumen</h1>
                    <p className="text-sm text-muted-foreground mt-0.5">
                        Atur nomor otomatis untuk <strong className="font-semibold text-foreground">{tenant.name}</strong>. Nomor baru
                        tidak dapat dibuat sebelum statusnya aktif.
                    </p>
                </div>
                <div className="grid gap-6 lg:grid-cols-[22rem_1fr] items-start">
                    {/* Sidebar Jenis Nomor Scrollable */}
                    <Card className="flex flex-col h-[calc(100vh-13rem)] overflow-hidden">
                        <CardHeader className="shrink-0 pb-3">
                            <CardTitle className="text-base font-semibold">Jenis nomor</CardTitle>
                            <CardDescription className="text-xs">
                                Pilih jenis nomor dokumen yang akan dikonfigurasi.
                            </CardDescription>
                            <div className="relative mt-2">
                                <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                                <Input
                                    type="search"
                                    placeholder="Cari jenis nomor…"
                                    className="pl-8 text-xs h-8"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                />
                            </div>
                        </CardHeader>
                        <CardContent className="min-h-0 flex-1 overflow-y-auto space-y-1.5 px-3 pb-3">
                            {filteredSequences.map((item) => {
                                const isSelected = item.id === selected;

                                return (
                                    <button
                                        key={item.id}
                                        type="button"
                                        className={`group flex w-full items-center justify-between rounded-lg border-l-4 p-3 text-left transition-all duration-150 ${isSelected
                                                ? 'border-l-primary bg-primary/10 font-medium text-primary shadow-xs'
                                                : 'border-l-transparent text-muted-foreground hover:bg-muted/50 hover:text-foreground'
                                            }`}
                                        onClick={() => setSelected(item.id)}
                                    >
                                        <span className="truncate text-xs leading-tight font-medium pr-2">
                                            {item.reference_name}
                                        </span>
                                        <span className="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[10px] font-normal text-muted-foreground group-hover:bg-muted/80">
                                            {item.app_name}
                                        </span>
                                    </button>
                                );
                            })}
                            {filteredSequences.length === 0 && (
                                <p className="p-3 text-center text-xs text-muted-foreground">
                                    {search ? 'Tidak ada nomor yang cocok.' : 'Belum ada aplikasi yang mendaftarkan nomor.'}
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Main Form Config Panel Pas 1 Halaman */}
                    {sequence && (
                        <Card className="h-[calc(100vh-13rem)] overflow-hidden p-0 gap-0">
                            <div className="flex flex-col flex-1 min-h-0 h-full">
                                <SequenceForm
                                    key={sequence.id}
                                    sequence={sequence}
                                    profiles={profiles}
                                    canManage={canManage}
                                />
                            </div>
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


