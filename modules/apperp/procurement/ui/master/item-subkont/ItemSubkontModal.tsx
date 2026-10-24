import { useEffect, useState } from "react";
import { toast } from "sonner";
import {
    Dialog,
    DialogAction,
    DialogCancel,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@apperp/ui/dialog";
import { Input } from "@apperp/ui/input";
import { Label } from "@apperp/ui/label";
import { Textarea } from "@apperp/ui/textarea";
import { api, toastSaveError } from "../../api";

export type ItemSubkontRecord = {
    id: string;
    kode_subkont: string;
    nama_subkont: string;
    satuan: string;
    keterangan: string | null;
    aktif: boolean;
    created_at?: string;
    updated_at?: string;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    editingItem: ItemSubkontRecord | null;
    onSuccess: (saved: ItemSubkontRecord, isNew: boolean) => void;
};

export function ItemSubkontModal({
    open,
    onOpenChange,
    editingItem,
    onSuccess,
}: Props) {
    const [kodeSubkont, setKodeSubkont] = useState("");
    const [namaSubkont, setNamaSubkont] = useState("");
    const [satuan, setSatuan] = useState("");
    const [keterangan, setKeterangan] = useState("");
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const timer = setTimeout(() => {
            if (editingItem) {
                setKodeSubkont(editingItem.kode_subkont);
                setNamaSubkont(editingItem.nama_subkont);
                setSatuan(editingItem.satuan);
                setKeterangan(editingItem.keterangan || "");
            } else {
                setKodeSubkont("");
                setNamaSubkont("");
                setSatuan("");
                setKeterangan("");
            }
        }, 0);

        return () => clearTimeout(timer);
    }, [editingItem, open]);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        if (!kodeSubkont.trim()) {
            toast.error("Kode subkont wajib diisi.");

            return;
        }

        if (!namaSubkont.trim()) {
            toast.error("Nama subkont wajib diisi.");

            return;
        }

        if (!satuan.trim()) {
            toast.error("Satuan wajib diisi.");

            return;
        }

        setSaving(true);

        try {
            if (editingItem) {
                const res = await api<{
                    status: string;
                    message: string;
                    data: ItemSubkontRecord;
                }>(`/item-subkont/${editingItem.id}`, {
                    method: "PUT",
                    body: JSON.stringify({
                        kode_subkont: kodeSubkont.trim(),
                        nama_subkont: namaSubkont.trim(),
                        satuan: satuan.trim(),
                        keterangan: keterangan.trim() || null,
                    }),
                });

                toast.success("Item subkontraktor berhasil diperbarui.");
                onSuccess(res.data, false);
            } else {
                const res = await api<{
                    status: string;
                    message: string;
                    data: ItemSubkontRecord;
                }>(`/item-subkont`, {
                    method: "POST",
                    body: JSON.stringify({
                        kode_subkont: kodeSubkont.trim(),
                        nama_subkont: namaSubkont.trim(),
                        satuan: satuan.trim(),
                        keterangan: keterangan.trim() || null,
                        aktif: true,
                    }),
                });

                toast.success("Item subkontraktor berhasil ditambahkan.");
                onSuccess(res.data, true);
            }

            onOpenChange(false);
        } catch (err) {
            toastSaveError(err, "Gagal menyimpan item subkontraktor.");
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
                                ? "Ubah Item Subkont"
                                : "Tambah Item Subkont"}
                        </DialogTitle>
                        <DialogDescription>
                            Isi kode, nama subkont, satuan, dan keterangan untuk
                            transaksi pengadaan.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 px-6 py-4">
                        {/* 1. Kode Subkont */}
                        <div className="space-y-1.5">
                            <Label className="text-xs font-medium">
                                Kode Subkont
                            </Label>
                            <Input
                                placeholder="Subkon.001"
                                value={kodeSubkont}
                                onChange={(e) => setKodeSubkont(e.target.value)}
                                required
                                className="h-8 font-mono text-xs"
                            />
                        </div>

                        {/* 2. Nama Subkont */}
                        <div className="space-y-1.5">
                            <Label className="text-xs font-medium">
                                Nama Subkont
                            </Label>
                            <Input
                                placeholder="Pembangunan Rangkaian Struktur IPAL"
                                value={namaSubkont}
                                onChange={(e) => setNamaSubkont(e.target.value)}
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
                                placeholder="Unit"
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
                                ? "Menyimpan…"
                                : editingItem
                                  ? "Simpan"
                                  : "Tambah"}
                        </DialogAction>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
