import { useEffect, useRef, useState } from 'react';
import type { SetStateAction } from 'react';
import { ActionButton } from '@apperp/ui/action-button';
import { Button } from '@apperp/ui/button';

import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@apperp/ui/field';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { Switch } from '@apperp/ui/switch';
import RecordLookup from '../_shared/RecordLookup';
import { api, errorMessage } from '../api';
import type { ApiValidationErrors } from '../api';
import {
    monthsPerPeriod,
    serviceLifeMonths,
    serviceLifeLabel,
} from './serviceLife';
import ServiceLifeFields from './ServiceLifeFields';
import { optionLabel, useMasterOptions } from './useMasterOptions';

export type GroupBookRow = {
    buku_id: string;
    depreciation_profile_id: string;
    alternative_profile_id: string;
    useful_life_periods: string;
    convention: string;
    depreciate: boolean;
    round_off_depreciation: string;
};
export function groupBookRowsPayload(rows: GroupBookRow[]) {
    return rows.map((row) => ({
        buku_id: row.buku_id,
        depreciation_profile_id: row.depreciation_profile_id || null,
        alternative_profile_id: row.alternative_profile_id || null,
        useful_life_periods:
            row.useful_life_periods === ''
                ? null
                : Number(row.useful_life_periods),
        convention: row.convention || null,
        depreciate: row.depreciate,
        round_off_depreciation:
            row.round_off_depreciation === ''
                ? null
                : Number(row.round_off_depreciation),
    }));
}

type Row = GroupBookRow;
type Draft = {
    rows: Row[];
    onChange: (rows: Row[]) => void;
    errors?: Record<number, string[]>;
    error?: string[];
};
type Props = { canEdit: boolean } & (
    | {
          draft: Draft;
          groupId?: never;
          onChange?: never;
          validationErrors?: never;
      }
    | {
          draft?: never;
          groupId: string;
          onChange: (rows: GroupBookRow[]) => void;
          validationErrors?: ApiValidationErrors;
      }
);

const CONVENTIONS: { value: string; label: string }[] = [
    { value: '', label: 'Tanpa penyesuaian' },
    { value: 'full_month', label: 'Bulan perolehan penuh' },
    { value: 'mid_month_1st', label: 'Tengah bulan (awal bulan)' },
    { value: 'mid_month_15th', label: 'Tengah bulan (tanggal 15)' },
    { value: 'mid_quarter', label: 'Tengah kuartal' },
    { value: 'half_year', label: 'Setengah tahun' },
    {
        value: 'half_year_start_of_year',
        label: 'Setengah tahun (mulai awal tahun)',
    },
    {
        value: 'half_year_next_year',
        label: 'Setengah tahun (mulai tahun depan)',
    },
];

const emptyRow = (bukuId: string): Row => ({
    buku_id: bukuId,
    depreciation_profile_id: '',
    alternative_profile_id: '',
    useful_life_periods: '',
    convention: '',
    depreciate: true,
    round_off_depreciation: '',
});

/**
 * Matriks group x buku, disunting di dalam form group aset.
 *
 * Satu baris berarti "aset dari group ini mendapat buku tersebut", lengkap dengan masa
 * manfaat dan perlakuan periode pertama. Karena itu satu group dapat
 * menyusut komersial dan fiskal sekaligus dengan aturan yang berbeda.
 */
