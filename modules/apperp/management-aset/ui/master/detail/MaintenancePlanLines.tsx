import { useEffect, useRef, useState } from 'react';
import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
import { DialogToolbar } from '@apperp/ui/dialog';
import { Empty, EmptyDescription } from '@apperp/ui/empty';

import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import RecordLookup from '../../_shared/RecordLookup';
import { api, errorMessage } from '../../api';
import type { ApiValidationErrors } from '../../api';
import type { MasterDetailsPayload } from '../DraftMasterDetails';
import type { MasterOption } from '../useMasterOptions';
import { optionLabel, useMasterOptions } from '../useMasterOptions';

type Basis = 'tanggal_mulai' | 'work_order_terakhir' | 'nilai_counter';

type Line = {
    id: string | null;
    dasar: Basis;
    interval: string;
    satuan_interval: string;
    jenis_counter_id: string;
    interval_counter: string;
    toleransi_counter: string;
    maintenance_job_type_id: string;
    trade_id: string;
    tipe_work_order_id: string;
    tingkat_layanan_id: string;
    selesai_dalam_hari: string;
    deskripsi: string;
    aktif: boolean;
    /** Nama dari server, hanya untuk tampilan baca. */
    job_type_nama?: string | null;
    jenis_counter_nama?: string | null;
    jenis_counter_satuan?: string | null;
    tipe_work_order_nama?: string | null;
};

type ServerLine = {
    id: string;
    dasar: Basis;
    interval: number | null;
    satuan_interval: string | null;
    jenis_counter_id: string | null;
    interval_counter: string | null;
    toleransi_counter: string | null;
    maintenance_job_type_id: string;
    trade_id: string | null;
    tipe_work_order_id: string;
    tingkat_layanan_id: string | null;
    selesai_dalam_hari: number | null;
    deskripsi: string | null;
    aktif: boolean;
    job_type_nama: string | null;
    jenis_counter_nama: string | null;
    jenis_counter_satuan: string | null;
    tipe_work_order_nama: string | null;
};

const BASIS: { value: Basis; label: string }[] = [
    { value: 'tanggal_mulai', label: 'Berulang dari tanggal mulai' },
    {
        value: 'work_order_terakhir',
        label: 'Berulang dari work order terakhir',
    },
    { value: 'nilai_counter', label: 'Berulang setiap nilai counter' },
];

const UNITS = [
    { value: 'hari', label: 'hari' },
    { value: 'minggu', label: 'minggu' },
    { value: 'bulan', label: 'bulan' },
    { value: 'tahun', label: 'tahun' },
];

const text = (value: string | number | null | undefined) =>
    value === null || value === undefined ? '' : String(value);

/** Angka desimal dari server ("500.00") ditampilkan tanpa nol di belakang koma. */
const number = (value: string | null | undefined) =>
    value === null || value === undefined || value === ''
        ? ''
        : String(Number(value));

function fromServer(line: ServerLine): Line {
    return {
        id: line.id,
        dasar: line.dasar,
        interval: text(line.interval),
        satuan_interval: line.satuan_interval ?? 'bulan',
        jenis_counter_id: line.jenis_counter_id ?? '',
        interval_counter: number(line.interval_counter),
        toleransi_counter: number(line.toleransi_counter),
        maintenance_job_type_id: line.maintenance_job_type_id,
        trade_id: line.trade_id ?? '',
        tipe_work_order_id: line.tipe_work_order_id,
        tingkat_layanan_id: line.tingkat_layanan_id ?? '',
        selesai_dalam_hari: text(line.selesai_dalam_hari),
        deskripsi: line.deskripsi ?? '',
        aktif: line.aktif,
        job_type_nama: line.job_type_nama,
        jenis_counter_nama: line.jenis_counter_nama,
        jenis_counter_satuan: line.jenis_counter_satuan,
        tipe_work_order_nama: line.tipe_work_order_nama,
    };
}

const emptyLine = (): Line => ({
    id: null,
    dasar: 'tanggal_mulai',
    interval: '1',
    satuan_interval: 'tahun',
    jenis_counter_id: '',
    interval_counter: '',
    toleransi_counter: '',
    maintenance_job_type_id: '',
    trade_id: '',
    tipe_work_order_id: '',
    tingkat_layanan_id: '',
    selesai_dalam_hari: '',
    deskripsi: '',
    aktif: true,
});

