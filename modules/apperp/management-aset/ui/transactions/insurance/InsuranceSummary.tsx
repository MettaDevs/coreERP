import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { useToday } from '@/hooks/use-work-date';
import { Badge } from '@apperp/ui/badge';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { money } from '../../_shared/format';
import { api, errorMessage } from '../../api';
import type { InsuranceStatus } from './insurance';
import { STATUS_LABELS } from './insurance';

type Row = {
    id: string;
    kode: string;
    nama: string;
    jenis_aset_nama: string | null;
    lokasi_aset_nama: string | null;
    nilai_perolehan: string;
    nilai_buku: string | null;
    total_nilai_tertanggung: string;
    kekurangan: string;
    status: InsuranceStatus;
};

const FILTERS = [
    { value: 'bermasalah', label: 'Belum atau kurang diasuransikan' },
    { value: 'tidak_diasuransikan', label: 'Belum diasuransikan' },
    { value: 'kurang_diasuransikan', label: 'Kurang diasuransikan' },
    { value: 'semua', label: 'Semua aset' },
];

/**
 * Aset yang belum atau kurang diasuransikan pada satu tanggal; padanan laporan *Insurance - Uninsured
 * FAs* Business Central. Kurang berarti nilai yang ditanggung lebih kecil dari nilai perolehan.
 */
export default function InsuranceSummary() {
    const today = useToday();
    const [date, setDate] = useState('');
    const [status, setStatus] = useState('bermasalah');
    const [loaded, setLoaded] = useState<{
        key: string;
        rows: Row[];
        cut: boolean;
    } | null>(null);
    const key = `?status=${status}${date ? `&tanggal=${date}` : ''}`;

    useEffect(() => {
        let cancelled = false;
        api<{ data: Row[]; meta: { terpotong: boolean } }>(
            `/asuransi-aset/ringkasan${key}`,
        )
            .then(
                (result) =>
                    !cancelled &&
                    setLoaded({
                        key,
                        rows: result.data,
                        cut: result.meta.terpotong,
                    }),
            )
            .catch(
                (caught) =>
                    !cancelled &&
                    toast.error(
                        errorMessage(
                            caught,
                            'Ringkasan asuransi belum dapat dimuat.',
                        ),
                    ),
            );

        return () => {
            cancelled = true;
        };
    }, [key]);

    const current = loaded && loaded.key === key ? loaded : null;
    const columns: DataTableColumn<Row>[] = [
        {
            id: 'aset',
            header: 'Aset',
            cell: (row) => `${row.kode} · ${row.nama}`,
            sortValue: (row) => row.kode,
            minWidth: 200,
        },
        {
            id: 'jenis',
            header: 'Jenis aset',
            cell: (row) => row.jenis_aset_nama ?? '—',
            width: 160,
        },
        {
            id: 'lokasi',
            header: 'Lokasi',
            cell: (row) => row.lokasi_aset_nama ?? '—',
            width: 160,
        },
        {
            id: 'perolehan',
            header: 'Nilai perolehan',
            cell: (row) => money(row.nilai_perolehan),
            align: 'right',
            width: 150,
        },
        {
            id: 'buku',
            header: 'Nilai buku',
            cell: (row) => money(row.nilai_buku),
            align: 'right',
            width: 150,
        },
        {
            id: 'ditanggung',
            header: 'Ditanggung',
            cell: (row) => money(row.total_nilai_tertanggung),
            align: 'right',
            width: 150,
        },
        {
            id: 'kurang',
            header: 'Kekurangan',
            cell: (row) => money(row.kekurangan),
            sortValue: (row) => Number(row.kekurangan),
            align: 'right',
            width: 150,
        },
        {
            id: 'status',
            header: 'Status',
            cell: (row) => (
                <Badge
                    variant={
                        row.status === 'cukup' ? 'secondary' : 'destructive'
                    }
                >
                    {STATUS_LABELS[row.status]}
                </Badge>
            ),
            width: 170,
        },
    ];

    return (
        <div>
            <div className="flex flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:flex-wrap sm:items-end">
                <div className="w-full sm:w-72">
                    <Select
                        label="Tampilkan"
                        items={FILTERS}
                        value={status}
                        ariaLabel="Saring status asuransi"
                        onValueChange={(value) =>
                            setStatus(value ?? 'bermasalah')
                        }
                    />
                </div>
                <div className="w-full sm:w-48">
                    <Input
                        label="Per tanggal"
                        type="date"
                        value={date || today}
                        onChange={(event) => setDate(event.target.value)}
                    />
                </div>
                {current?.cut && (
                    <p className="text-muted-foreground text-sm">
                        Hanya 2.000 aset pertama yang ditampilkan.
                    </p>
                )}
            </div>
            {current && current.rows.length === 0 ? (
                <Empty>
                    <EmptyHeader>
                        <EmptyTitle>Tidak ada aset pada pilihan ini</EmptyTitle>
                        <EmptyDescription>
                            Seluruh aset yang masih dipakai sudah ditanggung
                            sekurang-kurangnya senilai nilai perolehannya.
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <DataTable
                    columns={columns}
                    data={current?.rows ?? []}
                    getRowKey={(row) => row.id}
                    getRowLabel={(row) => row.kode}
                />
            )}
        </div>
    );
}
