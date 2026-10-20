import { useEffect, useState } from 'react';
import { Button } from '@apperp/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import { Input } from '@apperp/ui/input';
import { Label } from '@apperp/ui/label';
import { Textarea } from '@apperp/ui/textarea';
import { Loader2 } from 'lucide-react';
import { toast } from 'sonner';
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
        if (editingItem) {
            setKodeTermin(editingItem.kode_termin);
            setNamaTerminBayar(editingItem.nama_termin_bayar);
            setKeterangan(editingItem.keterangan || '');
        } else {
            setKodeTermin('');
            setNamaTerminBayar('');
            setKeterangan('');
        }
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
            <DialogContent className="overflow-hidden border p-0 shadow-lg sm:max-w-[440px]">
                <div className="bg-muted/20 border-b px-5 py-3.5">
                    <DialogTitle className="text-foreground text-sm font-semibold">
                        {editingItem
                            ? 'Edit Termin Pembayaran'
                            : 'Tambah Termin Pembayaran'}
                    </DialogTitle>
                </div>

                <form onSubmit={handleSubmit} className="space-y-3 p-5">
                    {/* 1. Kode Termin */}
                    <div className="space-y-1">
                        <Label className="text-foreground text-xs font-medium">
                            Kode Termin
                        </Label>
                        <Input
                            placeholder="Contoh: Termin.001"
                            value={kodeTermin}
                            onChange={(e) => setKodeTermin(e.target.value)}
                            required
                            className="bg-background border-input h-8 font-mono text-xs"
                        />
                    </div>

                    {/* 2. Nama Termin Bayar */}
                    <div className="space-y-1">
                        <Label className="text-foreground text-xs font-medium">
                            Nama Termin Bayar
                        </Label>
                        <Input
                            placeholder="Contoh: Down Payment (DP)"
                            value={namaTerminBayar}
                            onChange={(e) => setNamaTerminBayar(e.target.value)}
                            required
                            className="bg-background border-input h-8 text-xs font-medium"
                        />
                    </div>

                    {/* 3. Keterangan */}
                    <div className="space-y-1">
                        <Label className="text-foreground text-xs font-medium">
                            Keterangan
                        </Label>
                        <Textarea
                            placeholder="Pembayaran sebagian di awal, sisanya setelah barang/jasa diterima"
                            value={keterangan}
                            onChange={(e) => setKeterangan(e.target.value)}
                            rows={2}
                            className="bg-background border-input resize-none text-xs"
                        />
                    </div>

                    {/* Tombol Aksi */}
                    <div className="mt-4 flex items-center justify-end gap-2 border-t pt-3">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            disabled={saving}
                            onClick={() => onOpenChange(false)}
                            className="h-8 text-xs"
                        >
                            Batal
                        </Button>
                        <Button
                            type="submit"
                            size="sm"
                            disabled={saving}
                            className="h-8 px-4 text-xs font-medium"
                        >
                            {saving ? (
                                <>
                                    <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
                                    Menyimpan...
                                </>
                            ) : editingItem ? (
                                'Simpan Perubahan'
                            ) : (
                                'Tambah Termin'
                            )}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
