import { Button } from '@apperp/ui/button';
import { Select } from '@apperp/ui/select';
import { Plus, X } from 'lucide-react';
import type { RefObject } from 'react';
import {
    dimension,
    dimensionField,
    TIME_GRANULARITIES,
} from '@/lib/analytics/query';
import type { DatasetField, QueryDimension } from '@/lib/analytics/types';
import { cn } from '@/lib/utils';

/** Kolom tanggal yang baru dipilih dikelompokkan per bulan sampai pengguna memilih ukuran lain. */
function initialDimension(field: DatasetField): QueryDimension {
    return field.time ? dimension(field.key, 'month') : field.key;
}

/**
 * Langkah Kelompokkan menurut: kolom pengelompokan berurutan; kolom tanggal meminta per hari, minggu, bulan,
 * kuartal, atau tahun. Kolom yang sudah dipakai tidak ditawarkan lagi. Batas jumlahnya diperiksa server, dan
 * pesannya tampil di hasil.
 */
export function DimensionPicker({
    fields,
    value,
    onChange,
    portalContainer,
}: {
    fields: DatasetField[];
    value: QueryDimension[];
    onChange: (dimensions: QueryDimension[]) => void;
    portalContainer?: RefObject<HTMLElement | null>;
}) {
    const byKey = new Map(fields.map((field) => [field.key, field]));
    const chosen = value.map(dimensionField);
    const remaining = fields.filter((field) => !chosen.includes(field.key));
    const replace = (index: number, next: QueryDimension) =>
        onChange(value.map((item, i) => (i === index ? next : item)));

    return (
        <div className="flex flex-col gap-3">
            {value.map((item, index) => {
                const key = dimensionField(item);
                const field = byKey.get(key);
                const caption = field?.caption ?? key;

                return (
                    <div key={key} className="flex items-start gap-1">
                        <div
                            className={cn(
                                'grid min-w-0 flex-1 gap-3',
                                field?.time && 'sm:grid-cols-2',
                            )}
                        >
                            <Select
                                label={`Kelompok ${index + 1}`}
                                items={[
                                    { value: key, label: caption },
                                    ...remaining.map((option) => ({
                                        value: option.key,
                                        label: option.caption,
                                    })),
                                ]}
                                value={key}
                                onValueChange={(next) => {
                                    const picked =
                                        next === null
                                            ? undefined
                                            : byKey.get(next);

                                    if (picked && picked.key !== key) {
                                        replace(
                                            index,
                                            initialDimension(picked),
                                        );
                                    }
                                }}
                                searchPlaceholder="Cari kolom"
                                emptyMessage="Kolom tidak ditemukan."
                                portalContainer={portalContainer}
                            />
                            {field?.time && (
                                <Select
                                    label="Per"
                                    items={TIME_GRANULARITIES.map((entry) => ({
                                        value: entry.value,
                                        label: entry.caption,
                                    }))}
                                    value={
                                        typeof item === 'string'
                                            ? null
                                            : (item.granularity ?? null)
                                    }
                                    onValueChange={(next) => {
                                        const granularity =
                                            TIME_GRANULARITIES.find(
                                                (entry) => entry.value === next,
                                            )?.value;

                                        replace(
                                            index,
                                            dimension(key, granularity),
                                        );
                                    }}
                                    placeholder="Tanggal persis"
                                    searchPlaceholder="Cari"
                                    emptyMessage="Tidak ditemukan."
                                    portalContainer={portalContainer}
                                />
                            )}
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="mt-0.5"
                            aria-label={`Hapus pengelompokan ${caption}`}
                            title={`Hapus pengelompokan ${caption}`}
                            onClick={() =>
                                onChange(value.filter((_, i) => i !== index))
                            }
                        >
                            <X />
                        </Button>
                    </div>
                );
            })}
            {remaining.length > 0 && (
                <div className="flex items-center gap-1">
                    <Plus
                        className="size-4 shrink-0 text-muted-foreground"
                        aria-hidden
                    />
                    <Select
                        label={
                            value.length === 0
                                ? 'Kelompokkan menurut kolom'
                                : 'Tambah pengelompokan'
                        }
                        items={remaining.map((field) => ({
                            value: field.key,
                            label: field.caption,
                        }))}
                        value={null}
                        onValueChange={(next) => {
                            const picked =
                                next === null ? undefined : byKey.get(next);

                            if (picked) {
                                onChange([...value, initialDimension(picked)]);
                            }
                        }}
                        searchPlaceholder="Cari kolom"
                        emptyMessage="Kolom tidak ditemukan."
                        portalContainer={portalContainer}
                    />
                </div>
            )}
        </div>
    );
}
