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

export type TerminPembayaranItem = {
    id: string;
    kode_termin: string;
    nama_termin_bayar: string;
    keterangan: string | null;
    aktif: boolean;
    created_at?: string;
    updated_at?: string;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    editingItem: TerminPembayaranItem | null;
    onSuccess: (saved: TerminPembayaranItem, isNew: boolean) => void;
};

export function TerminPembayaranModal({
    open,
    onOpenChange,
    editingItem,
    onSuccess,
}: Props) {
    const [kodeTermin, setKodeTermin] = useState('');
    const [namaTerminBayar, setNamaTerminBayar] = useState('');
    const [keterangan, setKeterangan] = useState('');
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const timer = setTimeout(() => {
            if (editingItem) {
                setKodeTermin(editingItem.kode_termin);
                setNamaTerminBayar(editingItem.nama_termin_bayar);
                setKeterangan(editingItem.keterangan || '');
            } else {
                setKodeTermin('');
                setNamaTerminBayar('');
                setKeterangan('');
            }
        }, 0);

        return () => clearTimeout(timer);
    }, [editingItem, open]);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        if (!kodeTermin.trim()) {
            toast.error('Kode termin wajib diisi.');

            return;
        }

        if (!namaTerminBayar.trim()) {
            toast.error('Nama termin bayar wajib diisi.');

            return;
        }

        setSaving(true);

        try {
            if (editingItem) {
                const res = await api<{
                    status: string;
                    message: string;
                    data: TerminPembayaranItem;
                }>(`/termin-pembayaran/${editingItem.id}`, {
                    method: 'PUT',
                    body: JSON.stringify({
                        kode_termin: kodeTermin.trim(),
                        nama_termin_bayar: namaTerminBayar.trim(),
                        keterangan: keterangan.trim() || null,
                    }),
                });

                toast.success('Termin pembayaran berhasil diperbarui.');
                onSuccess(res.data, false);
            } else {
                const res = await api<{
                    status: string;
                    message: string;
                    data: TerminPembayaranItem;
                }>(`/termin-pembayaran`, {
                    method: 'POST',
                    body: JSON.stringify({
                        kode_termin: kodeTermin.trim(),
                        nama_termin_bayar: namaTerminBayar.trim(),
                        keterangan: keterangan.trim() || null,
                        aktif: true,
                    }),
                });

                toast.success('Termin pembayaran berhasil ditambahkan.');
                onSuccess(res.data, true);
            }

            onOpenChange(false);
        } catch (err) {
            toastSaveError(err, 'Gagal menyimpan termin pembayaran.');
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
                                ? 'Ubah Termin Pembayaran'
                                : 'Tambah Termin Pembayaran'}
                        </DialogTitle>
                        <DialogDescription>
                            Isi kode dan nama termin pembayaran yang digunakan
                            dalam transaksi pengadaan.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 px-6 py-4">
                        {/* 1. Kode Termin */}
                        <div className="space-y-1.5">
                            <Label className="text-xs font-medium">
                                Kode Termin
                            </Label>
                            <Input
                                placeholder="Contoh: Termin.001"
                                value={kodeTermin}
                                onChange={(e) => setKodeTermin(e.target.value)}
                                required
                                className="h-8 font-mono text-xs"
                            />
                        </div>

                        {/* 2. Nama Termin Bayar */}
                        <div className="space-y-1.5">
                            <Label className="text-xs font-medium">
                                Nama Termin Bayar
                            </Label>
                            <Input
                                placeholder="Contoh: Down Payment (DP)"
                                value={namaTerminBayar}
                                onChange={(e) =>
                                    setNamaTerminBayar(e.target.value)
                                }
                                required
                                className="h-8 text-xs font-medium"
                            />
                        </div>

                        {/* 3. Keterangan */}
                        <div className="space-y-1.5">
                            <Label className="text-xs font-medium">
                                Keterangan
                            </Label>
                            <Textarea
                                placeholder="Pembayaran sebagian di awal, sisanya setelah barang/jasa diterima"
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