export default function GroupBookMatrix({
    groupId,
    canEdit,
    onChange,
    validationErrors,
    draft,
}: Props) {
    const [storedRows, setStoredRows] = useState<Row[]>([]);
    const [loadedGroupId, setLoadedGroupId] = useState<string | null>(null);
    const [loadAttempt, setLoadAttempt] = useState(0);
    const rows = draft?.rows ?? storedRows;
    const staging = draft !== undefined;
    const setRows = (update: SetStateAction<Row[]>) => {
        if (draft) {
            draft.onChange(
                typeof update === 'function' ? update(rows) : update,
            );
        } else {
            const next = typeof update === 'function' ? update(rows) : update;
            setStoredRows(next);
            onChange?.(next);
        }
    };
    const [error, setError] = useState('');
    const fieldError = (field: string) =>
        validationErrors?.[`details.books.${field}`]?.join(' ');
    const containerRef = useRef<HTMLDivElement>(null);
    const profiles = useMasterOptions(
        'profil-penyusutan',
        '',
        rows
            .flatMap((row) => [
                row.depreciation_profile_id,
                row.alternative_profile_id,
            ])
            .filter(Boolean),
    );
    const bookSource = useMasterOptions(
        'buku-penyusutan',
        '',
        rows.map((row) => row.buku_id),
    );
    const books = bookSource.options;

    useEffect(() => {
        if (staging || !groupId) {
            return;
        }

        let cancelled = false;
        api<{ data: Record<string, unknown>[] }>(
            `/group-aset/${groupId}/buku-penyusutan`,
        )
            .then((matrix) => {
                if (cancelled) {
                    return;
                }

                setStoredRows(
                    matrix.data.map((row) => ({
                        buku_id: String(row.buku_id ?? ''),
                        depreciation_profile_id: String(
                            row.depreciation_profile_id ?? '',
                        ),
                        alternative_profile_id: String(
                            row.alternative_profile_id ?? '',
                        ),
                        useful_life_periods:
                            row.useful_life_periods === null ||
                            row.useful_life_periods === undefined
                                ? ''
                                : String(row.useful_life_periods),
                        convention: String(row.convention ?? ''),
                        depreciate: Boolean(row.depreciate ?? true),
                        round_off_depreciation:
                            row.round_off_depreciation === null ||
                            row.round_off_depreciation === undefined
                                ? ''
                                : String(row.round_off_depreciation),
                    })),
                );
                setLoadedGroupId(groupId);
            })
            .catch((caught) => {
                if (!cancelled) {
                    setError(
                        errorMessage(
                            caught,
                            'Matriks buku penyusutan belum dapat dimuat.',
                        ),
                    );
                }
            });

        return () => {
            cancelled = true;
        };
    }, [groupId, staging, loadAttempt]);

    const label = (id: string) => {
        const book = books.find((item) => item.id === id);

        return book ? `${book.kode} — ${book.nama}` : '';
    };
    const unused = books.filter(
        (book) => !rows.some((row) => row.buku_id === book.id),
    );
    const patch = (index: number, changes: Partial<Row>) =>
        setRows((current) =>
            current.map((row, i) =>
                i === index ? { ...row, ...changes } : row,
            ),
        );

    const profileFor = (row: Row) =>
        profiles.options.find(
            (profile) =>
                profile.id ===
                (row.depreciation_profile_id ||
                    books.find((book) => book.id === row.buku_id)
                        ?.depreciation_profile_id),
        );

    if (!staging && loadedGroupId !== groupId) {
        return error ? (
            <div role="alert" className="space-y-2">
                <p className="text-destructive text-sm">{error}</p>
                <Button
                    type="button"
                    variant="ghost"
                    onClick={() => {
                        setError('');
                        setLoadAttempt((attempt) => attempt + 1);
                    }}
                >
                    Muat ulang
                </Button>
            </div>
        ) : (
            <p role="status" className="text-muted-foreground text-sm">
                Memuat buku dan metode penyusutan…
            </p>
        );
    }

    return (
        <div
            ref={(node) => {
                containerRef.current =
                    node?.closest<HTMLDivElement>(
                        '[data-slot="page-dialog-contents"]',
                    ) ?? node;
            }}
            className={staging ? 'space-y-4' : 'space-y-4 border-t px-5 py-4'}
        >
            {!staging && (
                <div>
                    <p className="font-semibold">Buku dan Metode Penyusutan</p>
                    <p className="text-muted-foreground text-sm">
                        Aset dari group ini akan mendapat satu buku untuk tiap
                        baris di bawah. Buku komersial dan fiskal boleh memakai
                        metode, masa manfaat, dan tanggal berlaku yang berbeda.
                    </p>
                </div>
            )}

            {rows.length === 0 && (
                <p className="text-muted-foreground text-sm">
                    Belum ada buku. Aset dari group ini belum dapat ditempatkan
                    sampai matriks memiliki buku dan metode yang bisa dihitung.
                </p>
            )}

            {rows.map((row, index) => {
                const profile = profileFor(row);
                const inheritedLife = serviceLifeLabel(
                    profile?.useful_life_periods,
                    profile?.frequency,
                );

                return (
                    <div
                        key={row.buku_id}
                        className="space-y-3 rounded-md border p-3"
                    >
                        <div className="flex items-center justify-between gap-3">
                            <span className="font-medium">
                                {label(row.buku_id) || 'Buku tidak dikenal'}
                            </span>
                            {canEdit && (
                                <ActionButton
                                    action="delete"
                                    type="button"
                                    onClick={() =>
                                        setRows(
                                            rows.filter((_, i) => i !== index),
                                        )
                                    }
                                />
                            )}
                        </div>
                        <FieldError>
                            {draft?.errors?.[index]?.join(' ') ||
                                Object.entries(validationErrors ?? {})
                                    .filter(([field]) =>
                                        field.startsWith(
                                            `details.books.rows.${index}.`,
                                        ),
                                    )
                                    .flatMap(([, messages]) => messages)
                                    .join(' ')}
                        </FieldError>
                        <FormGrid>
                            <FormControl
                                hint={
                                    <>
                                        {profiles.error ||
                                            'Kosong berarti memakai metode utama pada Buku penyusutan. Server akan memeriksa masa manfaat, metode, frekuensi, dan tanggal berlakunya sebelum aset ditempatkan.'}
                                    </>
                                }
                                data-error-field={`details.books.rows.${index}.depreciation_profile_id`}
                                error={fieldError(
                                    `rows.${index}.depreciation_profile_id`,
                                )}
                            >
                                <RecordLookup
                                    source={profiles}
                                    label="Metode utama untuk group ini"
                                    items={profiles.options.map((item) => ({
                                        value: item.id,
                                        label: optionLabel(item),
                                    }))}
                                    value={row.depreciation_profile_id || ''}
                                    placeholder="Ikuti metode utama buku"
                                    searchPlaceholder="Cari metode penyusutan"

                                    ariaLabel="Pilih metode utama untuk group ini"
                                    portalContainer={containerRef}
                                    onValueChange={(item) => {
                                        const id = item ?? '';
                                        const nextProfile = profileFor({
                                            ...row,
                                            depreciation_profile_id: id,
                                        });
                                        const months = serviceLifeMonths(
                                            row.useful_life_periods,
                                            profile?.frequency,
                                        );
                                        const nextPeriod = monthsPerPeriod(
                                            nextProfile?.frequency,
                                        );
                                        patch(index, {
                                            depreciation_profile_id: id,
                                            useful_life_periods:
                                                months !== null && nextPeriod
                                                    ? String(
                                                          months / nextPeriod,
                                                      )
                                                    : '',
                                        });
                                    }}
                                />
                            </FormControl>
                            <FormControl
                                hint={
                                    <>
                                        Dipakai begitu saldo menurun
                                        menghasilkan angka lebih kecil daripada
                                        garis lurus sisa umur, agar aset tetap
                                        habis di akhir masa manfaat.
                                    </>
                                }
                                data-error-field={`details.books.rows.${index}.alternative_profile_id`}
                                error={fieldError(
                                    `rows.${index}.alternative_profile_id`,
                                )}
                            >
                                <RecordLookup
                                    source={profiles}
                                    label="Metode pengganti (opsional)"
                                    items={profiles.options.map((item) => ({
                                        value: item.id,
                                        label: optionLabel(item),
                                    }))}
                                    value={row.alternative_profile_id || ''}
                                    placeholder="Tanpa pengganti"
                                    searchPlaceholder="Cari metode pengganti"

                                    ariaLabel="Pilih metode pengganti"
                                    portalContainer={containerRef}
                                    onValueChange={(item) =>
                                        patch(index, {
                                            alternative_profile_id: item ?? '',
                                        })
                                    }
                                />
                            </FormControl>
                            <FormControl
                                data-error-field={`details.books.rows.${index}.useful_life_periods`}
                                error={fieldError(
                                    `rows.${index}.useful_life_periods`,
                                )}
                            >
                                <Field orientation="horizontal">
                                    <Switch
                                        id={`custom-life-${row.buku_id}`}
                                        disabled={!canEdit || !profile}
                                        checked={row.useful_life_periods !== ''}
                                        onCheckedChange={(checked) =>
                                            patch(index, {
                                                useful_life_periods: checked
                                                    ? String(
                                                          profile?.useful_life_periods ??
                                                              1,
                                                      )
                                                    : '',
                                            })
                                        }
                                    />
                                    <FieldLabel
                                        htmlFor={`custom-life-${row.buku_id}`}
                                    >
                                        Gunakan masa manfaat khusus
                                    </FieldLabel>
                                </Field>
                                <FieldDescription>
                                    {profile
                                        ? `Bawaan metode ${profile.nama}: ${inheritedLife ?? 'belum diisi'}.`
                                        : profiles.error ||
                                          'Pilih metode pada baris ini atau pada buku untuk melihat masa manfaat bawaan.'}
                                </FieldDescription>
                                {row.useful_life_periods !== '' && (
                                    <ServiceLifeFields
                                        value={row.useful_life_periods}
                                        frequency={profile?.frequency}
                                        readOnly={!canEdit}
                                        required
                                        onChange={(next) =>
                                            patch(index, {
                                                useful_life_periods:
                                                    String(next),
                                            })
                                        }
                                    />
                                )}
                            </FormControl>
                            <FormControl
                                hint={
                                    <>
                                        Dihitung dari tanggal aset mulai
                                        digunakan.
                                    </>
                                }
                                data-error-field={`details.books.rows.${index}.value`}
                                error={fieldError(`rows.${index}.value`)}
                            >
                                <Select
                                    label="Perlakuan periode pertama"
                                    items={CONVENTIONS.map(
                                        (item) => item.label,
                                    )}
                                    value={
                                        CONVENTIONS.find(
                                            (item) =>
                                                item.value === row.convention,
                                        )?.label
                                    }
                                    placeholder="Pilih perlakuan"
                                    searchPlaceholder="Cari perlakuan"
                                    emptyMessage="Perlakuan tidak ditemukan."
                                    ariaLabel="Pilih perlakuan periode pertama"
                                    portalContainer={containerRef}
                                    onValueChange={(item) =>
                                        patch(index, {
                                            convention:
                                                CONVENTIONS.find(
                                                    (c) => c.label === item,
                                                )?.value ?? '',
                                        })
                                    }
                                />
                            </FormControl>
                            <FormControl
                                hint={
                                    <>
                                        Kosong mengikuti Book. Periode terakhir
                                        tidak dibulatkan.
                                    </>
                                }
                                data-error-field={`details.books.rows.${index}.round_off_depreciation`}
                                error={fieldError(
                                    `rows.${index}.round_off_depreciation`,
                                )}
                            >
                                <Input
                                    id={`round-off-${row.buku_id}`}
                                    label="Kelipatan pembulatan penyusutan"
                                    type="number"
                                    min={0}
                                    step={0.01}
                                    disabled={!canEdit}
                                    value={row.round_off_depreciation}
                                    onChange={(event) =>
                                        patch(index, {
                                            round_off_depreciation:
                                                event.target.value,
                                        })
                                    }
                                    aria-invalid={
                                        !!fieldError(
                                            `rows.${index}.round_off_depreciation`,
                                        )
                                    }
                                />
                            </FormControl>
                            <Field orientation="horizontal">
                                <Switch
                                    id={`depreciate-${row.buku_id}`}
                                    disabled={!canEdit}
                                    checked={row.depreciate}
                                    onCheckedChange={(checked) =>
                                        patch(index, { depreciate: checked })
                                    }
                                />
                                <FieldLabel
                                    htmlFor={`depreciate-${row.buku_id}`}
                                >
                                    Hitung penyusutan
                                </FieldLabel>
                            </Field>
                        </FormGrid>
                    </div>
                );
            })}

            {canEdit && (
                <div className="sm:w-72">
                    <RecordLookup
                        source={bookSource}
                        filterKey={JSON.stringify(
                            rows.map((row) => row.buku_id).sort(),
                        )}
                        filterRecord={(book) =>
                            !rows.some((row) => row.buku_id === book.id)
                        }
                        items={unused.map((book) => ({
                            value: book.id,
                            label: `${book.kode} — ${book.nama}`,
                        }))}
                        placeholder="Pilih buku penyusutan"
                        searchPlaceholder="Cari buku"
                        emptyMessage="Buku tidak ditemukan."
                        ariaLabel="Pilih buku penyusutan"
                        portalContainer={containerRef}
                        onValueChange={(id) => {
                            if (id && !rows.some((row) => row.buku_id === id)) {
                                setRows([...rows, emptyRow(id)]);
                            }
                        }}
                    />
                </div>
            )}

            {error && <p className="text-destructive text-sm">{error}</p>}
            <FieldError>
                {draft?.error?.join(' ') ||
                    validationErrors?.['details.books.rows']?.join(' ')}
            </FieldError>
        </div>
    );
}
