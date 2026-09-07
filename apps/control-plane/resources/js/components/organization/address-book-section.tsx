import { Pencil, Plus, Star, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@apperp/ui/alert-dialog';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Dialog,
    DialogAction,
    DialogBody,
    DialogCancel,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import { Field, FieldDescription, FieldGroup } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import { Switch } from '@apperp/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { apiJson, apiRequest, errorText } from '@/lib/core-api';

/**
 * Alamat dan informasi kontak satu organisasi, dibaca dari buku alamat party Core
 * (padanan Addresses dan Contact information pada legal entity Dynamics 365).
 *
 * Alamat utama dan kontak utama per jenis yang dipakai kop dokumen; identitas cetak
 * tidak menyimpan salinannya. Karena itu kedua daftar ini adalah satu-satunya tempat
 * mengubah alamat atau telepon yang tercetak.
 */

type Location = {
    id: string;
    name: string;
    purpose: string;
    is_primary: boolean;
    country_region_code: string | null;
    province: string | null;
    city: string | null;
    district: string | null;
    street: string | null;
    building: string | null;
    postbox: string | null;
    postal_code: string | null;
    formatted: string;
};

type Country = { code: string; name: string };

type LocationForm = {
    name: string;
    purpose: string;
    is_primary: boolean;
    country_region_code: string;
    street: string;
    building: string;
    district: string;
    city: string;
    province: string;
    postal_code: string;
    postbox: string;
};

const PURPOSE_LABEL: Record<string, string> = {
    business: 'Kantor / usaha',
    delivery: 'Pengiriman',
    invoice: 'Penagihan',
    payment: 'Pembayaran',
    home: 'Rumah',
};

const emptyLocation = (countryCode: string): LocationForm => ({
    name: '',
    purpose: 'business',
    is_primary: false,
    country_region_code: countryCode,
    street: '',
    building: '',
    district: '',
    city: '',
    province: '',
    postal_code: '',
    postbox: '',
});

const locationToForm = (location: Location): LocationForm => ({
    name: location.name,
    purpose: location.purpose,
    is_primary: location.is_primary,
    country_region_code: location.country_region_code ?? 'ID',
    street: location.street ?? '',
    building: location.building ?? '',
    district: location.district ?? '',
    city: location.city ?? '',
    province: location.province ?? '',
    postal_code: location.postal_code ?? '',
    postbox: location.postbox ?? '',
});

function useList<T, M>(url: string, fallback: string) {
    const [items, setItems] = useState<T[]>([]);
    const [meta, setMeta] = useState<M | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [reloadKey, setReloadKey] = useState(0);

    useEffect(() => {
        let cancelled = false;
        apiJson<{ data: T[]; meta: M }>(url)
            .then((result) => {
                if (cancelled) {
                    return;
                }

                setItems(result.data);
                setMeta(result.meta);
                setError('');
            })
            .catch((caught: unknown) => {
                if (!cancelled) {
                    setError(errorText(caught, fallback));
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [url, fallback, reloadKey]);

    return {
        items,
        meta,
        loading,
        error,
        reload: () => setReloadKey((key) => key + 1),
    };
}

export function OrganizationAddressesSection({
    organizationId,
    countryCode,
    canManage,
}: {
    organizationId: string;
    countryCode: string;
    canManage: boolean;
}) {
    const base = `/api/v1/organizations/${organizationId}/locations`;
    const list = useList<
        Location,
        { purposes: string[]; countries: Country[] }
    >(base, 'Alamat belum dapat dimuat.');
    const [editing, setEditing] = useState<Location | 'new' | null>(null);
    const [form, setForm] = useState<LocationForm>(emptyLocation(countryCode));
    const [saving, setSaving] = useState(false);
    const [formError, setFormError] = useState('');

    const open = (target: Location | 'new') => {
        setForm(
            target === 'new'
                ? emptyLocation(countryCode)
                : locationToForm(target),
        );
        setFormError('');
        setEditing(target);
    };

    const save = async () => {
        setSaving(true);

        try {
            if (editing === 'new') {
                await apiJson(base, {
                    method: 'POST',
                    body: JSON.stringify(form),
                });
                toast.success('Alamat ditambahkan.');
            } else if (editing) {
                await apiJson(`${base}/${editing.id}`, {
                    method: 'PUT',
                    body: JSON.stringify(form),
                });
                toast.success('Alamat disimpan.');
            }

            setEditing(null);
            list.reload();
        } catch (caught) {
            setFormError(errorText(caught, 'Alamat belum dapat disimpan.'));
        } finally {
            setSaving(false);
        }
    };

    const remove = async (location: Location) => {
        try {
            await apiRequest(`${base}/${location.id}`, { method: 'DELETE' });
            toast.success('Alamat dihapus.');
            list.reload();
        } catch (caught) {
            toast.error(errorText(caught, 'Alamat belum dapat dihapus.'));
        }
    };

    const makePrimary = async (location: Location) => {
        try {
            await apiJson(`${base}/${location.id}`, {
                method: 'PUT',
                body: JSON.stringify({
                    ...locationToForm(location),
                    is_primary: true,
                }),
            });
            toast.success('Alamat utama diganti.');
            list.reload();
        } catch (caught) {
            toast.error(errorText(caught, 'Alamat utama belum dapat diganti.'));
        }
    };

    const text = (
        key: Exclude<keyof LocationForm, 'is_primary'>,
        label: string,
        placeholder?: string,
    ) => (
        <Field key={key}>
            <Input
                label={label}
                placeholder={placeholder}
                value={form[key]}
                onChange={(event) =>
                    setForm((current) => ({
                        ...current,
                        [key]: event.target.value,
                    }))
                }
            />
        </Field>
    );

    if (list.loading) {
        return <p className="text-sm text-muted-foreground">Memuat alamat…</p>;
    }

    return (
        <div className="space-y-3">
            {list.error && (
                <p className="text-sm text-destructive">{list.error}</p>
            )}
            <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <p className="text-sm text-muted-foreground">
                    Alamat utama tampil pada kop setiap dokumen yang dicetak
                    atas nama organisasi ini. Alamat lain dipakai untuk
                    pengiriman, penagihan, atau lokasi cabang.
                </p>
                {canManage && (
                    <Button
                        type="button"
                        size="sm"
                        className="shrink-0"
                        onClick={() => open('new')}
                    >
                        <Plus className="mr-1.5 size-3.5" />
                        Tambah alamat
                    </Button>
                )}
            </div>

            {list.items.length === 0 ? (
                <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                    Belum ada alamat. Tanpa alamat utama, bagian alamat pada kop
                    dokumen kosong.
                </p>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nama atau keterangan</TableHead>
                                <TableHead>Alamat</TableHead>
                                <TableHead>Kegunaan</TableHead>
                                <TableHead>Utama</TableHead>
                                {canManage && (
                                    <TableHead className="w-28 text-right">
                                        Aksi
                                    </TableHead>
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {list.items.map((location) => (
                                <TableRow key={location.id}>
                                    <TableCell className="font-medium">
                                        {location.name}
                                    </TableCell>
                                    <TableCell className="whitespace-pre-line text-muted-foreground">
                                        {location.formatted || '—'}
                                    </TableCell>
                                    <TableCell>
                                        {PURPOSE_LABEL[location.purpose] ??
                                            location.purpose}
                                    </TableCell>
                                    <TableCell>
                                        {location.is_primary ? (
                                            <Badge>Utama</Badge>
                                        ) : canManage ? (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="h-7 px-2 text-xs"
                                                onClick={() =>
                                                    void makePrimary(location)
                                                }
                                            >
                                                <Star className="mr-1 size-3" />
                                                Jadikan utama
                                            </Button>
                                        ) : (
                                            '—'
                                        )}
                                    </TableCell>
                                    {canManage && (
                                        <TableCell className="text-right">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Ubah ${location.name}`}
                                                onClick={() => open(location)}
                                            >
                                                <Pencil className="size-4" />
                                            </Button>
                                            <AlertDialog>
                                                <AlertDialogTrigger asChild>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Hapus ${location.name}`}
                                                    >
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                </AlertDialogTrigger>
                                                <AlertDialogContent>
                                                    <AlertDialogHeader>
                                                        <AlertDialogTitle>
                                                            Hapus alamat{' '}
                                                            {location.name}?
                                                        </AlertDialogTitle>
                                                        <AlertDialogDescription>
                                                            {location.is_primary
                                                                ? 'Ini alamat utama. Alamat tertua yang tersisa akan menjadi utama dan tampil pada kop dokumen.'
                                                                : 'Alamat ini tidak lagi tersedia untuk dokumen dan pengiriman.'}
                                                        </AlertDialogDescription>
                                                    </AlertDialogHeader>
                                                    <AlertDialogFooter>
                                                        <AlertDialogCancel>
                                                            Batal
                                                        </AlertDialogCancel>
                                                        <AlertDialogAction
                                                            onClick={() =>
                                                                void remove(
                                                                    location,
                                                                )
                                                            }
                                                        >
                                                            Hapus
                                                        </AlertDialogAction>
                                                    </AlertDialogFooter>
                                                </AlertDialogContent>
                                            </AlertDialog>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}

            <Dialog
                open={editing !== null}
                onOpenChange={(isOpen) => !isOpen && setEditing(null)}
            >
                <DialogContent size="wide">
                    <DialogHeader>
                        <DialogTitle>
                            {editing === 'new' ? 'Alamat baru' : 'Ubah alamat'}
                        </DialogTitle>
                        <DialogDescription>
                            Bentuk tercetaknya disusun otomatis: jalan dan
                            gedung, kelurahan atau kecamatan, lalu kota,
                            provinsi, dan kode pos.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        onSubmit={(event) => {
                            // Dialog dirender lewat portal di dalam form organisasi; React
                            // tetap meneruskan submit ke form induk bila tidak dihentikan.
                            event.preventDefault();
                            event.stopPropagation();
                            void save();
                        }}
                    >
                        <DialogBody className="space-y-4 py-3">
                            <FieldGroup className="grid gap-4 md:grid-cols-2">
                                {text(
                                    'name',
                                    'Nama atau keterangan',
                                    'Kantor pusat',
                                )}
                                <Field>
                                    <NativeSelect
                                        label="Kegunaan"
                                        value={form.purpose}
                                        onChange={(event) =>
                                            setForm((current) => ({
                                                ...current,
                                                purpose: event.target.value,
                                            }))
                                        }
                                    >
                                        {(list.meta?.purposes ?? []).map(
                                            (purpose) => (
                                                <NativeSelectOption
                                                    key={purpose}
                                                    value={purpose}
                                                >
                                                    {PURPOSE_LABEL[purpose] ??
                                                        purpose}
                                                </NativeSelectOption>
                                            ),
                                        )}
                                    </NativeSelect>
                                </Field>
                            </FieldGroup>
                            <FieldGroup className="grid gap-4 md:grid-cols-2">
                                {text(
                                    'street',
                                    'Jalan dan nomor',
                                    'Jl. I Gusti Ngurah Rai No. 8',
                                )}
                                {text(
                                    'building',
                                    'Gedung / blok / lantai',
                                    'Rukan CBD Blok K',
                                )}
                                {text(
                                    'district',
                                    'Kelurahan / kecamatan',
                                    'Mengwitani, Mengwi',
                                )}
                                {text('city', 'Kota / kabupaten', 'Badung')}
                                {text('province', 'Provinsi', 'Bali')}
                                {text('postal_code', 'Kode pos', '80351')}
                            </FieldGroup>
                            <FieldGroup className="grid gap-4 md:grid-cols-2">
                                <Field>
                                    <NativeSelect
                                        label="Negara"
                                        value={form.country_region_code}
                                        onChange={(event) =>
                                            setForm((current) => ({
                                                ...current,
                                                country_region_code:
                                                    event.target.value,
                                            }))
                                        }
                                    >
                                        {(list.meta?.countries ?? []).map(
                                            (country) => (
                                                <NativeSelectOption
                                                    key={country.code}
                                                    value={country.code}
                                                >
                                                    {country.name}
                                                </NativeSelectOption>
                                            ),
                                        )}
                                    </NativeSelect>
                                    <FieldDescription>
                                        Nama negara ikut tercetak hanya untuk
                                        alamat di luar Indonesia.
                                    </FieldDescription>
                                </Field>
                                {text('postbox', 'PO Box')}
                            </FieldGroup>
                            <Field>
                                <label className="flex items-center gap-3 text-sm">
                                    <Switch
                                        checked={form.is_primary}
                                        onCheckedChange={(checked) =>
                                            setForm((current) => ({
                                                ...current,
                                                is_primary: checked,
                                            }))
                                        }
                                    />
                                    Jadikan alamat utama (tampil pada kop
                                    dokumen)
                                </label>
                                <FieldDescription>
                                    Alamat pertama otomatis menjadi utama.
                                </FieldDescription>
                            </Field>
                            {formError && (
                                <p className="text-sm text-destructive">
                                    {formError}
                                </p>
                            )}
                        </DialogBody>
                        <DialogFooter>
                            <DialogAction type="submit" disabled={saving}>
                                {saving ? 'Menyimpan…' : 'Simpan alamat'}
                            </DialogAction>
                            <DialogCancel />
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}

type Contact = {
    id: string;
    type: string;
    value: string;
    purpose: string | null;
    is_primary: boolean;
};

type ContactForm = {
    type: string;
    value: string;
    purpose: string;
    is_primary: boolean;
};

const TYPE_LABEL: Record<string, string> = {
    phone: 'Telepon',
    whatsapp: 'WhatsApp',
    email: 'Email',
    fax: 'Faks',
    url: 'Laman web',
};

const TYPE_PLACEHOLDER: Record<string, string> = {
    phone: '(0361) 829769',
    whatsapp: '0812-3456-7890',
    email: 'info@perusahaan.co.id',
    fax: '(0361) 829770',
    url: 'https://perusahaan.co.id',
};

export function OrganizationContactsSection({
    organizationId,
    canManage,
}: {
    organizationId: string;
    canManage: boolean;
}) {
    const base = `/api/v1/organizations/${organizationId}/contacts`;
    const list = useList<Contact, { types: string[] }>(
        base,
        'Informasi kontak belum dapat dimuat.',
    );
    const [editing, setEditing] = useState<Contact | 'new' | null>(null);
    const [form, setForm] = useState<ContactForm>({
        type: 'phone',
        value: '',
        purpose: '',
        is_primary: false,
    });
    const [saving, setSaving] = useState(false);
    const [formError, setFormError] = useState('');

    const open = (target: Contact | 'new') => {
        setForm(
            target === 'new'
                ? { type: 'phone', value: '', purpose: '', is_primary: false }
                : {
                      type: target.type,
                      value: target.value,
                      purpose: target.purpose ?? '',
                      is_primary: target.is_primary,
                  },
        );
        setFormError('');
        setEditing(target);
    };

    const save = async () => {
        setSaving(true);

        try {
            if (editing === 'new') {
                await apiJson(base, {
                    method: 'POST',
                    body: JSON.stringify(form),
                });
                toast.success('Kontak ditambahkan.');
            } else if (editing) {
                await apiJson(`${base}/${editing.id}`, {
                    method: 'PUT',
                    body: JSON.stringify(form),
                });
                toast.success('Kontak disimpan.');
            }

            setEditing(null);
            list.reload();
        } catch (caught) {
            setFormError(errorText(caught, 'Kontak belum dapat disimpan.'));
        } finally {
            setSaving(false);
        }
    };

    const remove = async (contact: Contact) => {
        try {
            await apiRequest(`${base}/${contact.id}`, { method: 'DELETE' });
            toast.success('Kontak dihapus.');
            list.reload();
        } catch (caught) {
            toast.error(errorText(caught, 'Kontak belum dapat dihapus.'));
        }
    };

    const makePrimary = async (contact: Contact) => {
        try {
            await apiJson(`${base}/${contact.id}`, {
                method: 'PUT',
                body: JSON.stringify({
                    type: contact.type,
                    value: contact.value,
                    purpose: contact.purpose ?? '',
                    is_primary: true,
                }),
            });
            toast.success('Kontak utama diganti.');
            list.reload();
        } catch (caught) {
            toast.error(errorText(caught, 'Kontak utama belum dapat diganti.'));
        }
    };

    if (list.loading) {
        return (
            <p className="text-sm text-muted-foreground">
                Memuat informasi kontak…
            </p>
        );
    }

    return (
        <div className="space-y-3">
            {list.error && (
                <p className="text-sm text-destructive">{list.error}</p>
            )}
            <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <p className="text-sm text-muted-foreground">
                    Kontak utama tiap jenis (telepon, WhatsApp, email, faks,
                    laman) tampil pada kop dokumen. Kontak lain disimpan untuk
                    keperluan internal.
                </p>
                {canManage && (
                    <Button
                        type="button"
                        size="sm"
                        className="shrink-0"
                        onClick={() => open('new')}
                    >
                        <Plus className="mr-1.5 size-3.5" />
                        Tambah kontak
                    </Button>
                )}
            </div>

            {list.items.length === 0 ? (
                <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                    Belum ada kontak. Telepon dan email pada kop dokumen kosong
                    sampai ada kontak utama.
                </p>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Keterangan</TableHead>
                                <TableHead>Jenis</TableHead>
                                <TableHead>Nomor atau alamat</TableHead>
                                <TableHead>Utama</TableHead>
                                {canManage && (
                                    <TableHead className="w-28 text-right">
                                        Aksi
                                    </TableHead>
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {list.items.map((contact) => (
                                <TableRow key={contact.id}>
                                    <TableCell className="text-muted-foreground">
                                        {contact.purpose || '—'}
                                    </TableCell>
                                    <TableCell>
                                        {TYPE_LABEL[contact.type] ??
                                            contact.type}
                                    </TableCell>
                                    <TableCell className="font-medium">
                                        {contact.value}
                                    </TableCell>
                                    <TableCell>
                                        {contact.is_primary ? (
                                            <Badge>Utama</Badge>
                                        ) : canManage ? (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="h-7 px-2 text-xs"
                                                onClick={() =>
                                                    void makePrimary(contact)
                                                }
                                            >
                                                <Star className="mr-1 size-3" />
                                                Jadikan utama
                                            </Button>
                                        ) : (
                                            '—'
                                        )}
                                    </TableCell>
                                    {canManage && (
                                        <TableCell className="text-right">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Ubah ${contact.value}`}
                                                onClick={() => open(contact)}
                                            >
                                                <Pencil className="size-4" />
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Hapus ${contact.value}`}
                                                onClick={() =>
                                                    void remove(contact)
                                                }
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}

            <Dialog
                open={editing !== null}
                onOpenChange={(isOpen) => !isOpen && setEditing(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {editing === 'new' ? 'Kontak baru' : 'Ubah kontak'}
                        </DialogTitle>
                        <DialogDescription>
                            Satu kontak utama per jenis; yang utama tampil pada
                            kop dokumen.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        onSubmit={(event) => {
                            // Dialog dirender lewat portal di dalam form organisasi; React
                            // tetap meneruskan submit ke form induk bila tidak dihentikan.
                            event.preventDefault();
                            event.stopPropagation();
                            void save();
                        }}
                    >
                        <DialogBody className="space-y-4 py-3">
                            <FieldGroup>
                                <Field>
                                    <NativeSelect
                                        label="Jenis"
                                        value={form.type}
                                        onChange={(event) =>
                                            setForm((current) => ({
                                                ...current,
                                                type: event.target.value,
                                            }))
                                        }
                                    >
                                        {(list.meta?.types ?? []).map(
                                            (type) => (
                                                <NativeSelectOption
                                                    key={type}
                                                    value={type}
                                                >
                                                    {TYPE_LABEL[type] ?? type}
                                                </NativeSelectOption>
                                            ),
                                        )}
                                    </NativeSelect>
                                </Field>
                                <Field>
                                    <Input
                                        label="Nomor atau alamat"
                                        placeholder={
                                            TYPE_PLACEHOLDER[form.type]
                                        }
                                        value={form.value}
                                        required
                                        onChange={(event) =>
                                            setForm((current) => ({
                                                ...current,
                                                value: event.target.value,
                                            }))
                                        }
                                    />
                                </Field>
                                <Field>
                                    <Input
                                        label="Keterangan"
                                        placeholder="Resepsionis, bagian penjualan"
                                        value={form.purpose}
                                        onChange={(event) =>
                                            setForm((current) => ({
                                                ...current,
                                                purpose: event.target.value,
                                            }))
                                        }
                                    />
                                </Field>
                                <Field>
                                    <label className="flex items-center gap-3 text-sm">
                                        <Switch
                                            checked={form.is_primary}
                                            onCheckedChange={(checked) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    is_primary: checked,
                                                }))
                                            }
                                        />
                                        Jadikan kontak utama untuk jenis ini
                                    </label>
                                    <FieldDescription>
                                        Kontak pertama dari suatu jenis otomatis
                                        menjadi utama.
                                    </FieldDescription>
                                </Field>
                            </FieldGroup>
                            {formError && (
                                <p className="text-sm text-destructive">
                                    {formError}
                                </p>
                            )}
                        </DialogBody>
                        <DialogFooter>
                            <DialogAction type="submit" disabled={saving}>
                                {saving ? 'Menyimpan…' : 'Simpan kontak'}
                            </DialogAction>
                            <DialogCancel />
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