const blankToNull = (value: string) => (value.trim() === '' ? null : value);

function toPayload(line: Line) {
    const counter = line.dasar === 'nilai_counter';

    return {
        id: line.id,
        dasar: line.dasar,
        interval: counter ? null : Number(line.interval),
        satuan_interval: counter ? null : line.satuan_interval,
        jenis_counter_id: counter ? blankToNull(line.jenis_counter_id) : null,
        interval_counter: counter ? blankToNull(line.interval_counter) : null,
        toleransi_counter: counter ? blankToNull(line.toleransi_counter) : null,
        maintenance_job_type_id: line.maintenance_job_type_id,
        trade_id: blankToNull(line.trade_id),
        tipe_work_order_id: line.tipe_work_order_id,
        tingkat_layanan_id: blankToNull(line.tingkat_layanan_id),
        selesai_dalam_hari: blankToNull(line.selesai_dalam_hari),
        deskripsi: blankToNull(line.deskripsi),
        aktif: line.aktif,
    };
}

/** Ringkasan satu baris dalam kalimat, untuk mode baca. */
function describe(line: Line): string {
    if (line.dasar === 'nilai_counter') {
        const satuan = line.jenis_counter_satuan ?? '';

        return `Setiap ${line.interval_counter} ${satuan} ${line.jenis_counter_nama ?? ''}`.replace(
            /\s+/g,
            ' ',
        );
    }

    const basis =
        line.dasar === 'tanggal_mulai'
            ? 'dihitung dari tanggal mulai'
            : 'dihitung dari work order terakhir yang selesai';

    return `Setiap ${line.interval} ${line.satuan_interval}, ${basis}`;
}

const items = (options: MasterOption[]) =>
    options.map((option) => ({ value: option.id, label: optionLabel(option) }));

const optional = (options: MasterOption[], none: string) => [
    { value: '', label: none },
    ...items(options),
];

/**
 * Baris rencana pemeliharaan; padanan baris *Maintenance plan* F&O.
 *
 * Setiap baris disimpan dengan id-nya, karena usulan jadwal menunjuk baris itu: mengubah interval
 * memperbarui baris yang sama, bukan membuat baris baru yang akan mengusulkan ulang jatuh tempo
 * yang sudah diusulkan. Disajikan sebagai kartu, bukan tabel, karena isiannya bercabang menurut
 * dasar hitungnya.
 */
