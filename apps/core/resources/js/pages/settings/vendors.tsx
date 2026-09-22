import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import { Select } from '@apperp/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@apperp/ui/sheet';
import { Switch } from '@apperp/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Head, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import { apiJson, CoreApiError, errorText } from '@/lib/core-api';
import type { BreadcrumbItem } from '@/types/navigation';

type PartyType = 'organization' | 'person';
type Status = 'active' | 'inactive';
type Vendor = {
    id: string;
    number: string;
    name: string;
    party_id: string;
    party_type: PartyType;
    legal_entity_id: string;
    tax_number: string | null;
    status: Status;
    updated_at: string | null;
};
type LegalEntity = { id: string; name: string; company_code: string | null };
type PartyOption = { id: string; name: string; type: PartyType };
type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};
type Props = {
    canManage: boolean;
    filters: { q: string; legal_entity: string | null; status: Status | null };
    legalEntities: LegalEntity[];
    vendors: Paginated<Vendor>;
    manualNumbers: boolean;
};

const PARTY_TYPE_LABEL: Record<PartyType, string> = {
    organization: 'Organisasi',
    person: 'Perorangan',
};

function Filters({
    filters,
    legalEntities,
}: Pick<Props, 'filters' | 'legalEntities'>) {
    const [q, setQ] = useState(filters.q);
    const go = (next: Partial<Props['filters']>) =>
        router.get(
            '/settings/vendors',
            { ...filters, q, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <form
            className="flex flex-col gap-3 sm:flex-row sm:items-end"
            onSubmit={(event) => {
                event.preventDefault();
                go({ q });
            }}
        >
            <div className="relative w-full sm:max-w-xs">
                <Search className="absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                <Input
                    aria-label="Cari nomor, nama, atau NPWP"
                    placeholder="Cari nomor, nama, atau NPWP"
                    className="pl-8"
                    value={q}
                    onChange={(event) => setQ(event.target.value)}
                />
            </div>
            <div className="w-full sm:w-56">
                <NativeSelect
                    label="Entitas legal"
                    value={filters.legal_entity ?? ''}
                    onChange={(event) =>
                        go({ legal_entity: event.target.value || null })
                    }
                >
                    <NativeSelectOption value="">
                        Semua entitas legal
                    </NativeSelectOption>
                    {legalEntities.map((entity) => (
                        <NativeSelectOption key={entity.id} value={entity.id}>
                            {entity.name}
                        </NativeSelectOption>
                    ))}
                </NativeSelect>
            </div>
            <div className="w-full sm:w-40">
                <NativeSelect
                    label="Status"
                    value={filters.status ?? ''}
                    onChange={(event) =>
                        go({
                            status: (event.target.value ||
                                null) as Status | null,
                        })
                    }
                >
                    <NativeSelectOption value="">Semua</NativeSelectOption>
                    <NativeSelectOption value="active">
                        Aktif
                    </NativeSelectOption>
                    <NativeSelectOption value="inactive">
                        Nonaktif
                    </NativeSelectOption>
                </NativeSelect>
            </div>
            <Button type="submit" variant="outline">
                Cari
            </Button>
        </form>
    );
}

type Form = {
    legal_entity_id: string;
    party_source: 'new' | 'existing';
    party_id: string | null;
    party_name: string;
    party_type: PartyType;
    number: string;
    name: string;
    tax_number: string;
    active: boolean;
};

function toForm(vendor: Vendor | null, legalEntities: LegalEntity[]): Form {
    return {
        legal_entity_id:
            vendor?.legal_entity_id ??
            (legalEntities.length === 1 ? legalEntities[0].id : ''),
        party_source: 'new',
        party_id: null,
        party_name: '',
        party_type: 'organization',
        number: '',
        name: vendor?.name ?? '',
        tax_number: vendor?.tax_number ?? '',
        // Vendor baru langsung aktif supaya dapat dipilih di dokumen tanpa langkah kedua.
        active: vendor ? vendor.status === 'active' : true,
    };
}

function newKey(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function VendorSheet({
    vendor,
    legalEntities,
    manualNumbers,
    onClose,
}: {
    vendor: Vendor | null;
    legalEntities: LegalEntity[];
    manualNumbers: boolean;
    onClose: () => void;
}) {
    const contentRef = useRef<HTMLDivElement>(null);
    // Satu kunci per form yang dibuka: klik ganda atau kiriman ulang tidak membuat vendor kedua.
    const [idempotencyKey] = useState(newKey);
    const [form, setForm] = useState<Form>(() => toForm(vendor, legalEntities));
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);
    const [parties, setParties] = useState<PartyOption[]>([]);
    const [partyQuery, setPartyQuery] = useState('');
    const [partyError, setPartyError] = useState('');
    const set = <K extends keyof Form>(key: K, value: Form[K]) =>
        setForm((current) => ({ ...current, [key]: value }));
    const error = (key: string) => errors[key]?.[0];
    const legalEntityName =
        legalEntities.find((entity) => entity.id === form.legal_entity_id)
            ?.name ?? '—';

    useEffect(() => {
        if (vendor || form.party_source !== 'existing') {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            apiJson<{ data: PartyOption[] }>(
                `/api/v1/vendors/party-options?q=${encodeURIComponent(partyQuery)}`,
                { signal: controller.signal },
            )
                .then((result) => {
                    setParties(result.data);
                    setPartyError('');
                })
                .catch((caught) => {
                    if (!controller.signal.aborted) {
                        setPartyError(
                            errorText(
                                caught,
                                'Buku alamat belum dapat dimuat.',
                            ),
                        );
                    }
                });
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [vendor, form.party_source, partyQuery]);

    const save = async () => {
        setSaving(true);
        setErrors({});

        try {
            const result = await apiJson<{ data: Vendor }>(
                vendor ? `/api/v1/vendors/${vendor.id}` : '/api/v1/vendors',
                vendor
                    ? {
                          method: 'PATCH',
                          body: JSON.stringify({
                              name: form.name,
                              tax_number: form.tax_number || null,
                              status: form.active ? 'active' : 'inactive',
                          }),
                      }
                    : {
                          method: 'POST',
                          headers: { 'Idempotency-Key': idempotencyKey },
                          body: JSON.stringify({
                              legal_entity_id: form.legal_entity_id,
                              party_id:
                                  form.party_source === 'existing'
                                      ? form.party_id
                                      : null,
                              party_name:
                                  form.party_source === 'new'
                                      ? form.party_name
                                      : null,
                              party_type:
                                  form.party_source === 'new'
                                      ? form.party_type
                                      : null,
                              number: form.number.trim() || null,
                              tax_number: form.tax_number || null,
                              status: form.active ? 'active' : 'inactive',
                          }),
                      },
            );
            toast.success(
                vendor
                    ? `Vendor ${result.data.number} disimpan.`
                    : `Vendor ${result.data.number} dibuat untuk ${legalEntityName}.`,
            );
            router.reload({ only: ['vendors'] });
            onClose();
        } catch (caught) {
            if (caught instanceof CoreApiError) {
                setErrors(caught.errors);
            }

            toast.error(errorText(caught, 'Vendor belum disimpan.'));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent
                ref={contentRef}
                side="right"
                className="w-full gap-0 p-0 sm:max-w-xl"
            >
                <SheetHeader className="border-b px-6 py-5 pr-12">
                    <SheetTitle>
                        {vendor
                            ? `Ubah vendor ${vendor.number}`
                            : 'Vendor baru'}
                    </SheetTitle>
                    <SheetDescription>
                        {vendor
                            ? `Entitas legal ${legalEntityName}. Nomor dan entitas legal tidak dapat diubah.`
                            : 'Vendor dibuat per entitas legal. Pihak yang sama dapat menjadi vendor di entitas legal lain dengan nomor tersendiri.'}
                    </SheetDescription>
                </SheetHeader>
                <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                    <FieldGroup>
                        {vendor ? (
                            <>
                                <Field>
                                    <Input
                                        label="Nomor vendor"
                                        value={vendor.number}
                                        readOnly
                                        className="font-mono"
                                    />
                                </Field>
                                <Field data-invalid={Boolean(error('name'))}>
                                    <Input
                                        label="Nama"
                                        required
                                        maxLength={200}
                                        value={form.name}
                                        onChange={(event) =>
                                            set('name', event.target.value)
                                        }
                                    />
                                    <FieldDescription>
                                        Nama milik pihak di buku alamat.
                                        Mengubahnya mengubah nama pihak ini di
                                        semua entitas legal.
                                    </FieldDescription>
                                    <FieldError>{error('name')}</FieldError>
                                </Field>
                            </>
                        ) : (
                            <>
                                <Field
                                    data-invalid={Boolean(
                                        error('legal_entity_id'),
                                    )}
                                >
                                    <Select
                                        label="Entitas legal"
                                        required
                                        items={legalEntities.map((entity) => ({
                                            value: entity.id,
                                            label: entity.name,
                                        }))}
                                        value={form.legal_entity_id || null}
                                        onValueChange={(value) =>
                                            set('legal_entity_id', value ?? '')
                                        }
                                        placeholder="Pilih entitas legal"
                                        portalContainer={contentRef}
                                    />
                                    <FieldError>
                                        {error('legal_entity_id')}
                                    </FieldError>
                                </Field>
                                <Field>
                                    <NativeSelect
                                        label="Pihak"
                                        value={form.party_source}
                                        onChange={(event) =>
                                            set(
                                                'party_source',
                                                event.target.value as
                                                    'new' | 'existing',
                                            )
                                        }
                                    >
                                        <NativeSelectOption value="new">
                                            Pihak baru
                                        </NativeSelectOption>
                                        <NativeSelectOption value="existing">
                                            Sudah ada di buku alamat
                                        </NativeSelectOption>
                                    </NativeSelect>
                                    <FieldDescription>
                                        Pilih yang sudah ada bila pemasok ini
                                        sudah tercatat, misalnya sebagai vendor
                                        di entitas legal lain, supaya namanya
                                        tidak tercatat dua kali.
                                    </FieldDescription>
                                </Field>
                                {form.party_source === 'new' ? (
                                    <>
                                        <Field
                                            data-invalid={Boolean(
                                                error('party_name'),
                                            )}
                                        >
                                            <Input
                                                label="Nama vendor"
                                                required
                                                maxLength={200}
                                                placeholder="Contoh: PT Sarana Medika"
                                                value={form.party_name}
                                                onChange={(event) =>
                                                    set(
                                                        'party_name',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <FieldError>
                                                {error('party_name')}
                                            </FieldError>
                                        </Field>
                                        <Field>
                                            <NativeSelect
                                                label="Jenis pihak"
                                                value={form.party_type}
                                                onChange={(event) =>
                                                    set(
                                                        'party_type',
                                                        event.target
                                                            .value as PartyType,
                                                    )
                                                }
                                            >
                                                <NativeSelectOption value="organization">
                                                    {
                                                        PARTY_TYPE_LABEL.organization
                                                    }
                                                </NativeSelectOption>
                                                <NativeSelectOption value="person">
                                                    {PARTY_TYPE_LABEL.person}
                                                </NativeSelectOption>
                                            </NativeSelect>
                                        </Field>
                                    </>
                                ) : (
                                    <Field
                                        data-invalid={Boolean(
                                            error('party_id') || partyError,
                                        )}
                                    >
                                        <Select
                                            label="Pihak di buku alamat"
                                            required
                                            items={parties.map((party) => ({
                                                value: party.id,
                                                label: `${party.name} · ${PARTY_TYPE_LABEL[party.type]}`,
                                            }))}
                                            value={form.party_id}
                                            onValueChange={(value) =>
                                                set('party_id', value)
                                            }
                                            onSearchChange={setPartyQuery}
                                            placeholder="Pilih pihak"
                                            searchPlaceholder="Cari nama pihak"
                                            emptyMessage="Tidak ada pihak yang cocok."
                                            portalContainer={contentRef}
                                        />
                                        {partyError && (
                                            <FieldDescription className="text-destructive">
                                                {partyError}
                                            </FieldDescription>
                                        )}
                                        <FieldError>
                                            {error('party_id')}
                                        </FieldError>
                                    </Field>
                                )}
                                {manualNumbers && (
                                    <Field
                                        data-invalid={Boolean(error('number'))}
                                    >
                                        <Input
                                            label="Nomor vendor"
                                            maxLength={40}
                                            placeholder="Kosongkan untuk nomor otomatis"
                                            className="font-mono"
                                            value={form.number}
                                            onChange={(event) =>
                                                set(
                                                    'number',
                                                    event.target.value.toUpperCase(),
                                                )
                                            }
                                        />
                                        <FieldDescription>
                                            Isi hanya saat memindahkan pemasok
                                            dari sistem lama. Nomor harus
                                            mengikuti format di Nomor dokumen
                                            (bawaan VND-000001) dan tidak dapat
                                            diubah sesudah disimpan.
                                        </FieldDescription>
                                        <FieldError>
                                            {error('number')}
                                        </FieldError>
                                    </Field>
                                )}
                            </>
                        )}
                        <Field data-invalid={Boolean(error('tax_number'))}>
                            <Input
                                label="NPWP"
                                maxLength={32}
                                inputMode="numeric"
                                value={form.tax_number}
                                onChange={(event) =>
                                    set('tax_number', event.target.value)
                                }
                            />
                            <FieldError>{error('tax_number')}</FieldError>
                        </Field>
                        <Field orientation="horizontal">
                            <Switch
                                id="vendor-active"
                                checked={form.active}
                                onCheckedChange={(checked) =>
                                    set('active', checked)
                                }
                            />
                            <FieldLabel htmlFor="vendor-active">
                                Aktif
                            </FieldLabel>
                        </Field>
                        <FieldDescription>
                            Vendor nonaktif tidak muncul di pilihan dokumen
                            baru. Dokumen dan posting lama tetap menunjuknya.
                        </FieldDescription>
                    </FieldGroup>
                </div>
                <SheetFooter className="border-t px-6 py-4 sm:flex-row sm:justify-end">
                    <Button variant="outline" type="button" onClick={onClose}>
                        Batal
                    </Button>
                    <Button type="button" disabled={saving} onClick={save}>
                        {vendor ? 'Simpan' : 'Buat vendor'}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}

export default function Vendors({
    canManage,
    filters,
    legalEntities,
    vendors,
    manualNumbers,
}: Props) {
    // `undefined` = form tertutup, `null` = vendor baru.
    const [editing, setEditing] = useState<Vendor | null | undefined>(
        undefined,
    );
    const legalEntityName = (id: string) =>
        legalEntities.find((entity) => entity.id === id)?.name ?? id;
    const filtered = Boolean(
        filters.q || filters.legal_entity || filters.status,
    );

    return (
        <>
            <Head title="Vendor" />
            <main className="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-6 p-6">
                <Heading
                    title="Vendor"
                    description="Pemasok per entitas legal. Nomor vendor ikut di setiap posting ke aplikasi finance."
                />
                <Card>
                    <CardHeader>
                        <CardTitle>Daftar vendor</CardTitle>
                        <CardDescription>
                            Nama, alamat, dan kontak vendor dicatat di buku
                            alamat. Yang dicatat di sini nomor, NPWP, dan status
                            vendor di tiap entitas legal.
                        </CardDescription>
                        {canManage && (
                            <CardAction>
                                <ActionButton
                                    action="create"
                                    size="sm"
                                    disabled={legalEntities.length === 0}
                                    onClick={() => setEditing(null)}
                                >
                                    Tambah vendor
                                </ActionButton>
                            </CardAction>
                        )}
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <Filters
                            key={JSON.stringify(filters)}
                            filters={filters}
                            legalEntities={legalEntities}
                        />
                        {vendors.data.length === 0 ? (
                            <Empty className="py-12">
                                <EmptyHeader>
                                    <EmptyTitle>Belum ada vendor</EmptyTitle>
                                    <EmptyDescription>
                                        {filtered
                                            ? 'Tidak ada vendor yang cocok dengan saringan ini.'
                                            : legalEntities.length === 0
                                              ? 'Buat entitas legal di Organisasi lebih dulu; vendor selalu milik satu entitas legal.'
                                              : 'Tambahkan pemasok yang asetnya akan diterima dan diposting ke aplikasi finance.'}
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <div className="overflow-x-auto rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Nomor</TableHead>
                                            <TableHead>Nama</TableHead>
                                            <TableHead>Entitas legal</TableHead>
                                            <TableHead>NPWP</TableHead>
                                            <TableHead>Status</TableHead>
                                            {canManage && (
                                                <TableHead className="w-28 text-right">
                                                    Aksi
                                                </TableHead>
                                            )}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {vendors.data.map((vendor) => (
                                            <TableRow key={vendor.id}>
                                                <TableCell className="font-mono">
                                                    {vendor.number}
                                                </TableCell>
                                                <TableCell>
                                                    {vendor.name}
                                                    <span className="block text-xs text-muted-foreground">
                                                        {
                                                            PARTY_TYPE_LABEL[
                                                                vendor
                                                                    .party_type
                                                            ]
                                                        }
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    {legalEntityName(
                                                        vendor.legal_entity_id,
                                                    )}
                                                </TableCell>
                                                <TableCell className="font-mono text-muted-foreground">
                                                    {vendor.tax_number ?? '—'}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant={
                                                            vendor.status ===
                                                            'active'
                                                                ? 'secondary'
                                                                : 'outline'
                                                        }
                                                    >
                                                        {vendor.status ===
                                                        'active'
                                                            ? 'Aktif'
                                                            : 'Nonaktif'}
                                                    </Badge>
                                                </TableCell>
                                                {canManage && (
                                                    <TableCell className="text-right">
                                                        <ActionButton
                                                            action="edit"
                                                            size="sm"
                                                            onClick={() =>
                                                                setEditing(
                                                                    vendor,
                                                                )
                                                            }
                                                        >
                                                            Ubah
                                                        </ActionButton>
                                                    </TableCell>
                                                )}
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                    {vendors.last_page > 1 && (
                        <CardFooter className="flex items-center justify-between gap-3 text-sm text-muted-foreground">
                            <span>
                                {vendors.from}–{vendors.to} dari {vendors.total}{' '}
                                vendor
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!vendors.prev_page_url}
                                    onClick={() =>
                                        vendors.prev_page_url &&
                                        router.visit(vendors.prev_page_url, {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    Sebelumnya
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!vendors.next_page_url}
                                    onClick={() =>
                                        vendors.next_page_url &&
                                        router.visit(vendors.next_page_url, {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    Berikutnya
                                </Button>
                            </div>
                        </CardFooter>
                    )}
                </Card>
            </main>
            {editing !== undefined && (
                <VendorSheet
                    key={editing?.id ?? 'baru'}
                    vendor={editing}
                    legalEntities={legalEntities}
                    manualNumbers={manualNumbers}
                    onClose={() => setEditing(undefined)}
                />
            )}
        </>
    );
}

Vendors.layout = {
    breadcrumbs: [
        { title: 'Vendor', href: '/settings/vendors' },
    ] satisfies BreadcrumbItem[],
};
