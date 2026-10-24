import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import {
    Dialog,
    DialogAction,
    DialogCancel,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import { Input } from '@apperp/ui/input';
import { Label } from '@apperp/ui/label';
import { Textarea } from '@apperp/ui/textarea';
import { api, toastSaveError } from '../../api';

export type ItemJasaRecord = {
    id: string;
    kode_upah: string;
    nama_upah: string;
    satuan: string;
    keterangan: string | null;
    aktif: boolean;
    created_at?: string;
    updated_at?: string;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    editingItem: ItemJasaRecord | null;
    onSuccess: (saved: ItemJasaRecord, isNew: boolean) => void;
};

export function ItemJasaModal({
    open,
    onOpenChange,
    editingItem,
    onSuccess,
}: Props) {
    const [kodeUpah, setKodeUpah] = useState('');
    const [namaUpah, setNamaUpah] = useState('');
    const [satuan, setSatuan] = useState('');
    const [keterangan, setKeterangan] = useState('');
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const timer = setTimeout(() => {
            if (editingItem) {
                setKodeUpah(editingItem.kode_upah);
                setNamaUpah(editingItem.nama_upah);
                setSatuan(editingItem.satuan);
                setKeterangan(editingItem.keterangan || '');
            } else {
                setKodeUpah('');
                setNamaUpah('');
                setSatuan('');
                setKeterangan('');
            }
        }, 0);

        return () => clearTimeout(timer);
    }, [editingItem, open]);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        if (!kodeUpah.trim()) {
            toast.error('Kode upah wajib diisi.');

            return;
        }

        if (!namaUpah.trim()) {
            toast.error('Nama upah wajib diisi.');

            return;
        }

        if (!satuan.trim()) {
            toast.error('Satuan wajib diisi.');

            return;
        }

        setSaving(true);

        try {
            if (editingItem) {
                const res = await api<{
                    status: string;
                    message: string;
                    data: ItemJasaRecord;
                }>(`/item-jasa/${editingItem.id}`, {
                    method: 'PUT',
                    body: JSON.stringify({
                        kode_upah: kodeUpah.trim(),
                        nama_upah: namaUpah.trim(),
                        satuan: satuan.trim(),
                        keterangan: keterangan.trim() || null,
                    }),
                });

                toast.success('Item upah berhasil diperbarui.');
                onSuccess(res.data, false);
            } else {
                const res = await api<{
                    status: string;
                    message: string;
                    data: ItemJasaRecord;
                }>(`/item-jasa`, {
                    method: 'POST',
                    body: JSON.stringify({
                        kode_upah: kodeUpah.trim(),
                        nama_upah: namaUpah.trim(),
                        satuan: satuan.trim(),
                        keterangan: keterangan.trim() || null,
                        aktif: true,
                    }),
                });

                toast.success('Item upah berhasil ditambahkan.');
                onSuccess(res.data, true);
            }

            onOpenChange(false);
        } catch (err) {
            toastSaveError(err, 'Gagal menyimpan item upah.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent size="compact">
                <form onSubmit={handleSubmit} className="flex flex-col">
                    <DialogHeader>
                        <DialogTitle>
                            {editingItem
                                ? 'Ubah Item Upah'
                                : 'Tambah Item Upah'}
                        </DialogTitle>
                        <DialogDescription>
                            Isi kode, nama upah, satuan, dan keterangan untuk
                            transaksi pengadaan.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 px-6 py-4">
                        {/* 1. Kode Upah */}
                        <div className="space-y-1.5">
                            <Label className="text-xs font-medium">
                                Kode Upah
                            </Label>
                            <Input
                                placeholder="Contoh: Upah.001"
                                value={kodeUpah}
                                onChange={(e) => setKodeUpah(e.target.value)}
                                required
                                className="h-8 font-mono text-xs"
                            />
                        </div>

                        {/* 2. Nama Upah */}
                        <div className="space-y-1.5">
                            <Label className="text-xs font-medium">
                                Nama Upah
                            </Label>
                            <Input
                                placeholder="Contoh: Upah Pembersihan Alat & Mesin"
                                value={namaUpah}
                                onChange={(e) => setNamaUpah(e.target.value)}
                                required
                                className="h-8 text-xs font-medium"
                            />
                        </div>

                        {/* 3. Satuan */}
                        <div className="space-y-1.5">
                            <Label className="text-xs font-medium">
                                Satuan
                            </Label>
                            <Input
                                placeholder="Contoh: Unit"
                                value={satuan}
                                onChange={(e) => setSatuan(e.target.value)}
                                required
                                className="h-8 text-xs"
                            />
                        </div>

                        {/* 4. Keterangan */}
                        <div className="space-y-1.5">
                            <Label className="text-xs font-medium">
                                Keterangan
                            </Label>
                            <Textarea
                                placeholder="-"
                                value={keterangan}
                                onChange={(e) => setKeterangan(e.target.value)}
                                rows={2}
                                className="resize-none text-xs"
                            />
                        </div>
                    </div>

                    <DialogFooter>
                        <DialogCancel
                            disabled={saving}
                            onClick={() => onOpenChange(false)}
                        >
                            Batal
                        </DialogCancel>
                        <DialogAction type="submit" disabled={saving}>
                            {saving
                                ? 'Menyimpan…'
                                : editingItem
                                  ? 'Simpan'
                                  : 'Tambah'}
                        </DialogAction>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
