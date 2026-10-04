import { Field, FieldDescription, FieldError } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import type { RefObject } from 'react';
import { useState } from 'react';
import { isRelativeRange, RELATIVE_RANGES } from '@/lib/analytics/query';
import type { DatasetDescription, QueryTimeRange } from '@/lib/analytics/types';

const ALL = 'all';

const CUSTOM = 'custom';

/**
 * Langkah Periode: periode relatif (dihitung server menurut zona waktu pengguna setiap kali dijalankan, jadi
 * "Tahun ini" tetap tahun berjalan) atau rentang tanggal sendiri dengan sintaks saringan tanggal. Data dengan
 * beberapa kolom tanggal meminta kolom mana yang dibatasi; tanpa pilihan, kolom tanggal utama data.
 */
export function TimeRangePicker({
    dataset,
    value,
    error,
    onChange,
    portalContainer,
}: {
    dataset: DatasetDescription;
    value: QueryTimeRange | undefined;
    /** Pesan server bila rentang sendiri tidak terbaca. */
    error?: string | null;
    onChange: (range: QueryTimeRange | undefined) => void;
    portalContainer?: RefObject<HTMLElement | null>;
}) {
    // Rentang sendiri yang belum diisi belum menjadi bagian query, jadi pilihan itu dipegang di sini.
    const [customChosen, setCustomChosen] = useState(false);
    const times = dataset.fields.filter((field) =>
        dataset.times.includes(field.key),
    );

    if (times.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                Data ini tidak punya kolom tanggal untuk membatasi periode.
            </p>
        );
    }

    const fallback = dataset.default_time ?? times[0].key;
    const field = value?.field ?? fallback;
    const range = value?.range;
    const mode =
        range === undefined
            ? customChosen
                ? CUSTOM
                : ALL
            : isRelativeRange(range)
              ? range
              : CUSTOM;
    const emit = (nextField: string, nextRange: string | undefined) =>
        onChange(
            nextRange === undefined || nextRange === ''
                ? undefined
                : {
                      ...(nextField === dataset.default_time
                          ? {}
                          : { field: nextField }),
                      range: nextRange,
                  },
        );

    return (
        <div className="flex flex-col gap-3">
            <Select
                label="Periode"
                items={[
                    { value: ALL, label: 'Semua tanggal' },
                    ...RELATIVE_RANGES.map((item) => ({
                        value: item.token,
                        label: item.caption,
                    })),
                    { value: CUSTOM, label: 'Rentang tanggal sendiri' },
                ]}
                value={mode}
                onValueChange={(next) => {
                    if (next === null || next === mode) {
                        return;
                    }

                    setCustomChosen(next === CUSTOM);
                    emit(
                        field,
                        next === ALL || next === CUSTOM ? undefined : next,
                    );
                }}
                searchPlaceholder="Cari periode"
                emptyMessage="Periode tidak ditemukan."
                portalContainer={portalContainer}
            />
            {mode === CUSTOM && (
                <RangeInput
                    key={range ?? ''}
                    value={range ?? ''}
                    error={error ?? undefined}
                    onCommit={(next) => emit(field, next)}
                />
            )}
            {times.length > 1 && mode !== ALL && (
                <Select
                    label="Menurut tanggal"
                    items={times.map((time) => ({
                        value: time.key,
                        label: time.caption,
                    }))}
                    value={field}
                    onValueChange={(next) => {
                        if (next !== null && next !== field) {
                            emit(next, range);
                        }
                    }}
                    searchPlaceholder="Cari kolom"
                    emptyMessage="Kolom tidak ditemukan."
                    portalContainer={portalContainer}
                />
            )}
        </div>
    );
}

/** Rentang tanggal dengan sintaks saringan; dipakai saat Enter atau saat isian ditinggalkan. */
function RangeInput({
    value,
    error,
    onCommit,
}: {
    value: string;
    error?: string;
    onCommit: (value: string) => void;
}) {
    const [draft, setDraft] = useState(value);

    const commit = () => {
        if (draft.trim() !== value) {
            onCommit(draft.trim());
        }
    };

    return (
        <Field data-invalid={error ? true : undefined}>
            <Input
                label="Rentang tanggal"
                value={draft}
                maxLength={100}
                aria-invalid={error ? true : undefined}
                onChange={(event) => setDraft(event.target.value)}
                onBlur={commit}
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        commit();
                    }
                }}
            />
            {error ? (
                <FieldError>{error}</FieldError>
            ) : (
                <FieldDescription>
                    Contoh: 01/01/2026..31/03/2026, &gt;=01/07/2026
                </FieldDescription>
            )}
        </Field>
    );
}
