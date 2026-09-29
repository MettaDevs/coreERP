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
import { Checkbox } from '@apperp/ui/checkbox';
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
import {
    Field,
    FieldDescription,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@apperp/ui/field';
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
import { Archive, Pencil, Plus, Star } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { apiJson, apiRequest, errorText } from '@/lib/core-api';

/**
 * Alamat dan informasi kontak satu organisasi, dibaca dari buku alamat party Core
 * (padanan Addresses dan Contact information pada legal entity Dynamics 365).
 *
 * Alamat utama dan kontak utama per jenis yang dipakai kop dokumen; identitas cetak
 * tidak menyimpan salinannya. Karena itu kedua daftar ini adalah satu-satunya tempat
 * mengubah alamat atau telepon yang tercetak.
 *
 * Satu alamat adalah tautan organisasi ke sebuah tempat, dan tempat dapat dipakai beberapa
 * pihak: mengubah jalan atau kota tempat bersama mengubahnya bagi semua pemakainya.
 * Mengarsipkan alamat hanya melepas tautannya.
 */

type Location = {
    id: string;
    location_id: string;
    name: string;
    purposes: string[];
    is_primary: boolean;
    shared_with: number;
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

type Purpose = { code: string; name: string };

type SharableLocation = { id: string; name: string; formatted: string };

type LocationForm = {
    location_id: string;
    name: string;
    purposes: string[];
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

const emptyLocation = (countryCode: string): LocationForm => ({
    location_id: '',
    name: '',
    purposes: ['business'],
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
    location_id: '',
    name: location.name,
    purposes: location.purposes,
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
        { purposes: Purpose[]; countries: Country[] }
    >(base, 'Alamat belum dapat dimuat.');
    const [editing, setEditing] = useState<Location | 'new' | null>(null);
    const [form, setForm] = useState<LocationForm>(emptyLocation(countryCode));
    const [saving, setSaving] = useState(false);
    const [formError, setFormError] = useState('');
    const [sharable, setSharable] = useState<SharableLocation[]>([]);

    // Tempat yang sudah dipakai pihak lain, untuk dipilih alih-alih mengetik alamat yang sama lagi.
    useEffect(() => {
        if (editing !== 'new' || !canManage) {
            return;
        }

        let cancelled = false;
        apiJson<{ data: SharableLocation[] }>(
            `/api/v1/organizations/${organizationId}/sharable-locations`,
        )
            .then((result) => {
                if (!cancelled) {
                    setSharable(result.data);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setSharable([]);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [editing, canManage, organizationId]);

    const linkedLocationIds = new Set(
        list.items.map((location) => location.location_id),
    );
    const sharableChoices = sharable.filter(
        (location) => !linkedLocationIds.has(location.id),
    );
    const chosenShared =
        editing === 'new'
            ? sharableChoices.find(
                  (location) => location.id === form.location_id,
              )
            : undefined;
    const purposeNames = (codes: string[]) =>
        codes
            .map(
                (code) =>
                    list.meta?.purposes.find((purpose) => purpose.code === code)
                        ?.name ?? code,
            )
            .join(', ');

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
                    body: JSON.stringify(
                        chosenShared
                            ? {
                                  location_id: chosenShared.id,
                                  purposes: form.purposes,
                                  is_primary: form.is_primary,
                              }
                            : { ...form, location_id: null },
                    ),
                });
                toast.success('Alamat ditambahkan.');
            } else if (editing) {
                await apiJson(`${base}/${editing.id}`, {
                    method: 'PUT',
                    body: JSON.stringify({ ...form, location_id: null }),
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
            toast.success('Alamat diarsipkan.');
            list.reload();
        } catch (caught) {
            toast.error(errorText(caught, 'Alamat belum dapat diarsipkan.'));
        }
    };

    const makePrimary = async (location: Location) => {
        try {
            await apiJson(`${base}/${location.id}`, {
                method: 'PUT',
                body: JSON.stringify({
                    ...locationToForm(location),
                    location_id: null,
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
        key: Exclude<
            keyof LocationForm,
            'is_primary' | 'purposes' | 'location_id'
        >,
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
                                        {location.shared_with > 0 && (
                                            <span className="block text-xs font-normal text-muted-foreground">
                                                Dipakai juga oleh{' '}
                                                {location.shared_with} pihak
                                                lain
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="whitespace-pre-line text-muted-foreground">
                                        {location.formatted || '—'}
                                    </TableCell>
                                    <TableCell>
                                        {purposeNames(location.purposes)}
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
                                                        aria-label={`Arsipkan ${location.name}`}
                                                    >
                                                        <Archive className="size-4" />
                                                    </Button>
                                                </AlertDialogTrigger>
                                                <AlertDialogContent>
                                                    <AlertDialogHeader>
                                                        <AlertDialogTitle>
                                                            Arsipkan alamat{' '}
                                                            {location.name}?
                                                        </AlertDialogTitle>
                                                        <AlertDialogDescription>
                                                            {location.is_primary
                                                                ? 'Ini alamat utama. Alamat tertua yang tersisa akan menjadi utama dan tampil pada kop dokumen.'
                                                                : 'Alamat ini tidak lagi tersedia untuk dokumen dan pengiriman organisasi ini.'}
                                                            {location.shared_with >
                                                                0 &&
                                                                ' Pihak lain yang memakai alamat yang sama tidak terpengaruh.'}
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
                                                            Arsipkan
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
                            {editing === 'new' &&
                                sharableChoices.length > 0 && (
                                    <Field>
                                        <NativeSelect
                                            label="Sumber alamat"
                                            value={form.location_id}
                                            onChange={(event) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    location_id:
                                                        event.target.value,
                                                }))
                                            }
                                        >
                                            <NativeSelectOption value="">
                                                Tulis alamat baru
                                            </NativeSelectOption>
                                            {sharableChoices.map((location) => (
                                                <NativeSelectOption
                                                    key={location.id}
                                                    value={location.id}
                                                >
                                                    {location.name}
                                                </NativeSelectOption>
                                            ))}
                                        </NativeSelect>
                                        <FieldDescription>
                                            {chosenShared
                                                ? `${chosenShared.formatted.replaceAll('\n', ', ')}. Alamat ini dipakai bersama: perubahan jalan atau kota berlaku bagi semua pemakainya.`
                                                : 'Pilih alamat yang sudah dipakai organisasi lain bila tempatnya sama, supaya tidak diketik dua kali.'}
                                        </FieldDescription>
                                    </Field>
                                )}
                            {editing !== null &&
                                editing !== 'new' &&
                                editing.shared_with > 0 && (
                                    <p className="rounded-md border border-warning/40 bg-warning/10 p-3 text-sm">
                                        Alamat ini dipakai juga oleh{' '}
                                        {editing.shared_with} pihak lain.
                                        Perubahan nama, jalan, kota, atau kode
                                        pos berlaku juga bagi mereka.
                                    </p>
                                )}
                            {!chosenShared && (
                                <FieldGroup className="grid gap-4 md:grid-cols-2">
                                    {text(
                                        'name',
                                        'Nama atau keterangan',
                                        'Kantor pusat',
                                    )}
                                </FieldGroup>
                            )}
                            <FieldSet>
                                <FieldLegend>Kegunaan</FieldLegend>
                                <div className="flex flex-wrap gap-x-5 gap-y-2">
                                    {(list.meta?.purposes ?? []).map(
                                        (purpose) => (
                                            <Field
                                                key={purpose.code}
                                                orientation="horizontal"
                                                className="w-auto"
                                            >
                                                <Checkbox
                                                    id={`purpose-${purpose.code}`}
                                                    checked={form.purposes.includes(
                                                        purpose.code,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        setForm((current) => ({
                                                            ...current,
                                                            purposes: checked
                                                                ? [
                                                                      ...current.purposes,
                                                                      purpose.code,
                                                                  ]
                                                                : current.purposes.filter(
                                                                      (code) =>
                                                                          code !==
                                                                          purpose.code,
                                                                  ),
                                                        }))
                                                    }
                                                />
                                                <FieldLabel
                                                    htmlFor={`purpose-${purpose.code}`}
                                                >
                                                    {purpose.name}
                                                </FieldLabel>
                                            </Field>
                                        ),
                                    )}
                                </div>
                                <FieldDescription>
                                    Satu alamat boleh punya beberapa kegunaan,
                                    misalnya kantor sekaligus penagihan.
                                </FieldDescription>
                            </FieldSet>
                            {!chosenShared && (
                                <>
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
                                        {text(
                                            'city',
                                            'Kota / kabupaten',
                                            'Badung',
                                        )}
                                        {text('province', 'Provinsi', 'Bali')}
                                        {text(
                                            'postal_code',
                                            'Kode pos',
                                            '80351',
                                        )}
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
                                                {(
                                                    list.meta?.countries ?? []
                                                ).map((country) => (
                                                    <NativeSelectOption
                                                        key={country.code}
                                                        value={country.code}
                                                    >
                                                        {country.name}
                                                    </NativeSelectOption>
                                                ))}
                                            </NativeSelect>
                                            <FieldDescription>
                                                Nama negara ikut tercetak hanya
                                                untuk alamat di luar Indonesia.
                                            </FieldDescription>
                                        </Field>
                                        {text('postbox', 'PO Box')}
                                    </FieldGroup>
                                </>
                            )}
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
    address_id: string | null;
    address_name: string | null;
};

type ContactForm = {
    type: string;
    value: string;
    purpose: string;
    is_primary: boolean;
    address_id: string;
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
    // Kontak menempel ke alamat; daftar alamat dibaca saat form dibuka.
    const [addresses, setAddresses] = useState<Location[]>([]);
    const [editing, setEditing] = useState<Contact | 'new' | null>(null);
    const [form, setForm] = useState<ContactForm>({
        type: 'phone',
        value: '',
        purpose: '',
        is_primary: false,
        address_id: '',
    });

    useEffect(() => {
        if (editing === null) {
            return;
        }

        let cancelled = false;
        apiJson<{ data: Location[] }>(
            `/api/v1/organizations/${organizationId}/locations`,
        )
            .then((result) => {
                if (cancelled) {
                    return;
                }

                setAddresses(result.data);

                if (editing === 'new') {
                    const primary = result.data.find(
                        (address) => address.is_primary,
                    );
                    setForm((current) => ({
                        ...current,
                        address_id: primary?.id ?? '',
                    }));
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setAddresses([]);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [editing, organizationId]);
    const [saving, setSaving] = useState(false);
    const [formError, setFormError] = useState('');

    const open = (target: Contact | 'new') => {
        setForm(
            target === 'new'
                ? {
                      type: 'phone',
                      value: '',
                      purpose: '',
                      is_primary: false,
                      address_id: '',
                  }
                : {
                      type: target.type,
                      value: target.value,
                      purpose: target.purpose ?? '',
                      is_primary: target.is_primary,
                      address_id: target.address_id ?? '',
                  },
        );
        setFormError('');
        setEditing(target);
    };

    const save = async () => {
        setSaving(true);

        try {
            const body = JSON.stringify({
                ...form,
                address_id: form.address_id || null,
            });

            if (editing === 'new') {
                await apiJson(base, { method: 'POST', body });
                toast.success('Kontak ditambahkan.');
            } else if (editing) {
                await apiJson(`${base}/${editing.id}`, { method: 'PUT', body });
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
            toast.success('Kontak diarsipkan.');
            list.reload();
        } catch (caught) {
            toast.error(errorText(caught, 'Kontak belum dapat diarsipkan.'));
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
                                <TableHead>Alamat</TableHead>
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
                                    <TableCell className="text-muted-foreground">
                                        {contact.address_name ?? '—'}
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
                                                aria-label={`Arsipkan ${contact.value}`}
                                                onClick={() =>
                                                    void remove(contact)
                                                }
                                            >
                                                <Archive className="size-4" />
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
                            Satu kontak utama per jenis di setiap alamat; kontak
                            utama di alamat utama yang tampil pada kop dokumen.
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
                                {addresses.length > 0 && (
                                    <Field>
                                        <NativeSelect
                                            label="Alamat"
                                            value={form.address_id}
                                            onChange={(event) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    address_id:
                                                        event.target.value,
                                                }))
                                            }
                                        >
                                            {addresses.map((address) => (
                                                <NativeSelectOption
                                                    key={address.id}
                                                    value={address.id}
                                                >
                                                    {address.name}
                                                    {address.is_primary
                                                        ? ' (utama)'
                                                        : ''}
                                                </NativeSelectOption>
                                            ))}
                                        </NativeSelect>
                                        <FieldDescription>
                                            Telepon cabang dipasang ke alamat
                                            cabangnya. Kop dokumen membaca
                                            kontak di alamat utama lebih dulu.
                                        </FieldDescription>
                                    </Field>
                                )}
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
