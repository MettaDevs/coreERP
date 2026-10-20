import { useEffect, useState } from 'react';
import { Button } from '@apperp/ui/button';
import {
    Dialog,
    DialogContent,
    DialogTitle,
} from '@apperp/ui/dialog';
import { Input } from '@apperp/ui/input';
import { Label } from '@apperp/ui/label';
import { Loader2 } from 'lucide-react';
import { toast } from 'sonner';
import { api, toastSaveError } from '../../api';

export type HargaPerkiraanItem = {
    id: string;
    group_item: string;
    jenis_item: string;
    kode_item: string;
    nama_item: string;
    satuan: string;
    harga_perkiraan: number;
    tanggal_update: string | null;
    keterangan?: string | null;
    created_at?: string;
    updated_at?: string;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    item: HargaPerkiraanItem | null;
    onSuccess: (updated: HargaPerkiraanItem) => void;
};

const BULAN_INDONESIA = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
];

export function formatIndonesianDate(dateStr: string | null | undefined): string {
    if (!dateStr) return '';
    try {
        const parts = dateStr.split('-');
        if (parts.length === 3) {
            const day = parseInt(parts[2], 10);
            const monthIdx = parseInt(parts[1], 10) - 1;
            const year = parts[0];
            if (monthIdx >= 0 && monthIdx < 12) {
                return `${day} ${BULAN_INDONESIA[monthIdx]} ${year}`;
            }
        }
        return dateStr;
    } catch {
        return dateStr || '';
    }
}

export function formatRupiah(value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') return 'Rp. 0';
    const num = typeof value === 'string' ? parseFloat(value) : value;
    if (isNaN(num)) return 'Rp. 0';
    return `Rp. ${new Intl.NumberFormat('id-ID').format(num)}`;
}

export function HargaPerkiraanEditModal({
    open,
    onOpenChange,
    item,
    onSuccess,
}: Props) {
    const [priceInput, setPriceInput] = useState('');
    const [dateInput, setDateInput] = useState('');
    const [code, setCode] = useState('');
    const [name, setName] = useState('');
    const [group, setGroup] = useState('');
    const [unit, setUnit] = useState('');
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (item) {
            setCode(item.kode_item || '');
            setName(item.nama_item || '');
            setGroup(item.group_item || '');
            setUnit(item.satuan || '');
            const today = new Date().toISOString().slice(0, 10);
            setDateInput(item.tanggal_update || today);
            if (item.harga_perkiraan !== undefined && item.harga_perkiraan !== null) {
                setPriceInput(
                    new Intl.NumberFormat('id-ID').format(Number(item.harga_perkiraan))
                );
            } else {
                setPriceInput('');
            }
        }
    }, [item]);

    if (!item) return null;

    const handlePriceChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const raw = e.target.value.replace(/[^0-9]/g, '');
        if (!raw) {
            setPriceInput('');
            return;
        }
        const num = parseInt(raw, 10);
        setPriceInput(new Intl.NumberFormat('id-ID').format(num));
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        const numericPrice = parseFloat(priceInput.replace(/[^0-9]/g, ''));
        if (isNaN(numericPrice) || numericPrice < 0) {
            toast.error('Harga perkiraan harus berupa nominal angka valid.');
            return;
        }

        setSaving(true);
        try {
            const res = await api<{
                status: string;
                message: string;
                data: HargaPerkiraanItem;
            }>(`/harga-perkiraan/${item.id}`, {
                method: 'PUT',
                body: JSON.stringify({
                    harga_perkiraan: numericPrice,
                    tanggal_update: dateInput || new Date().toISOString().slice(0, 10),
                    kode_item: code,
                    nama_item: name,
                    group_item: group,
                    satuan: unit,
                }),
            });

            toast.success('Data harga perkiraan berhasil diperbarui.');
            onSuccess(res.data);
            onOpenChange(false);
        } catch (err) {
            toastSaveError(err, 'Gagal memperbarui data harga perkiraan.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[460px] p-0 overflow-hidden border shadow-lg">
                <div className="px-5 py-3.5 bg-muted/20 border-b">
                    <DialogTitle className="text-sm font-semibold text-foreground">
                        Ubah Data Harga Perkiraan
                    </DialogTitle>
                </div>

                <form onSubmit={handleSubmit} className="p-5 space-y-3">
                    {/* 1. Tanggal Update */}
                    <div className="space-y-1">
                        <div className="flex items-center justify-between">
                            <Label className="text-xs font-medium text-foreground">
                                Tanggal Update
                            </Label>
                            {dateInput && (
                                <span className="text-[11px] text-muted-foreground font-normal">
                                    {formatIndonesianDate(dateInput)}
                                </span>
                            )}
                        </div>
                        <Input
                            type="date"
                            value={dateInput}
                            onChange={(e) => setDateInput(e.target.value)}
                            required
                            className="h-8 text-xs font-mono bg-background border-input"
                        />
                    </div>

                    {/* 2. Kode Item */}
                    <div className="space-y-1">
                        <Label className="text-xs font-medium text-foreground">
                            Kode Item
                        </Label>
                        <Input
                            placeholder="Contoh: Bahan 01"
                            value={code}
                            onChange={(e) => setCode(e.target.value)}
                            required
                            className="h-8 text-xs font-mono bg-background border-input"
                        />
                    </div>

                    {/* 3. Nama Item */}
                    <div className="space-y-1">
                        <Label className="text-xs font-medium text-foreground">
                            Nama Item
                        </Label>
                        <Input
                            placeholder="Contoh: Obat A"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            required
                            className="h-8 text-xs font-medium bg-background border-input"
                        />
                    </div>

                    {/* 4. Group Item & Satuan */}
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1">
                            <Label className="text-xs font-medium text-foreground">
                                Group Item
                            </Label>
                            <Input
                                placeholder="Contoh: Bahan"
                                value={group}
                                onChange={(e) => setGroup(e.target.value)}
                                required
                                className="h-8 text-xs bg-background border-input"
                            />
                        </div>
                        <div className="space-y-1">
                            <Label className="text-xs font-medium text-foreground">
                                Satuan
                            </Label>
                            <Input
                                placeholder="Contoh: Tablet"
                                value={unit}
                                onChange={(e) => setUnit(e.target.value)}
                                required
                                className="h-8 text-xs bg-background border-input"
                            />
                        </div>
                    </div>

                    {/* 5. Harga Perkiraan (HPS) */}
                    <div className="space-y-1">
                        <div className="flex items-center justify-between">
                            <Label className="text-xs font-bold text-foreground">
                                Harga Perkiraan (HPS)
                            </Label>
                            <span className="text-xs font-bold text-primary font-mono">
                                {formatRupiah(priceInput ? priceInput.replace(/[^0-9]/g, '') : 0)}
                            </span>
                        </div>
                        <div className="relative">
                            <span className="absolute inset-y-0 left-0 pl-2.5 flex items-center text-xs font-bold text-muted-foreground pointer-events-none">
                                Rp.
                            </span>
                            <Input
                                type="text"
                                required
                                value={priceInput}
                                onChange={handlePriceChange}
                                placeholder="0"
                                className="h-8 pl-9 text-xs font-bold text-foreground bg-background border-input"
                            />
                        </div>
                    </div>

                    {/* Tombol Aksi */}
                    <div className="pt-3 flex items-center justify-end gap-2 border-t mt-4">
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
                            ) : (
                                'Simpan Perubahan'
                            )}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
