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

export type JenisVendorItem = {
    id: string;
    kode_jenis: string;
    nama_jenis_vendor: string;
    keterangan: string | null;
    aktif: boolean;
    created_at?: string;
    updated_at?: string;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    editingItem: JenisVendorItem | null;
    onSuccess: (saved: JenisVendorItem, isNew: boolean) => void;
};

export function JenisVendorModal({
    open,
    onOpenChange,
    editingItem,
    onSuccess,
}: Props) {
    const [kodeJenis, setKodeJenis] = useState('');
    const [namaJenisVendor, setNamaJenisVendor] = useState('');
    const [keterangan, setKeterangan] = useState('');
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const timer = setTimeout(() => {
            if (editingItem) {
                setKodeJenis(editingItem.kode_jenis);
                setNamaJenisVendor(editingItem.nama_jenis_vendor);
                setKeterangan(editingItem.keterangan || '');
            } else {
                setKodeJenis('');
                setNamaJenisVendor('');
                setKeterangan('');
            }
        }, 0);

        return () => clearTimeout(timer);
    }, [editingItem, open]);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        if (!kodeJenis.trim()) {
            toast.error('Kode jenis wajib diisi.');

            return;
        }

        if (!namaJenisVendor.trim()) {
            toast.error('Nama jenis vendor wajib diisi.');

            return;
        }

        setSaving(true);

        try {
            if (editingItem) {
                const res = await api<{
                    status: string;
                    message: string;
                    data: JenisVendorItem;
                }>(`/jenis-vendor/${editingItem.id}`, {
                    method: 'PUT',
                    body: JSON.stringify({
                        kode_jenis: kodeJenis.trim(),
                        nama_jenis_vendor: namaJenisVendor.trim(),
                        keterangan: keterangan.trim() || null,
                    }),
                });

                toast.success('Jenis vendor berhasil diperbarui.');
                onSuccess(res.data, false);
            } else {
                const res = await api<{
                    status: string;
                    message: string;
                    data: JenisVendorItem;
                }>(`/jenis-vendor`, {
                    method: 'POST',
                    body: JSON.stringify({
                        kode_jenis: kodeJenis.trim(),
                        nama_jenis_vendor: namaJenisVendor.trim(),
                        keterangan: keterangan.trim() || null,
                        aktif: true,
                    }),
                });

                toast.success('Jenis vendor berhasil ditambahkan.');
                onSuccess(res.data, true);
            }

            onOpenChange(false);
        } catch (err) {
            toastSaveError(err, 'Gagal menyimpan jenis vendor.');
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
                                ? 'Ubah Jenis Vendor'
                                : 'Tambah Jenis Vendor'}
                        </DialogTitle>
                        <DialogDescription>
                            Isi kode dan nama kategori klasifikasi
                            rekanan/supplier vendor.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 px-6 py-4">
                        {/* 1. Kode Jenis */}
                        <div className="space-y-1.5">
                            <Label className="text-xs font-medium">
                                Kode Jenis
                            </Label>
                            <Input
                                placeholder="Contoh: Jenis.001"
                                value={kodeJenis}
                                onChange={(e) => setKodeJenis(e.target.value)}
                                required
                                className="h-8 font-mono text-xs"
                            />
                        </div>

                        {/* 2. Nama Jenis Vendor */}
                        <div className="space-y-1.5">
                            <Label className="text-xs font-medium">
                                Nama Jenis Vendor
                            </Label>
                            <Input
                                placeholder="Contoh: Supplier"
                                value={namaJenisVendor}
                                onChange={(e) =>
                                    setNamaJenisVendor(e.target.value)
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
                                placeholder="Supplier obat dan alat medis"
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
