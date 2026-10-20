import { Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@apperp/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@apperp/ui/dialog';
import { Input } from '@apperp/ui/input';
import { Label } from '@apperp/ui/label';
import { Textarea } from '@apperp/ui/textarea';
import { api, toastSaveError } from '../../api';

export type MetodePembayaranItem = {
    id: string;
    kode_bayar: string;
    nama_metode_bayar: string;
    keterangan: string | null;
    aktif: boolean;
    created_at?: string;
    updated_at?: string;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    editingItem: MetodePembayaranItem | null;
    onSuccess: (saved: MetodePembayaranItem, isNew: boolean) => void;
};

export function MetodePembayaranModal({
    open,
    onOpenChange,
    editingItem,
    onSuccess,
}: Props) {
    const [kodeBayar, setKodeBayar] = useState('');
    const [namaMetodeBayar, setNamaMetodeBayar] = useState('');
    const [keterangan, setKeterangan] = useState('');
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const timer = setTimeout(() => {
            if (editingItem) {
                setKodeBayar(editingItem.kode_bayar);
                setNamaMetodeBayar(editingItem.nama_metode_bayar);
                setKeterangan(editingItem.keterangan || '');
            } else {
                setKodeBayar('');
                setNamaMetodeBayar('');
                setKeterangan('');
            }
        }, 0);

        return () => clearTimeout(timer);
    }, [editingItem, open]);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        if (!kodeBayar.trim()) {
            toast.error('Kode bayar wajib diisi.');

            return;
        }

        if (!namaMetodeBayar.trim()) {
            toast.error('Nama metode bayar wajib diisi.');

            return;
        }

        setSaving(true);

        try {
            if (editingItem) {
                const res = await api<{
                    status: string;
                    message: string;
                    data: MetodePembayaranItem;
                }>(`/metode-pembayaran/${editingItem.id}`, {
                    method: 'PUT',
                    body: JSON.stringify({
                        kode_bayar: kodeBayar.trim(),
                        nama_metode_bayar: namaMetodeBayar.trim(),
                        keterangan: keterangan.trim() || null,
                    }),
                });

                toast.success('Metode pembayaran berhasil diperbarui.');
                onSuccess(res.data, false);
            } else {
                const res = await api<{
                    status: string;
                    message: string;
                    data: MetodePembayaranItem;
                }>(`/metode-pembayaran`, {
                    method: 'POST',
                    body: JSON.stringify({
                        kode_bayar: kodeBayar.trim(),
                        nama_metode_bayar: namaMetodeBayar.trim(),
                        keterangan: keterangan.trim() || null,
                        aktif: true,
                    }),
                });

                toast.success('Metode pembayaran berhasil ditambahkan.');
                onSuccess(res.data, true);
            }

            onOpenChange(false);
        } catch (err) {
            toastSaveError(err, 'Gagal menyimpan metode pembayaran.');
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
                            ? 'Edit Metode Pembayaran'
                            : 'Tambah Metode Pembayaran'}
                    </DialogTitle>
                </div>

                <form onSubmit={handleSubmit} className="space-y-3 p-5">
                    {/* 1. Kode Bayar */}
                    <div className="space-y-1">
                        <Label className="text-foreground text-xs font-medium">
                            Kode Bayar
                        </Label>
                        <Input
                            placeholder="Contoh: Metode.001"
                            value={kodeBayar}
                            onChange={(e) => setKodeBayar(e.target.value)}
                            required
                            className="bg-background border-input h-8 font-mono text-xs"
                        />
                    </div>

                    {/* 2. Nama Metode Bayar */}
                    <div className="space-y-1">
                        <Label className="text-foreground text-xs font-medium">
                            Nama Metode Bayar
                        </Label>
                        <Input
                            placeholder="Contoh: Transfer Bank"
                            value={namaMetodeBayar}
                            onChange={(e) => setNamaMetodeBayar(e.target.value)}
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
                            placeholder="Pembayaran melalui rekening bank"
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
                                'Tambah Metode'
                            )}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
