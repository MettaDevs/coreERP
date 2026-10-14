import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import { DataTable, DataTableText } from '@apperp/ui/data-table';
import { Button } from '@apperp/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@apperp/ui/dialog';
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
import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import {
    apiJson,
    CoreApiError,
    errorText,
    toastSaveError,
} from '@/lib/core-api';
import type { BreadcrumbItem } from '@/types/navigation';
import {
    OrganizationAddressesSection,
    OrganizationContactsSection,
} from '../../platform/organization/components/address-book-section';
import SupplierImport from './supplier-import';

type PartyType = 'organization' | 'person';
type Status = 'active' | 'inactive';
type Vendor = {
    id: string;
    version: number;
    number: string;
    name: string;
    party_id: string;
    party_type: PartyType;
    legal_entity_id: string;
    tax_number: string | null;
    payment_priority: number | null;
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
    initialParty: PartyOption | null;
    canReadAddressBook: boolean;
    canManageAddressBook: boolean;
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
    payment_priority: string;
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
        payment_priority: vendor?.payment_priority?.toString() ?? '',
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
    initialParty,
    canReadAddressBook,
    canManageAddressBook,
    onCreated,
}: {
    vendor: Vendor | null;
    legalEntities: LegalEntity[];
    manualNumbers: boolean;
    onClose: () => void;
    initialParty: PartyOption | null;
    canReadAddressBook: boolean;
    canManageAddressBook: boolean;
    onCreated: (vendor: Vendor) => void;
}) {
    const contentRef = useRef<HTMLDivElement>(null);
    // Satu kunci per form yang dibuka: klik ganda atau kiriman ulang tidak membuat vendor kedua.
    const [idempotencyKey] = useState(newKey);
    const [form, setForm] = useState<Form>(() => ({
        ...toForm(vendor, legalEntities),
        ...(!vendor && initialParty
            ? {
                  party_source: 'existing' as const,
                  party_id: initialParty.id,
                  party_name: initialParty.name,
              }
            : {}),
    }));
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);
    const [saveError, setSaveError] = useState('');
    const [parties, setParties] = useState<PartyOption[]>(
        initialParty ? [initialParty] : [],
    );
    const [partyQuery, setPartyQuery] = useState('');
    const [partyError, setPartyError] = useState('');
    const set = <K extends keyof Form>(key: K, value: Form[K]) =>
        setForm((current) => ({ ...current, [key]: value }));
    const error = (key: string) => errors[key]?.[0];
    const legalEntityName =
        legalEntities.find((entity) => entity.id === form.legal_entity_id)
            ?.name ?? '—';

    useEffect(() => {
        if (
            vendor ||
            (form.party_source === 'new' && form.party_name.trim().length < 2)
        ) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            apiJson<{ data: PartyOption[] }>(
                `/api/v1/vendors/party-options?q=${encodeURIComponent(form.party_source === 'new' ? form.party_name : partyQuery)}`,
                { signal: controller.signal },
            )
                .then((result) => {
                    setParties(
                        initialParty &&
                            !result.data.some(
                                (party) => party.id === initialParty.id,
                            )
                            ? [initialParty, ...result.data]
                            : result.data,
                    );
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
    }, [vendor, form.party_source, form.party_name, partyQuery, initialParty]);

    const save = async () => {
        setSaving(true);
        setErrors({});
        setSaveError('');

        try {
            const result = await apiJson<{ data: Vendor }>(
                vendor ? `/api/v1/vendors/${vendor.id}` : '/api/v1/vendors',
                vendor
                    ? {
                          method: 'PATCH',
                          body: JSON.stringify({
                              name: form.name,
                              tax_number: form.tax_number || null,
                              payment_priority: form.payment_priority
                                  ? Number(form.payment_priority)
                                  : null,
                              status: form.active ? 'active' : 'inactive',
                              version: vendor.version,
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
                              payment_priority: form.payment_priority
                                  ? Number(form.payment_priority)
                                  : null,
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
            if (vendor) {
                onClose();
            } else {
                onCreated(result.data);
            }
        } catch (caught) {
            setSaveError(errorText(caught, 'Vendor belum dapat disimpan.'));
            if (caught instanceof CoreApiError) {
                setErrors(caught.errors);
            }

            toastSaveError(caught, 'Vendor belum disimpan.');
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
                {saveError && (
                    <p role="alert" className="px-6 text-sm text-destructive">
                        {saveError}
                    </p>
                )}
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
                                                placeholder="Nama pemasok"
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
                                {form.party_source === 'new' &&
                                    parties.length > 0 &&
                                    form.party_name.trim().length >= 2 && (
                                        <Field>
                                            <FieldDescription>
                                                Ada pihak dengan nama yang
                                                mirip. Periksa sebelum membuat
                                                identitas baru.
                                            </FieldDescription>
                                            <Select
                                                label="Pakai pihak yang sudah ada"
                                                portalContainer={contentRef}
                                                items={parties.map((party) => ({
                                                    value: party.id,
                                                    label: party.name,
                                                }))}
                                                value={null}
                                                onValueChange={(value) => {
                                                    if (value)
                                                        setForm((current) => ({
                                                            ...current,
                                                            party_source:
                                                                'existing',
                                                            party_id: value,
                                                        }));
                                                }}
                                            />
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
                        <Field>
                            <Input
                                label="Prioritas pembayaran"
                                type="number"
                                min={1}
                                value={form.payment_priority}
                                onChange={(event) =>
                                    set('payment_priority', event.target.value)
                                }
                            />
                            <FieldDescription>
                                Kosongkan bila tidak diberi prioritas. Angka
                                kecil menunjukkan prioritas lebih tinggi;
                                pengisian ini tidak memproses pembayaran.
                            </FieldDescription>
                            <FieldError>{error('payment_priority')}</FieldError>
                        </Field>
                        <FieldDescription>
                            Vendor nonaktif tidak muncul di pilihan dokumen
                            baru. Dokumen dan posting lama tetap menunjuknya.
                        </FieldDescription>
                    </FieldGroup>
                </div>
                {vendor && canReadAddressBook && (
                    <div className="max-h-80 overflow-y-auto border-t px-6 py-4">
                        <Link
                            href={`/settings/global-address-book?party=${vendor.party_id}`}
                            className="text-sm text-primary underline"
                        >
                            Lihat identitas lengkap di buku alamat
                        </Link>
                        <OrganizationAddressesSection
                            organizationId={vendor.party_id}
                            resource="parties"
                            countryCode="ID"
                            canManage={canManageAddressBook}
                        />
                        <OrganizationContactsSection
                            organizationId={vendor.party_id}
                            resource="parties"
                            canManage={canManageAddressBook}
                        />
                    </div>
                )}
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

function VendorReadSheet({
    vendor,
    company,
    canReadAddressBook,
    onClose,
}: {
    vendor: Vendor;
    company: string;
    canReadAddressBook: boolean;
    onClose: () => void;
}) {
    return (
        <Sheet
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <SheetContent className="sm:max-w-2xl">
                <SheetHeader>
                    <SheetTitle>
                        {vendor.number} · {vendor.name}
                    </SheetTitle>
                    <SheetDescription>
                        Akun vendor di {company}. Nama, alamat, dan kontak
                        memakai identitas pihak yang sama.
                    </SheetDescription>
                </SheetHeader>
                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-4">
                    <dl className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                Perusahaan
                            </dt>
                            <dd>{company}</dd>
                        </div>
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                NPWP
                            </dt>
                            <dd>{vendor.tax_number ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                Jenis pihak
                            </dt>
                            <dd>{PARTY_TYPE_LABEL[vendor.party_type]}</dd>
                        </div>
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                Status
                            </dt>
                            <dd>
                                {vendor.status === 'active'
                                    ? 'Aktif'
                                    : 'Nonaktif'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                Prioritas pembayaran
                            </dt>
                            <dd>
                                {vendor.payment_priority ??
                                    'Tidak diberi prioritas'}
                            </dd>
                        </div>
                    </dl>
                    {canReadAddressBook && (
                        <>
                            <Link
                                href={`/settings/global-address-book?party=${vendor.party_id}`}
                                className="text-sm text-primary underline"
                            >
                                Lihat identitas lengkap di buku alamat
                            </Link>
                            <OrganizationAddressesSection
                                organizationId={vendor.party_id}
                                resource="parties"
                                countryCode="ID"
                                canManage={false}
                            />
                            <OrganizationContactsSection
                                organizationId={vendor.party_id}
                                resource="parties"
                                canManage={false}
                            />
                        </>
                    )}
                </div>
                <SheetFooter>
                    <Button variant="outline" onClick={onClose}>
                        Tutup
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
    initialParty,
    canReadAddressBook,
    canManageAddressBook,
}: Props) {
    const [editing, setEditing] = useState<Vendor | null | undefined>(
        initialParty && canManage ? null : undefined,
    );
    const [viewing, setViewing] = useState<Vendor | null>(null);
    const [importing, setImporting] = useState(false);
    const legalEntityName = (id: string) =>
        legalEntities.find((entity) => entity.id === id)?.name ?? id;
    return (
        <>
            <Head title="Vendor" />
            <ListPageLayout
                header={
                    <div className="flex flex-wrap items-center justify-between gap-3 p-4">
                        <Heading
                            title="Vendor"
                            description="Akun pemasok per perusahaan. Identitas, alamat, dan kontak memakai buku alamat bersama."
                        />
                        {canManage && (
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => setImporting(true)}
                                >
                                    Impor supplier CSV
                                </Button>
                                <ActionButton
                                    action="create"
                                    disabled={legalEntities.length === 0}
                                    onClick={() => setEditing(null)}
                                >
                                    Tambah vendor
                                </ActionButton>
                            </div>
                        )}
                    </div>
                }
                filters={
                    <div className="px-4 pb-4">
                        <Filters
                            key={JSON.stringify(filters)}
                            filters={filters}
                            legalEntities={legalEntities}
                        />
                    </div>
                }
                footer={
                    <div className="flex items-center justify-between gap-3 border-t p-3 text-sm">
                        <span>
                            {vendors.from ?? 0}–{vendors.to ?? 0} dari{' '}
                            {vendors.total} vendor
                        </span>
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={!vendors.prev_page_url}
                                onClick={() =>
                                    vendors.prev_page_url &&
                                    router.visit(vendors.prev_page_url)
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
                                    router.visit(vendors.next_page_url)
                                }
                            >
                                Berikutnya
                            </Button>
                        </div>
                    </div>
                }
            >
                <DataTable
                    data={vendors.data}
                    getRowKey={(vendor) => vendor.id}
                    getRowLabel={(vendor) => vendor.name}
                    showRowNumbers
                    actionsPlacement="inline"
                    actions={[
                        { id: 'view', label: 'Lihat vendor' },
                        ...(canManage
                            ? [{ id: 'edit', label: 'Ubah vendor' }]
                            : []),
                    ]}
                    onRowAction={(action, vendor) =>
                        action === 'edit'
                            ? setEditing(vendor)
                            : setViewing(vendor)
                    }
                    onRowClick={setViewing}
                    emptyMessage={
                        legalEntities.length === 0
                            ? 'Buat perusahaan di Organisasi lebih dulu sebelum menambahkan vendor.'
                            : 'Belum ada vendor yang sesuai. Tambahkan vendor atau ubah saringan.'
                    }
                    columns={[
                        {
                            id: 'number',
                            header: 'Nomor vendor',
                            width: 180,
                            cell: (vendor) => (
                                <DataTableText>{vendor.number}</DataTableText>
                            ),
                        },
                        {
                            id: 'name',
                            header: 'Nama',
                            width: 280,
                            cell: (vendor) => (
                                <DataTableText>{vendor.name}</DataTableText>
                            ),
                        },
                        {
                            id: 'company',
                            header: 'Perusahaan',
                            width: 220,
                            cell: (vendor) => (
                                <DataTableText>
                                    {legalEntityName(vendor.legal_entity_id)}
                                </DataTableText>
                            ),
                        },
                        {
                            id: 'tax',
                            header: 'NPWP',
                            width: 200,
                            cell: (vendor) => (
                                <DataTableText>
                                    {vendor.tax_number ?? '—'}
                                </DataTableText>
                            ),
                        },
                        {
                            id: 'type',
                            header: 'Jenis pihak',
                            width: 140,
                            cell: (vendor) => (
                                <DataTableText>
                                    {PARTY_TYPE_LABEL[vendor.party_type]}
                                </DataTableText>
                            ),
                        },
                        {
                            id: 'status',
                            header: 'Status',
                            width: 120,
                            cell: (vendor) => (
                                <Badge
                                    variant={
                                        vendor.status === 'active'
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                >
                                    {vendor.status === 'active'
                                        ? 'Aktif'
                                        : 'Nonaktif'}
                                </Badge>
                            ),
                        },
                    ]}
                />
            </ListPageLayout>
            {editing !== undefined && (
                <VendorSheet
                    key={editing?.id ?? initialParty?.id ?? 'new'}
                    vendor={editing}
                    legalEntities={legalEntities}
                    manualNumbers={manualNumbers}
                    onClose={() => setEditing(undefined)}
                    initialParty={initialParty}
                    canReadAddressBook={canReadAddressBook}
                    canManageAddressBook={canManageAddressBook}
                    onCreated={setEditing}
                />
            )}
            {viewing && (
                <VendorReadSheet
                    vendor={viewing}
                    company={legalEntityName(viewing.legal_entity_id)}
                    canReadAddressBook={canReadAddressBook}
                    onClose={() => setViewing(null)}
                />
            )}
            <Dialog open={importing} onOpenChange={setImporting}>
                <DialogContent size="wide">
                    <DialogHeader>
                        <DialogTitle>Impor supplier</DialogTitle>
                        <DialogDescription>
                            Gunakan CSV untuk pengisian awal. Periksa pratinjau
                            sebelum menerapkan.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="min-h-0 flex-1 overflow-y-auto p-4">
                        <SupplierImport legalEntities={legalEntities} />
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}

Vendors.layout = {
    breadcrumbs: [
        { title: 'Vendor', href: '/settings/vendors' },
    ] satisfies BreadcrumbItem[],
};
