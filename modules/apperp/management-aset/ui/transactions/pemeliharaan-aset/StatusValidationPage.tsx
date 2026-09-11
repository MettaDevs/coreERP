import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Select } from '@apperp/ui/select';
import { Switch } from '@apperp/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { api, errorMessage } from '../../api';

type Aturan = {
    id: string;
    status: string;
    aturan: string;
    aktif: boolean;
    keparahan: 'informasi' | 'peringatan' | 'error';
};

const STATUS: Record<string, string> = {
    dijadwalkan: 'Dijadwalkan',
    dikerjakan: 'Dikerjakan',
    selesai: 'Selesai',
    ditutup: 'Ditutup',
};

const ATURAN: Record<string, string> = {
    checklist_wajib: 'Pemeriksaan wajib sudah diisi',
    sebab_kerusakan: 'Sebab kerusakan sudah diisi',
    tindakan_perbaikan: 'Tindakan perbaikan sudah diisi',
};

const KEPARAHAN: { kode: Aturan['keparahan']; label: string }[] = [
    { kode: 'informasi', label: 'Informasi — hanya dicatat' },
    { kode: 'peringatan', label: 'Peringatan — boleh lanjut, tercatat' },
    { kode: 'error', label: 'Error — menahan perpindahan' },
];

export default function StatusValidationPage({
    permissions,
}: {
    permissions: string[];
}) {
    const canUpdate = permissions.includes(
        'management-aset.validasi-status-work-order.update',
    );
    const [aturan, setAturan] = useState<Aturan[]>([]);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    // Effect adalah satu-satunya pemilik pengambilan data. Pemuatan ulang setelah
    // simpan dinyatakan dengan menaikkan penanda ini, bukan dengan memanggil ulang
    // fungsi pemuat dari luar.
    const [versiMuat, setVersiMuat] = useState(0);

    useEffect(() => {
        let dilepas = false;

        // Pengambilan data lahir di dalam effect: state baru disetel setelah jawaban
        // server tiba, bukan pada commit render yang sama, dan jawaban yang telat
        // datang setelah layar ditutup dibuang lewat `dilepas`.
        const muat = async () => {
            try {
                const result = await api<{ data: Aturan[] }>(
                    '/validasi-status-work-order',
                );

                if (dilepas) {
                    return;
                }

                setAturan(result.data);
                setError('');
            } catch (caught) {
                if (dilepas) {
                    return;
                }

                setError(
                    errorMessage(caught, 'Aturan validasi belum dapat dimuat.'),
                );
            }
        };

        void muat();

        return () => {
            dilepas = true;
        };
    }, [versiMuat]);

    const ubah = (id: string, perubahan: Partial<Aturan>) =>
        setAturan((current) =>
            current.map((baris) =>
                baris.id === id ? { ...baris, ...perubahan } : baris,
            ),
        );

    const simpan = async () => {
        setSaving(true);

        try {
            await api('/validasi-status-work-order', {
                method: 'PUT',
                body: JSON.stringify({
                    aturan: aturan.map((baris) => ({
                        status: baris.status,
                        aturan: baris.aturan,
                        aktif: baris.aktif,
                        keparahan: baris.keparahan,
                    })),
                }),
            });
            setVersiMuat((versi) => versi + 1);
            toast.success('Aturan validasi tersimpan.');
        } catch (caught) {
            toast.error(
                errorMessage(caught, 'Aturan validasi belum dapat disimpan.'),
            );
        } finally {
            setSaving(false);
        }
    };

    // Dikelompokkan menurut status karena itulah cara aturan dibaca: "apa yang harus
    // terpenuhi sebelum pekerjaan boleh dinyatakan selesai", bukan sebaliknya.
    const perStatus = Object.keys(STATUS)
        .map((status) => ({
            status,
            baris: aturan.filter((item) => item.status === status),
        }))
        .filter((kelompok) => kelompok.baris.length > 0);

    return (
        <Card className="min-h-full rounded-none border-0 shadow-none">
            <CardHeader className="border-b px-5 py-3">
                <CardTitle>Validasi status work order</CardTitle>
                {canUpdate && (
                    <CardAction>
                        <Button disabled={saving} onClick={() => void simpan()}>
                            {saving ? 'Menyimpan…' : 'Simpan'}
                        </Button>
                    </CardAction>
                )}
            </CardHeader>
            <CardContent className="space-y-6 px-5 py-4">
                <p className="text-muted-foreground text-sm">
                    Aturan melekat pada status tujuan, sehingga pemeriksaan yang
                    sama dapat longgar saat pekerjaan dijadwalkan dan ketat saat
                    dinyatakan selesai.
                </p>
                {error && <p className="text-destructive text-sm">{error}</p>}
                {perStatus.length === 0 ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>Belum ada aturan validasi</EmptyTitle>
                            <EmptyDescription>
                                Aturan disiapkan saat tenant dikonfigurasi.
                                Hubungi admin bila daftar ini kosong.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    perStatus.map((kelompok) => (
                        <section key={kelompok.status} className="space-y-2">
                            <h3 className="font-semibold">
                                Sebelum berpindah ke {STATUS[kelompok.status]}
                            </h3>
                            <div className="overflow-x-auto rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                Yang diperiksa
                                            </TableHead>
                                            <TableHead className="w-32">
                                                Diperiksa
                                            </TableHead>
                                            <TableHead className="w-72">
                                                Bila belum terpenuhi
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {kelompok.baris.map((baris) => (
                                            <TableRow key={baris.id}>
                                                <TableCell>
                                                    {ATURAN[baris.aturan] ??
                                                        baris.aturan}
                                                </TableCell>
                                                <TableCell>
                                                    <Switch
                                                        aria-label={`Periksa ${ATURAN[baris.aturan]} saat ${STATUS[kelompok.status]}`}
                                                        checked={baris.aktif}
                                                        disabled={!canUpdate}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            ubah(baris.id, {
                                                                aktif: checked,
                                                            })
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    {!baris.aktif ? (
                                                        <span className="text-muted-foreground text-sm">
                                                            Tidak diperiksa
                                                        </span>
                                                    ) : canUpdate ? (
                                                        <Select
                                                            items={KEPARAHAN.map(
                                                                (item) =>
                                                                    item.label,
                                                            )}
                                                            value={
                                                                KEPARAHAN.find(
                                                                    (item) =>
                                                                        item.kode ===
                                                                        baris.keparahan,
                                                                )?.label ?? null
                                                            }
                                                            ariaLabel={`Keparahan ${ATURAN[baris.aturan]} saat ${STATUS[kelompok.status]}`}
                                                            onValueChange={(
                                                                value,
                                                            ) => {
                                                                const dipilih =
                                                                    KEPARAHAN.find(
                                                                        (
                                                                            item,
                                                                        ) =>
                                                                            item.label ===
                                                                            value,
                                                                    );

                                                                if (dipilih) {
                                                                    ubah(
                                                                        baris.id,
                                                                        {
                                                                            keparahan:
                                                                                dipilih.kode,
                                                                        },
                                                                    );
                                                                }
                                                            }}
                                                        />
                                                    ) : (
                                                        <span className="text-sm">
                                                            {KEPARAHAN.find(
                                                                (item) =>
                                                                    item.kode ===
                                                                    baris.keparahan,
                                                            )?.label ??
                                                                baris.keparahan}
                                                        </span>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </section>
                    ))
                )}
            </CardContent>
        </Card>
    );
}
