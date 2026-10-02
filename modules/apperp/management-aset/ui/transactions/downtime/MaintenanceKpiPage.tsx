import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { useToday } from '@/hooks/use-work-date';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Input } from '@apperp/ui/input';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import { api, errorMessage } from '../../api';
import { optionLabel, useMasterOptions } from '../../master/useMasterOptions';
import type { KpiFigures, KpiRow } from './downtime';

const GROUPINGS = [
    { value: 'aset', label: 'Per aset' },
    { value: 'jenis', label: 'Per jenis aset' },
    { value: 'lokasi', label: 'Per lokasi' },
];

const hours = (value: number) =>
    value.toLocaleString('id-ID', { maximumFractionDigits: 2 });

/**
 * KPI pemeliharaan; padanan *Asset KPIs* Dynamics 365 F&O dan *Maintenance Analysis* Business Central.
 *
 * Angka dihitung saat dibuka dari downtime dan work order yang selesai dalam periode. Rumusnya ada di
 * halaman fitur Downtime dan KPI pemeliharaan.
 */
export default function MaintenanceKpiPage() {
    const today = useToday();
    const [from, setFrom] = useState(() => `${today.slice(0, 7)}-01`);
    const [until, setUntil] = useState(today);
    const [grouping, setGrouping] = useState('aset');
    const [type, setType] = useState('');
    const [location, setLocation] = useState('');
    const types = useMasterOptions('jenis-aset');
    const locations = useMasterOptions('lokasi-aset');
    const [loaded, setLoaded] = useState<{
        key: string;
        rows: KpiRow[];
        total: KpiFigures;
    } | null>(null);
    const key = new URLSearchParams(
        Object.entries({
            dari: from,
            sampai: until,
            kelompok: grouping,
            jenis_aset_id: type,
            lokasi_aset_id: location,
        }).filter(([, value]) => Boolean(value)),
    ).toString();

    useEffect(() => {
        let cancelled = false;
        api<{ data: KpiRow[]; meta: { total: KpiFigures } }>(
            `/kpi-pemeliharaan?${key}`,
        )
            .then(
                (result) =>
                    !cancelled &&
                    setLoaded({
                        key,
                        rows: result.data,
                        total: result.meta.total,
                    }),
            )
            .catch(
                (caught) =>
                    !cancelled &&
                    toast.error(
                        errorMessage(caught, 'KPI belum dapat dihitung.'),
                    ),
            );

        return () => {
            cancelled = true;
        };
    }, [key]);

    const current = loaded && loaded.key === key ? loaded : null;
    const columns: DataTableColumn<KpiRow>[] = [
        {
            id: 'nama',
            header:
                grouping === 'aset'
                    ? 'Aset'
                    : grouping === 'jenis'
                      ? 'Jenis aset'
                      : 'Lokasi',
            cell: (row) =>
                row.kunci === null
                    ? 'Tanpa lokasi'
                    : [row.kode, row.nama].filter(Boolean).join(' · '),
            sortValue: (row) => row.kode ?? '',
            minWidth: 200,
        },
        ...(grouping === 'aset'
            ? []
            : [
                  {
                      id: 'jumlah',
                      header: 'Aset',
                      cell: (row: KpiRow) => row.jumlah_aset,
                      align: 'right' as const,
                      width: 80,
                  },
              ]),
        {
            id: 'availability',
            header: 'Availability',
            cell: (row) =>
                row.availability_persen === null
                    ? '—'
                    : `${hours(row.availability_persen)}%`,
            sortValue: (row) => row.availability_persen ?? -1,
            align: 'right',
            width: 120,
        },
        {
            id: 'downtime',
            header: 'Downtime (jam)',
            cell: (row) => hours(row.downtime_jam),
            sortValue: (row) => row.downtime_jam,
            align: 'right',
            width: 130,
        },
        {
            id: 'henti',
            header: 'Henti',
            cell: (row) => row.jumlah_henti,
            align: 'right',
            width: 80,
        },
        {
            id: 'kerusakan',
            header: 'Kerusakan',
            cell: (row) => row.jumlah_kerusakan,
            sortValue: (row) => row.jumlah_kerusakan,
            align: 'right',
            width: 110,
        },
        {
            id: 'mtbf',
            header: 'MTBF (jam)',
            cell: (row) => hours(row.mtbf_jam),
            sortValue: (row) => row.mtbf_jam,
            align: 'right',
            width: 120,
        },
        {
            id: 'mttr',
            header: 'MTTR (jam)',
            cell: (row) => hours(row.mttr_jam),
            sortValue: (row) => row.mttr_jam,
            align: 'right',
            width: 120,
        },
        {
            id: 'wo',
            header: 'Work order selesai',
            cell: (row) => row.wo_selesai,
            align: 'right',
            width: 150,
        },
    ];

    return (
        <div>
            <RecordActionBar title="KPI pemeliharaan" />
            <div className="flex flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:flex-wrap sm:items-end">
                <div className="w-full sm:w-44">
                    <Input
                        label="Dari"
                        type="date"
                        value={from}
                        onChange={(event) => setFrom(event.target.value)}
                    />
                </div>
                <div className="w-full sm:w-44">
                    <Input
                        label="Sampai"
                        type="date"
                        value={until}
                        onChange={(event) => setUntil(event.target.value)}
                    />
                </div>
                <div className="w-full sm:w-48">
                    <Select
                        label="Kelompokkan"
                        items={GROUPINGS}
                        value={grouping}
                        ariaLabel="Kelompokkan KPI"
                        onValueChange={(value) => setGrouping(value ?? 'aset')}
                    />
                </div>
                <div className="w-full sm:w-56">
                    <Select
                        label="Jenis aset"
                        items={[
                            { value: '', label: 'Semua jenis' },
                            ...types.options.map((option) => ({
                                value: option.id,
                                label: optionLabel(option),
                            })),
                        ]}
                        value={type || null}
                        placeholder="Semua jenis"
                        ariaLabel="Saring jenis aset"
                        onValueChange={(value) => setType(value ?? '')}
                    />
                </div>
                <div className="w-full sm:w-56">
                    <Select
                        label="Lokasi"
                        items={[
                            { value: '', label: 'Semua lokasi' },
                            ...locations.options.map((option) => ({
                                value: option.id,
                                label: optionLabel(option),
                            })),
                        ]}
                        value={location || null}
                        placeholder="Semua lokasi"
                        ariaLabel="Saring lokasi"
                        onValueChange={(value) => setLocation(value ?? '')}
                    />
                </div>
            </div>

            {current && (
                <dl className="grid gap-3 border-b px-5 py-3 sm:grid-cols-5">
                    {[
                        [
                            'Availability',
                            current.total.availability_persen === null
                                ? '—'
                                : `${hours(current.total.availability_persen)}%`,
                        ],
                        [
                            'Downtime',
                            `${hours(current.total.downtime_jam)} jam`,
                        ],
                        ['Kerusakan', String(current.total.jumlah_kerusakan)],
                        ['MTBF', `${hours(current.total.mtbf_jam)} jam`],
                        ['MTTR', `${hours(current.total.mttr_jam)} jam`],
                    ].map(([label, value]) => (
                        <div key={label}>
                            <dt className="text-muted-foreground text-xs">
                                {label}
                            </dt>
                            <dd className="text-lg font-semibold">{value}</dd>
                        </div>
                    ))}
                </dl>
            )}

            {current && current.rows.length === 0 ? (
                <Empty>
                    <EmptyHeader>
                        <EmptyTitle>Tidak ada aset pada pilihan ini</EmptyTitle>
                        <EmptyDescription>
                            Ubah periode atau saringan untuk melihat kinerja
                            pemeliharaan aset.
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <DataTable
                    columns={columns}
                    data={current?.rows ?? []}
                    getRowKey={(row) => row.kunci ?? 'tanpa'}
                    getRowLabel={(row) => row.kode ?? 'Tanpa lokasi'}
                />
            )}
        </div>
    );
}
