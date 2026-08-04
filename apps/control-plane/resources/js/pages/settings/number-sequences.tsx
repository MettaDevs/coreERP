import { Head, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus, Save, Trash2 } from 'lucide-react';
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
        <div className="space-y-2">
            {value.map((segment, index) => (
                <div
                    key={`${segment.type}-${index}`}
                    className="flex flex-wrap items-center gap-2 rounded-md border p-2"
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
                            value={segment.value ?? ''}
                            onChange={(event) =>
                                update(index, { value: event.target.value })
                            }
                        />
                    )}
                    {segment.type === 'number' && (
                        <Input
                            aria-label="Jumlah digit"
                            className="w-28"
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
                            className="w-28"
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
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        onClick={() => move(index, -1)}
                        aria-label="Naikkan urutan"
                    >
                        <ArrowUp />
                    </Button>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        onClick={() => move(index, 1)}
                        aria-label="Turunkan urutan"
                    >
                        <ArrowDown />
                    </Button>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        onClick={() =>
                            onChange(
                                value.filter((_, current) => current !== index),
                            )
                        }
                        aria-label="Hapus bagian"
                    >
                        <Trash2 />
                    </Button>
                </div>
            ))}
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() =>
                    onChange([...value, { type: 'constant', value: '' }])
                }
            >
                <Plus /> Tambah bagian
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
            className="space-y-4"
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
            <div className="grid gap-3 md:grid-cols-3">
                <NativeSelect
                    label="Mode dasar"
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
                <NativeSelect
                    label="Berlaku untuk"
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
                <NativeSelect
                    label="Status"
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
                <NativeSelect
                    label="Mulai ulang nomor"
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
                <Input
                    label="Nomor awal"
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
                <Input
                    label="Batas akhir (opsional)"
                    type="number"
                    min="0"
                    disabled={!canManage}
                    value={form.data.maximum_number}
                    onChange={(event) =>
                        form.setData('maximum_number', event.target.value)
                    }
                />
                <Input
                    label="Jumlah nomor yang disiapkan"
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
            <div className="flex flex-wrap gap-5 text-sm">
                <label className="flex items-center gap-2">
                    <Checkbox
                        checked={form.data.is_continuous}
                        disabled={!canManage}
                        onCheckedChange={(checked) =>
                            form.setData('is_continuous', checked === true)
                        }
                    />{' '}
                    Nomor berkelanjutan
                </label>
                <label className="flex items-center gap-2">
                    <Checkbox
                        checked={form.data.allow_manual}
                        disabled={!canManage || form.data.is_continuous}
                        onCheckedChange={(checked) =>
                            form.setData('allow_manual', checked === true)
                        }
                    />{' '}
                    Izinkan nomor manual
                </label>
                <label className="flex items-center gap-2">
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
            <div>
                <p className="mb-2 text-sm font-medium">Susunan nomor</p>
                <SegmentEditor
                    value={form.data.segments}
                    onChange={(segments) => form.setData('segments', segments)}
                />
            </div>
            <p className="text-sm text-muted-foreground">
                Contoh nomor:{' '}
                <output className="font-medium text-foreground">
                    {previewNumber(
                        form.data.segments,
                        form.data.minimum_number,
                    )}
                    {maximum === null
                        ? ' dan seterusnya'
                        : ` – ${previewNumber(form.data.segments, maximum)}`}
                </output>
                {maximum !== null &&
                    ` (rentang ${form.data.minimum_number}–${maximum})`}
            </p>
            {error && <p className="text-sm text-destructive">{error}</p>}
            {canManage && (
                <div className="flex items-center gap-3">
                    <Button type="submit" disabled={form.processing}>
                        <Save /> Simpan
                    </Button>
                    {form.recentlySuccessful && (
                        <span className="text-sm text-muted-foreground">
                            Tersimpan.
                        </span>
                    )}
                </div>
            )}
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
    const sequence = sequences.find((item) => item.id === selected);

    return (
        <>
            <Head title="Nomor dokumen" />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-6 p-6">
                <div>
                    <h1 className="text-2xl font-semibold">Nomor dokumen</h1>
                    <p className="text-muted-foreground">
                        Atur nomor otomatis untuk {tenant.name}. Nomor baru
                        tidak dapat dibuat sebelum statusnya aktif.
                    </p>
                </div>
                <div className="grid gap-6 lg:grid-cols-[20rem_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Jenis nomor</CardTitle>
                            <CardDescription>
                                Pilih nomor yang akan diatur.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {sequences.map((item) => (
                                <Button
                                    key={item.id}
                                    type="button"
                                    variant={
                                        item.id === selected
                                            ? 'default'
                                            : 'outline'
                                    }
                                    className="h-auto w-full justify-start text-left"
                                    onClick={() => setSelected(item.id)}
                                >
                                    <span>
                                        <span className="block">
                                            {item.reference_name}
                                        </span>
                                        <span className="block text-xs opacity-70">
                                            {item.app_name} ·{' '}
                                            {item.status === 'active'
                                                ? 'aktif'
                                                : item.status === 'stopped'
                                                  ? 'dihentikan'
                                                  : 'belum aktif'}
                                        </span>
                                    </span>
                                </Button>
                            ))}
                            {sequences.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada aplikasi siap pakai yang
                                    mendaftarkan nomor.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                    {sequence && (
                        <Card>
                            <CardHeader>
                                <CardTitle>{sequence.reference_name}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <SequenceForm
                                    key={sequence.id}
                                    sequence={sequence}
                                    profiles={profiles}
                                    canManage={canManage}
                                />
                            </CardContent>
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
