import { useEffect, useState } from 'react';
import { Button } from '@apperp/ui/button';
import { Empty, EmptyDescription } from '@apperp/ui/empty';
import { Input } from '@apperp/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { TransferList, type TransferListItem } from '@apperp/ui/transfer-list';
import { api, errorMessage, newIdempotencyKey } from '../../api';

type Variant = {
    id: string;
    kode: string;
    nama: string;
    keterangan: string | null;
    aktif: boolean;
};
type Choice = { id: string; kode: string; nama: string };

function choiceItem(item: Choice): TransferListItem {
    return { id: item.id, label: item.nama, description: item.kode };
}

export default function MaintenanceJobTypeDetails({
    jobTypeId,
    canEdit,
}: {
    jobTypeId: string;
    canEdit: boolean;
}) {
    const [variants, setVariants] = useState<Variant[]>([]);
    const [remaining, setRemaining] = useState<TransferListItem[]>([]);
    const [selected, setSelected] = useState<TransferListItem[]>([]);
    const [variantName, setVariantName] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);

    async function load() {
        setError('');
        try {
            const [variantResult, assetTypeResult] = await Promise.all([
                api<{ data: Variant[] }>(
                    `/maintenance-job-types/${jobTypeId}/variants`,
                ),
                api<{ data: { remaining: Choice[]; selected: Choice[] } }>(
                    `/maintenance-job-types/${jobTypeId}/asset-types`,
                ),
            ]);
            setVariants(variantResult.data);
            setRemaining(assetTypeResult.data.remaining.map(choiceItem));
            setSelected(assetTypeResult.data.selected.map(choiceItem));
        } catch (caught) {
            setError(
                errorMessage(caught, 'Rincian maintenance belum dapat dimuat.'),
            );
        }
    }

    useEffect(() => {
        void load();
    }, [jobTypeId]);

    async function addVariant() {
        if (!variantName.trim()) return;
        setBusy(true);
        setError('');
        try {
            await api('/maintenance-job-type-variants', {
                method: 'POST',
                headers: { 'Idempotency-Key': newIdempotencyKey() },
                body: JSON.stringify({
                    maintenance_job_type_id: jobTypeId,
                    nama: variantName.trim(),
                }),
            });
            setVariantName('');
            await load();
        } catch (caught) {
            setError(errorMessage(caught, 'Varian belum dapat ditambahkan.'));
        } finally {
            setBusy(false);
        }
    }

    async function removeVariant(id: string) {
        if (!window.confirm('Arsipkan varian ini?')) return;
        try {
            await api(`/maintenance-job-type-variants/${id}`, {
                method: 'DELETE',
            });
            await load();
        } catch (caught) {
            setError(errorMessage(caught, 'Varian belum dapat diarsipkan.'));
        }
    }

    async function saveAssetTypes() {
        setBusy(true);
        setError('');
        try {
            await api(`/maintenance-job-types/${jobTypeId}/asset-types`, {
                method: 'PUT',
                body: JSON.stringify({
                    jenis_aset_ids: selected.map((item) => item.id),
                }),
            });
            setSaved(true);
        } catch (caught) {
            setError(
                errorMessage(caught, 'Relasi jenis aset belum dapat disimpan.'),
            );
        } finally {
            setBusy(false);
        }
    }

    if (
        error &&
        variants.length === 0 &&
        remaining.length === 0 &&
        selected.length === 0
    ) {
        return <p className="text-destructive text-sm">{error}</p>;
    }

    return (
        <div className="space-y-7">
            <section className="space-y-3">
                <div className="flex items-center justify-between">
                    <h3 className="font-semibold">
                        Varian jenis pekerjaan maintenance
                    </h3>
                    {canEdit && (
                        <div className="flex gap-2">
                            <Input
                                aria-label="Nama varian baru"
                                placeholder="Nama varian"
                                value={variantName}
                                onChange={(event) =>
                                    setVariantName(event.target.value)
                                }
                            />
                            <Button
                                type="button"
                                disabled={busy}
                                onClick={() => void addVariant()}
                            >
                                Tambah
                            </Button>
                        </div>
                    )}
                </div>
                {variants.length === 0 ? (
                    <Empty>
                        <EmptyDescription>
                            Belum ada varian. Tambahkan interval seperti
                            mingguan atau tahunan.
                        </EmptyDescription>
                    </Empty>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Kode</TableHead>
                                    <TableHead>Nama</TableHead>
                                    {canEdit && (
                                        <TableHead className="w-28">
                                            Aksi
                                        </TableHead>
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {variants.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell>{item.kode}</TableCell>
                                        <TableCell>{item.nama}</TableCell>
                                        {canEdit && (
                                            <TableCell>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        void removeVariant(
                                                            item.id,
                                                        )
                                                    }
                                                >
                                                    Arsipkan
                                                </Button>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </section>

            <section className="space-y-3">
                <h3 className="font-semibold">
                    Jenis aset yang menggunakan pekerjaan ini
                </h3>
                <p className="text-muted-foreground text-sm">
                    Pilih jenis aset yang boleh memakai job type ini saat
                    maintenance dibuat.
                </p>
                <TransferList
                    remaining={remaining}
                    selected={selected}
                    onChange={(next) => {
                        setRemaining(next.remaining);
                        setSelected(next.selected);
                        setSaved(false);
                    }}
                    remainingTitle="Jenis aset tersedia"
                    selectedTitle="Jenis aset terpilih"
                    disabled={!canEdit || busy}
                    remainingEmptyLabel="Semua jenis aset sudah terpilih."
                    selectedEmptyLabel="Belum ada jenis aset yang dipilih."
                />
                {canEdit && (
                    <Button
                        type="button"
                        disabled={busy}
                        onClick={() => void saveAssetTypes()}
                    >
                        {busy ? 'Menyimpan…' : 'Simpan relasi jenis aset'}
                    </Button>
                )}
            </section>
            {saved && (
                <p className="text-muted-foreground text-sm">
                    Perubahan maintenance tersimpan.
                </p>
            )}
            {error && <p className="text-destructive text-sm">{error}</p>}
        </div>
    );
}
