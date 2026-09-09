import { useState, useRef, useEffect } from 'react';
import { MapPin } from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@apperp/ui/dialog';
import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { Textarea } from '@apperp/ui/textarea';
import { Switch } from '@apperp/ui/switch';
import type { AddressItem } from '@/types/global-address-book';

interface AddressSheetFormProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    initialData?: AddressItem | null;
    onSave: (address: AddressItem) => void;
}

export function AddressSheetForm({
    open,
    onOpenChange,
    initialData,
    onSave,
}: AddressSheetFormProps) {
    const dialogContentRef = useRef<HTMLDivElement>(null);

    const [formData, setFormData] = useState<AddressItem>({
        id: '',
        location_id: '000004822',
        description: '',
        purpose: '',
        country: '',
        postal_code: '',
        street: '',
        street_number: '',
        building_complement: '',
        building: '',
        post_box: '',
        city: '',
        district: '',
        state: '',
        county: '',
        is_primary: true,
        is_private: false,
        is_primary_for_country_region: true,
    });

    useEffect(() => {
        if (initialData) {
            setFormData({
                ...initialData,
                location_id:
                    initialData.location_id || initialData.id || '000004822',
                purpose: initialData.purpose || '',
                country: initialData.country || '',
                postal_code: initialData.postal_code || '',
                city: initialData.city || '',
                district: initialData.district || '',
                state: initialData.state || '',
                county: initialData.county || '',
                is_primary_for_country_region:
                    initialData.is_primary_for_country_region ??
                    initialData.is_primary ??
                    false,
            });
        } else {
            const randomLoc = `00000${Math.floor(4000 + Math.random() * 5000)}`;
            setFormData({
                id: `ADDR-${Date.now().toString().slice(-4)}`,
                location_id: randomLoc,
                description: '',
                purpose: '',
                country: '',
                postal_code: '',
                street: '',
                street_number: '',
                building_complement: '',
                building: '',
                post_box: '',
                city: '',
                district: '',
                state: '',
                county: '',
                is_primary: true,
                is_private: false,
                is_primary_for_country_region: true,
            });
        }
    }, [initialData, open]);

    const handleChange = (field: keyof AddressItem, value: any) => {
        setFormData((prev) => ({
            ...prev,
            [field]: value,
        }));
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        onSave(formData);
        onOpenChange(false);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                ref={dialogContentRef}
                className="flex max-h-[90vh] flex-col overflow-hidden p-0 sm:max-w-2xl"
            >
                {/* 1. HEADER DIALOG STANDAR */}
                <DialogHeader className="border-b px-6 py-4">
                    <div className="flex items-center gap-3">
                        <div className="flex size-10 shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                            <MapPin className="size-5" />
                        </div>
                        <div>
                            <DialogTitle>
                                {initialData
                                    ? 'Ubah Alamat'
                                    : 'Tambah Alamat Baru'}
                            </DialogTitle>
                            <DialogDescription>
                                Simpan alamat lengkap dan peruntukan lokasi
                                pihak ini.
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                {/* 2. FORM BODY (SCROLLABLE) */}
                <form
                    onSubmit={handleSubmit}
                    className="flex flex-1 flex-col overflow-hidden"
                >
                    <div className="flex-1 space-y-4 overflow-y-auto px-6 py-4">
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            {/* Location ID */}
                            <Input
                                id="location_id"
                                label="ID Lokasi"
                                value={formData.location_id || formData.id}
                                readOnly
                                className="bg-muted/40 font-mono text-sm"
                            />

                            {/* Peruntukan / Purpose */}
                            <Select
                                label="Peruntukan Alamat"
                                items={[]}
                                value={formData.purpose || null}
                                onValueChange={(val) =>
                                    handleChange('purpose', val || '')
                                }
                                placeholder="Pilih peruntukan alamat"
                                portalContainer={dialogContentRef}
                            />

                            {/* Nama / Deskripsi */}
                            <div className="md:col-span-2">
                                <Input
                                    id="addr_description"
                                    label="Nama / Keterangan Alamat"
                                    required
                                    placeholder="Contoh: Kantor Pusat, Pabrik Utama, Gudang Logistik"
                                    value={formData.description}
                                    onChange={(e) =>
                                        handleChange(
                                            'description',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>

                            {/* Negara / Wilayah */}
                            <Select
                                label="Negara / Wilayah"
                                required
                                items={[]}
                                value={formData.country || null}
                                onValueChange={(val) =>
                                    handleChange('country', val || '')
                                }
                                placeholder="Pilih negara atau wilayah"
                                portalContainer={dialogContentRef}
                            />

                            {/* Kode Pos */}
                            <Input
                                id="addr_postal_code"
                                label="Kode Pos"
                                placeholder="Masukkan kode pos"
                                value={formData.postal_code || ''}
                                onChange={(e) =>
                                    handleChange('postal_code', e.target.value)
                                }
                            />

                            {/* Jalan */}
                            <div className="md:col-span-2">
                                <label
                                    htmlFor="addr_street"
                                    className="mb-1.5 block text-xs font-medium text-foreground"
                                >
                                    Alamat Jalan
                                </label>
                                <Textarea
                                    id="addr_street"
                                    rows={2}
                                    value={formData.street}
                                    onChange={(e) =>
                                        handleChange('street', e.target.value)
                                    }
                                    placeholder="Nama jalan, nomor gedung, atau patokan lokasi..."
                                    className="w-full resize-y text-sm"
                                />
                            </div>

                            {/* Nomor Jalan */}
                            <Input
                                id="addr_street_number"
                                label="Nomor Bangunan / Jalan"
                                placeholder="Contoh: No. 42"
                                value={formData.street_number || ''}
                                onChange={(e) =>
                                    handleChange(
                                        'street_number',
                                        e.target.value,
                                    )
                                }
                            />

                            {/* Gedung / Unit / Lantai */}
                            <Input
                                id="addr_building_comp"
                                label="Gedung / Unit / Lantai"
                                placeholder="Contoh: Gedung Graha Lt. 5"
                                value={
                                    formData.building_complement ||
                                    formData.building ||
                                    ''
                                }
                                onChange={(e) => {
                                    handleChange(
                                        'building_complement',
                                        e.target.value,
                                    );
                                    handleChange('building', e.target.value);
                                }}
                            />

                            {/* Kotak Pos */}
                            <Input
                                id="addr_post_box"
                                label="Kotak Pos (PO Box)"
                                placeholder="Nomor PO Box (opsional)"
                                value={formData.post_box || ''}
                                onChange={(e) =>
                                    handleChange('post_box', e.target.value)
                                }
                            />

                            {/* Kota / Kabupaten */}
                            <Select
                                label="Kota / Kabupaten"
                                items={[]}
                                value={formData.city || null}
                                onValueChange={(val) =>
                                    handleChange('city', val || '')
                                }
                                placeholder="Pilih kota atau kabupaten"
                                portalContainer={dialogContentRef}
                            />

                            {/* Kecamatan */}
                            <Select
                                label="Kecamatan"
                                items={[]}
                                value={formData.district || null}
                                onValueChange={(val) =>
                                    handleChange('district', val || '')
                                }
                                placeholder="Pilih kecamatan"
                                portalContainer={dialogContentRef}
                            />

                            {/* Provinsi */}
                            <Select
                                label="Provinsi"
                                items={[]}
                                value={formData.state || null}
                                onValueChange={(val) =>
                                    handleChange('state', val || '')
                                }
                                placeholder="Pilih provinsi"
                                portalContainer={dialogContentRef}
                            />

                            {/* Wilayah / Daerah Tambahan */}
                            <Select
                                label="Wilayah Tambahan"
                                items={[]}
                                value={formData.county || null}
                                onValueChange={(val) =>
                                    handleChange('county', val || '')
                                }
                                placeholder="Pilih wilayah tambahan"
                                portalContainer={dialogContentRef}
                            />
                        </div>

                        {/* STATUS SWITCHES */}
                        <div className="space-y-3 pt-2">
                            <div className="flex items-center justify-between rounded-lg border bg-muted/20 p-3">
                                <div>
                                    <p className="text-sm font-medium text-foreground">
                                        Alamat Utama (Primary)
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Jadikan alamat ini sebagai alamat
                                        surat-menyurat utama
                                    </p>
                                </div>
                                <Switch
                                    checked={formData.is_primary}
                                    onCheckedChange={(checked) =>
                                        handleChange('is_primary', checked)
                                    }
                                />
                            </div>

                            <div className="flex items-center justify-between rounded-lg border bg-muted/20 p-3">
                                <div>
                                    <p className="text-sm font-medium text-foreground">
                                        Alamat Pribadi (Private)
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Batasi akses hanya untuk pengguna yang
                                        memiliki wewenang
                                    </p>
                                </div>
                                <Switch
                                    checked={formData.is_private}
                                    onCheckedChange={(checked) =>
                                        handleChange('is_private', checked)
                                    }
                                />
                            </div>

                            <div className="flex items-center justify-between rounded-lg border bg-muted/20 p-3">
                                <div>
                                    <p className="text-sm font-medium text-foreground">
                                        Utama untuk Negara/Wilayah
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Jadikan alamat default untuk wilayah
                                        negara terpilih
                                    </p>
                                </div>
                                <Switch
                                    checked={
                                        formData.is_primary_for_country_region ??
                                        formData.is_primary
                                    }
                                    onCheckedChange={(checked) =>
                                        handleChange(
                                            'is_primary_for_country_region',
                                            checked,
                                        )
                                    }
                                />
                            </div>
                        </div>
                    </div>

                    {/* 3. FOOTER DIALOG */}
                    <DialogFooter className="border-t bg-background px-6 py-3.5">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Batal
                        </Button>
                        <Button type="submit">Simpan Alamat</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
