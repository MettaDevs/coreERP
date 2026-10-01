import { CircleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
import { Empty, EmptyDescription } from '@apperp/ui/empty';
import { Field, FieldHint, FieldLabel } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { api, errorMessage } from '../../api';
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

/** Ikon bantuan di sebelah kontrol, pola yang sama dengan `DynamicField`. */
function hinted(control: ReactNode, help: string) {
    return (
        <div className="flex items-center gap-1.5">
            <div className="min-w-0 flex-1">{control}</div>
            <FieldHint hint={help}>
                <button
                    type="button"
                    aria-label="Lihat penjelasan"
                    className="text-muted-foreground hover:text-foreground shrink-0"
                >
                    <CircleAlert className="size-4" />
                </button>
            </FieldHint>
        </div>
    );
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
    version,
    onVersionChange,
}: {
    planId: string;
    canEdit: boolean;
    /** Versi record pemilik; penyimpanan rincian ini mengklaimnya. */
    version: number;
    onVersionChange: (version: number) => void;
}) {
    const [lines, setLines] = useState<Line[]>([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);
    const jobTypes = useMasterOptions(canEdit ? 'maintenance-job-types' : null);
    const counters = useMasterOptions(canEdit ? 'jenis-counter' : null);
    const tipe = useMasterOptions(canEdit ? 'tipe-work-order' : null);
    const trades = useMasterOptions(canEdit ? 'trade' : null);
    const layanan = useMasterOptions(canEdit ? 'tingkat-layanan' : null);

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

    function update(index: number, changes: Partial<Line>) {
        setSaved(false);
        setLines((current) =>
            current.map((line, position) =>
                position === index ? { ...line, ...changes } : line,
            ),
        );
    }

    async function save() {
        setSaving(true);
        setError('');

        try {
            const result = await api<{ data: ServerLine[]; version: number }>(
                `/rencana-pemeliharaan/${planId}/baris`,
                {
                    method: 'PUT',
                    body: JSON.stringify({
                        version,
                        lines: lines.map(toPayload),
                    }),
                },
            );
            setLines(result.data.map(fromServer));
            onVersionChange(result.version);
            setSaved(true);
        } catch (caught) {
            setError(
                errorMessage(caught, 'Baris rencana belum dapat disimpan.'),
            );
        } finally {
            setSaving(false);
        }
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
        <div className="space-y-4">
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
                                setSaved(false);
                                setLines((current) =>
                                    current.filter(
                                        (_, position) => position !== index,
                                    ),
                                );
                            }}
                        >
                            Keluarkan
                        </Button>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field>
                            <FieldLabel>Dasar jatuh tempo</FieldLabel>
                            {hinted(
                                <Select
                                    items={BASIS}
                                    value={line.dasar}
                                    ariaLabel={`Dasar jatuh tempo baris ${index + 1}`}
                                    onValueChange={(value) =>
                                        value &&
                                        update(index, { dasar: value as Basis })
                                    }
                                />,
                                line.dasar === 'tanggal_mulai'
                                    ? 'Jatuh tempo pada tanggal mulai lalu setiap interval, tidak peduli kapan pekerjaan terakhir selesai.'
                                    : line.dasar === 'work_order_terakhir'
                                      ? 'Interval dihitung dari tanggal selesai work order terakhir untuk aset dan pekerjaan yang sama. Cocok untuk kalibrasi.'
                                      : 'Jatuh tempo setiap kelipatan interval dari total counter aset.',
                            )}
                        </Field>
                        {line.dasar === 'nilai_counter' ? (
                            <Field>
                                <FieldLabel>Jenis counter</FieldLabel>
                                <Select
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
                            </Field>
                        ) : (
                            <div className="grid grid-cols-[1fr_1fr] gap-2">
                                <Field>
                                    <FieldLabel>Setiap</FieldLabel>
                                    <Input
                                        type="number"
                                        min="1"
                                        aria-label={`Interval baris ${index + 1}`}
                                        value={line.interval}
                                        onChange={(event) =>
                                            update(index, {
                                                interval: event.target.value,
                                            })
                                        }
                                    />
                                </Field>
                                <Field>
                                    <FieldLabel>Satuan</FieldLabel>
                                    <Select
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
                                </Field>
                            </div>
                        )}
                        {line.dasar === 'nilai_counter' && (
                            <div className="grid grid-cols-[1fr_1fr] gap-2 sm:col-span-2">
                                <Field>
                                    <FieldLabel>
                                        Setiap (nilai counter)
                                    </FieldLabel>
                                    <Input
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
                                    />
                                </Field>
                                <Field>
                                    <FieldLabel>Toleransi counter</FieldLabel>
                                    {hinted(
                                        <Input
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
                                        />,
                                        'Usulan muncul lebih awal, saat total counter kurang sebanyak ini dari batasnya.',
                                    )}
                                </Field>
                            </div>
                        )}
                        <Field>
                            <FieldLabel>Jenis pekerjaan</FieldLabel>
                            <Select
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
                        </Field>
                        <Field>
                            <FieldLabel>Tipe work order</FieldLabel>
                            <Select
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
                        </Field>
                        <Field>
                            <FieldLabel>Bidang keahlian</FieldLabel>
                            <Select
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
                        </Field>
                        <Field>
                            <FieldLabel>Tingkat layanan</FieldLabel>
                            <Select
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
                        </Field>
                        <Field>
                            <FieldLabel>Selesai dalam (hari)</FieldLabel>
                            {hinted(
                                <Input
                                    type="number"
                                    min="0"
                                    value={line.selesai_dalam_hari}
                                    onChange={(event) =>
                                        update(index, {
                                            selesai_dalam_hari:
                                                event.target.value,
                                        })
                                    }
                                />,
                                'Target selesai work order dihitung dari tanggal jatuh tempo ditambah angka ini.',
                            )}
                        </Field>
                        <Field>
                            <FieldLabel>Keterangan work order</FieldLabel>
                            <Input
                                value={line.deskripsi}
                                maxLength={2000}
                                onChange={(event) =>
                                    update(index, {
                                        deskripsi: event.target.value,
                                    })
                                }
                            />
                        </Field>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={line.aktif}
                                onCheckedChange={(checked) =>
                                    update(index, { aktif: checked === true })
                                }
                            />
                            Baris aktif dan ikut dihitung
                        </label>
                    </div>
                </div>
            ))}
            <div className="flex gap-2">
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => {
                        setSaved(false);
                        setLines((current) => [...current, emptyLine()]);
                    }}
                >
                    Tambah baris
                </Button>
                <Button
                    type="button"
                    disabled={saving}
                    onClick={() => void save()}
                >
                    {saving ? 'Menyimpan…' : 'Simpan baris rencana'}
                </Button>
            </div>
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
            {saved && (
                <p className="text-muted-foreground text-sm">
                    Baris rencana tersimpan. Hitung ulang jadwal untuk melihat
                    akibatnya.
                </p>
            )}
            {error && <p className="text-destructive text-sm">{error}</p>}
        </div>
    );
}