export default function MaintenancePlanLines({
    planId,
    canEdit,
    onChange,
    validationErrors,
}: {
    planId: string;
    canEdit: boolean;
    onChange: (details: MasterDetailsPayload) => void;
    validationErrors?: ApiValidationErrors;
}) {
    const portal = useRef<HTMLDivElement | null>(null);
    const [lines, setLines] = useState<Line[]>([]);
    const [loading, setLoading] = useState(true);
    const fieldError = (field: string) =>
        Object.entries(validationErrors ?? {})
            .filter(
                ([name]) =>
                    name === `details.lines.${field}` ||
                    name.startsWith(`details.lines.${field}.`),
            )
            .flatMap(([, messages]) => messages)
            .join(' ') || undefined;
    const [error, setError] = useState('');
    const jobTypes = useMasterOptions(
        canEdit ? 'maintenance-job-types' : null,
        '',
        lines.map((line) => line.maintenance_job_type_id).filter(Boolean),
    );
    const counters = useMasterOptions(
        canEdit ? 'jenis-counter' : null,
        '',
        lines.map((line) => line.jenis_counter_id).filter(Boolean),
    );
    const tipe = useMasterOptions(
        canEdit ? 'tipe-work-order' : null,
        '',
        lines.map((line) => line.tipe_work_order_id).filter(Boolean),
    );
    const trades = useMasterOptions(
        canEdit ? 'trade' : null,
        '',
        lines.map((line) => line.trade_id).filter(Boolean),
    );
    const layanan = useMasterOptions(
        canEdit ? 'tingkat-layanan' : null,
        '',
        lines.map((line) => line.tingkat_layanan_id).filter(Boolean),
    );

    useEffect(() => {
        let dilepas = false;

        api<{ data: ServerLine[] }>(`/rencana-pemeliharaan/${planId}/baris`)
            .then((result) => {
                if (!dilepas) {
                    setLines(result.data.map(fromServer));
                }
            })
            .catch((caught) => {
                if (!dilepas) {
                    setError(
                        errorMessage(
                            caught,
                            'Baris rencana belum dapat dimuat.',
                        ),
                    );
                }
            })
            .finally(() => {
                if (!dilepas) {
                    setLoading(false);
                }
            });

        return () => {
            dilepas = true;
        };
    }, [planId]);

    function changeLines(change: Line[] | ((current: Line[]) => Line[])) {
        const next = typeof change === 'function' ? change(lines) : change;
        setLines(next);
        onChange({ lines: { lines: next.map(toPayload) } });
    }

    function update(index: number, changes: Partial<Line>) {
        changeLines((current) =>
            current.map((line, position) =>
                position === index ? { ...line, ...changes } : line,
            ),
        );
    }

    if (loading) {
        return (
            <p className="text-muted-foreground text-sm">
                Memuat baris rencana…
            </p>
        );
    }

    if (!canEdit) {
        return lines.length === 0 ? (
            <Empty>
                <EmptyDescription>
                    Belum ada baris. Rencana tanpa baris tidak menghasilkan
                    jadwal.
                </EmptyDescription>
            </Empty>
        ) : (
            <ul className="divide-y rounded-md border">
                {lines.map((line, index) => (
                    <li
                        key={line.id ?? index}
                        className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-sm"
                    >
                        <span className="font-medium">
                            {line.job_type_nama}
                        </span>
                        <span className="text-muted-foreground">
                            {describe(line)}
                        </span>
                        <span className="text-muted-foreground">
                            · {line.tipe_work_order_nama}
                        </span>
                        {!line.aktif && (
                            <Badge variant="outline">Tidak aktif</Badge>
                        )}
                    </li>
                ))}
            </ul>
        );
    }

    return (
        <div
            ref={(node) => {
                portal.current =
                    node?.closest<HTMLDivElement>(
                        '[data-slot="page-dialog-contents"]',
                    ) ?? null;
            }}
            className="space-y-4"
        >
            <DialogToolbar className="px-0">
                <ActionButton
                    action="add"
                    type="button"
                    onClick={() => {
                        changeLines((current) => [...current, emptyLine()]);
                    }}
                />
            </DialogToolbar>
            {lines.length === 0 && (
                <Empty>
                    <EmptyDescription>
                        Belum ada baris. Tambahkan pekerjaan berkala, misalnya
                        kalibrasi setiap 1 tahun atau servis setiap 500 jam.
                    </EmptyDescription>
                </Empty>
            )}
            {lines.map((line, index) => (
                <div
                    key={line.id ?? `baru-${index}`}
                    className="space-y-4 rounded-md border p-4"
                >
                    <div className="flex items-center justify-between gap-2">
                        <h4 className="text-sm font-semibold">
                            Baris {index + 1}
                        </h4>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                changeLines((current) =>
                                    current.filter(
                                        (_, position) => position !== index,
                                    ),
                                );
                            }}
                        >
                            Keluarkan
                        </Button>
                    </div>
                    <FormGrid>
                        <FormControl
                            hint={
                                line.dasar === 'tanggal_mulai'
                                    ? 'Jatuh tempo pada tanggal mulai lalu setiap interval, tidak peduli kapan pekerjaan terakhir selesai.'
                                    : line.dasar === 'work_order_terakhir'
                                      ? 'Interval dihitung dari tanggal selesai work order terakhir untuk aset dan pekerjaan yang sama. Cocok untuk kalibrasi.'
                                      : 'Jatuh tempo setiap kelipatan interval dari total counter aset.'
                            }
                            data-error-field={`details.lines.lines.${index}.dasar`}
                            error={fieldError(`lines.${index}.dasar`)}
                        >
                            <Select
                                portalContainer={portal}
                                label="Dasar jatuh tempo"
                                items={BASIS}
                                value={line.dasar}
                                ariaLabel={`Dasar jatuh tempo baris ${index + 1}`}
                                onValueChange={(value) =>
                                    value &&
                                    update(index, { dasar: value as Basis })
                                }
                            />
                        </FormControl>
                        {line.dasar === 'nilai_counter' ? (
                            <FormControl
                                data-error-field={`details.lines.lines.${index}.jenis_counter_id`}
                                error={fieldError(
                                    `lines.${index}.jenis_counter_id`,
                                )}
                            >
                                <RecordLookup
                                    label="Jenis counter"
                                    source={counters}
                                    items={items(counters.options)}
                                    value={line.jenis_counter_id || null}
                                    placeholder="Pilih counter"
                                    ariaLabel={`Jenis counter baris ${index + 1}`}
                                    onValueChange={(value) =>
                                        update(index, {
                                            jenis_counter_id: value ?? '',
                                        })
                                    }
                                />
                            </FormControl>
                        ) : (
                            <>
                                <FormControl
                                    data-error-field={`details.lines.lines.${index}.interval`}
                                    error={fieldError(
                                        `lines.${index}.interval`,
                                    )}
                                >
                                    <Input
                                        label="Setiap"
                                        type="number"
                                        min="1"
                                        aria-label={`Interval baris ${index + 1}`}
                                        value={line.interval}
                                        onChange={(event) =>
                                            update(index, {
                                                interval: event.target.value,
                                            })
                                        }
                                        aria-invalid={
                                            !!fieldError(
                                                `lines.${index}.interval`,
                                            )
                                        }
                                    />
                                </FormControl>
                                <FormControl
                                    data-error-field={`details.lines.lines.${index}.satuan_interval`}
                                    error={fieldError(
                                        `lines.${index}.satuan_interval`,
                                    )}
                                >
                                    <Select
                                        portalContainer={portal}
                                        label="Satuan"
                                        items={UNITS}
                                        value={line.satuan_interval}
                                        ariaLabel={`Satuan interval baris ${index + 1}`}
                                        onValueChange={(value) =>
                                            value &&
                                            update(index, {
                                                satuan_interval: value,
                                            })
                                        }
                                    />
                                </FormControl>
                            </>
                        )}
                        {line.dasar === 'nilai_counter' && (
                            <>
                                <FormControl
                                    data-error-field={`details.lines.lines.${index}.interval_counter`}
                                    error={fieldError(
                                        `lines.${index}.interval_counter`,
                                    )}
                                >
                                    <Input
                                        label="Setiap (nilai counter)"
                                        type="number"
                                        min="0"
                                        step="any"
                                        value={line.interval_counter}
                                        onChange={(event) =>
                                            update(index, {
                                                interval_counter:
                                                    event.target.value,
                                            })
                                        }
                                        aria-invalid={
                                            !!fieldError(
                                                `lines.${index}.interval_counter`,
                                            )
                                        }
                                    />
                                </FormControl>
                                <FormControl
                                    hint={
                                        'Usulan muncul lebih awal, saat total counter kurang sebanyak ini dari batasnya.'
                                    }
                                    data-error-field={`details.lines.lines.${index}.toleransi_counter`}
                                    error={fieldError(
                                        `lines.${index}.toleransi_counter`,
                                    )}
                                >
                                    <Input
                                        label="Toleransi counter"
                                        type="number"
                                        min="0"
                                        step="any"
                                        value={line.toleransi_counter}
                                        onChange={(event) =>
                                            update(index, {
                                                toleransi_counter:
                                                    event.target.value,
                                            })
                                        }
                                        aria-invalid={
                                            !!fieldError(
                                                `lines.${index}.toleransi_counter`,
                                            )
                                        }
                                    />
                                </FormControl>
                            </>
                        )}
                        <FormControl
                            data-error-field={`details.lines.lines.${index}.maintenance_job_type_id`}
                            error={fieldError(
                                `lines.${index}.maintenance_job_type_id`,
                            )}
                        >
                            <RecordLookup
                                label="Jenis pekerjaan"
                                source={jobTypes}
                                items={items(jobTypes.options)}
                                value={line.maintenance_job_type_id || null}
                                placeholder="Pilih jenis pekerjaan"
                                ariaLabel={`Jenis pekerjaan baris ${index + 1}`}
                                onValueChange={(value) =>
                                    update(index, {
                                        maintenance_job_type_id: value ?? '',
                                    })
                                }
                            />
                        </FormControl>
                        <FormControl
                            data-error-field={`details.lines.lines.${index}.tipe_work_order_id`}
                            error={fieldError(
                                `lines.${index}.tipe_work_order_id`,
                            )}
                        >
                            <RecordLookup
                                label="Tipe work order"
                                source={tipe}
                                items={items(tipe.options)}
                                value={line.tipe_work_order_id || null}
                                placeholder="Pilih tipe work order"
                                ariaLabel={`Tipe work order baris ${index + 1}`}
                                onValueChange={(value) =>
                                    update(index, {
                                        tipe_work_order_id: value ?? '',
                                    })
                                }
                            />
                        </FormControl>
                        <FormControl
                            data-error-field={`details.lines.lines.${index}.trade_id`}
                            error={fieldError(`lines.${index}.trade_id`)}
                        >
                            <RecordLookup
                                label="Bidang keahlian"
                                source={trades}
                                items={optional(
                                    trades.options,
                                    'Tidak ditentukan',
                                )}
                                value={line.trade_id || null}
                                placeholder="Tidak ditentukan"
                                ariaLabel={`Bidang keahlian baris ${index + 1}`}
                                onValueChange={(value) =>
                                    update(index, { trade_id: value ?? '' })
                                }
                            />
                        </FormControl>
                        <FormControl
                            data-error-field={`details.lines.lines.${index}.tingkat_layanan_id`}
                            error={fieldError(
                                `lines.${index}.tingkat_layanan_id`,
                            )}
                        >
                            <RecordLookup
                                label="Tingkat layanan"
                                source={layanan}
                                items={optional(
                                    layanan.options,
                                    'Tidak ditentukan',
                                )}
                                value={line.tingkat_layanan_id || null}
                                placeholder="Tidak ditentukan"
                                ariaLabel={`Tingkat layanan baris ${index + 1}`}
                                onValueChange={(value) =>
                                    update(index, {
                                        tingkat_layanan_id: value ?? '',
                                    })
                                }
                            />
                        </FormControl>
                        <FormControl
                            hint={
                                'Target selesai work order dihitung dari tanggal jatuh tempo ditambah angka ini.'
                            }
                            data-error-field={`details.lines.lines.${index}.selesai_dalam_hari`}
                            error={fieldError(
                                `lines.${index}.selesai_dalam_hari`,
                            )}
                        >
                            <Input
                                label="Selesai dalam (hari)"
                                type="number"
                                min="0"
                                value={line.selesai_dalam_hari}
                                onChange={(event) =>
                                    update(index, {
                                        selesai_dalam_hari: event.target.value,
                                    })
                                }
                                aria-invalid={
                                    !!fieldError(
                                        `lines.${index}.selesai_dalam_hari`,
                                    )
                                }
                            />
                        </FormControl>
                        <FormControl
                            data-error-field={`details.lines.lines.${index}.deskripsi`}
                            error={fieldError(`lines.${index}.deskripsi`)}
                        >
                            <Input
                                label="Keterangan work order"
                                value={line.deskripsi}
                                maxLength={2000}
                                onChange={(event) =>
                                    update(index, {
                                        deskripsi: event.target.value,
                                    })
                                }
                                aria-invalid={
                                    !!fieldError(`lines.${index}.deskripsi`)
                                }
                            />
                        </FormControl>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={line.aktif}
                                onCheckedChange={(checked) =>
                                    update(index, { aktif: checked === true })
                                }
                            />
                            Baris aktif dan ikut dihitung
                        </label>
                    </FormGrid>
                </div>
            ))}

            {[jobTypes, counters, tipe].map(
                (source) =>
                    source.error && (
                        <p
                            key={source.error}
                            className="text-muted-foreground text-sm"
                        >
                            {source.error}
                        </p>
                    ),
            )}
            {error && <p className="text-destructive text-sm">{error}</p>}
        </div>
    );
}
