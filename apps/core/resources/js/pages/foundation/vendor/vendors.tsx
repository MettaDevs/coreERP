import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import { DataTable, DataTableText } from '@apperp/ui/data-table';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@apperp/ui/dialog';
import { Field, FieldError, FieldGroup, FieldLabel } from '@apperp/ui/field';
import { InlineLookup } from '@apperp/ui/inline-lookup';
import type { LookupLoader } from '@apperp/ui/inline-lookup';
import { Input } from '@apperp/ui/input';
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Switch } from '@apperp/ui/switch';
import { Head, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { loadLookupItems } from '@/lib/lookup-loader';
import type { ReactNode } from 'react';
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
import { CreateVendorPartyDialog } from './create-vendor-party-dialog';
import SupplierImport from './supplier-import';
import { VendorContextPanel, VendorHint } from './vendor-fact-box';

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
type PartyOption = {
    id: string;
    name: string;
    type: PartyType;
    vendor_accounts?: {
        id: string;
        number: string;
        legal_entity_id: string;
        company: string;
        status: Status;
    }[];
};
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
    automaticNumbers: boolean;
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
                    label="Perusahaan"
                    value={filters.legal_entity ?? ''}
                    onChange={(event) =>
                        go({ legal_entity: event.target.value || null })
                    }
                >
                    <NativeSelectOption value="">
                        Semua perusahaan
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
        // Vendor baru langsung aktif supaya dapat dipilih di dokumen tanpa langkah kedua.
        active: vendor ? vendor.status === 'active' : true,
    };
}

function newKey(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function VendorCard({
    vendor,
    legalEntities,
    automaticNumbers,
    onClose,
    initialParty,
    canReadAddressBook,
    canManageAddressBook,
    onCreated,
    onOpenExisting,
}: {
    vendor: Vendor | null;
    legalEntities: LegalEntity[];
    automaticNumbers: boolean;
    onClose: () => void;
    initialParty: PartyOption | null;
    canReadAddressBook: boolean;
    canManageAddressBook: boolean;
    onCreated: (vendor: Vendor) => void;
    onOpenExisting: (vendor: Vendor) => void;
}) {
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
    const [partyError, setPartyError] = useState('');
    const [creatingParty, setCreatingParty] = useState(false);
    const [resolvingExisting, setResolvingExisting] = useState(false);
    const loadCompanies = useCallback<LookupLoader<LegalEntity>>(
        (request) =>
            loadLookupItems('/api/v1/vendors/company-options', request),
        [],
    );
    const loadParties = useCallback<LookupLoader<PartyOption>>(
        async (request) => {
            const result = await loadLookupItems<PartyOption>(
                '/api/v1/vendors/party-options',
                request,
            );
            if (!request.signal.aborted)
                setParties((current) => {
                    const unique = new Map(
                        current.map((party) => [party.id, party]),
                    );
                    result.items.forEach((party) =>
                        unique.set(party.id, party),
                    );
                    return [...unique.values()];
                });
            return result;
        },
        [],
    );
    const set = <K extends keyof Form>(key: K, value: Form[K]) =>
        setForm((current) => ({ ...current, [key]: value }));
    const error = (key: string) => errors[key]?.[0];
    const legalEntityName =
        legalEntities.find((entity) => entity.id === form.legal_entity_id)
            ?.name ?? '—';

    useEffect(() => {
        if (
            vendor ||
            form.party_source !== 'new' ||
            (form.party_source === 'new' && form.party_name.trim().length < 2)
        ) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            apiJson<{ data: PartyOption[] }>(
                `/api/v1/vendors/party-options?q=${encodeURIComponent(form.party_source === 'new' ? form.party_name : '')}`,
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
    }, [vendor, form.party_source, form.party_name, initialParty]);

    useEffect(() => {
        if (
            vendor ||
            form.party_source !== 'existing' ||
            !form.party_id ||
            !form.legal_entity_id
        ) {
            setResolvingExisting(false);
            return;
        }
        const controller = new AbortController();
        setResolvingExisting(true);
        apiJson<{ data: Vendor[] }>(
            `/api/v1/vendors/lookup?legal_entity_id=${form.legal_entity_id}&party_id=${form.party_id}`,
            { signal: controller.signal },
        )
            .then((result) => {
                if (!controller.signal.aborted && result.data[0])
                    onOpenExisting(result.data[0]);
            })
            .catch((caught) => {
                if (!controller.signal.aborted)
                    setSaveError(
                        errorText(caught, 'Akun vendor belum dapat diperiksa.'),
                    );
            })
            .finally(() => {
                if (!controller.signal.aborted) setResolvingExisting(false);
            });
        return () => controller.abort();
    }, [
        vendor,
        form.party_source,
        form.party_id,
        form.legal_entity_id,
        onOpenExisting,
    ]);

    const save = async () => {
        if (saving || resolvingExisting) {
            return;
        }
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
                              number: automaticNumbers
                                  ? null
                                  : form.number.trim() || null,
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

    const existingParty =
        parties.find((party) => party.id === form.party_id) ??
        (initialParty?.id === form.party_id ? initialParty : null);
    const duplicate =
        form.party_source === 'new' &&
        form.party_name.trim().length >= 2 &&
        parties.some((party) =>
            party.name
                .toLocaleLowerCase('id-ID')
                .includes(form.party_name.trim().toLocaleLowerCase('id-ID')),
        );

    return (
        <div className="flex h-full min-h-0 flex-col">
            <RecordActionBar
                title={
                    vendor ? `${vendor.number} · ${vendor.name}` : 'Vendor baru'
                }
                trailing={
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={saving || resolvingExisting}
                            onClick={onClose}
                        >
                            Batal
                        </Button>
                        <Button
                            type="submit"
                            form="vendor-form"
                            disabled={saving || resolvingExisting}
                        >
                            {saving
                                ? 'Menyimpan…'
                                : vendor
                                  ? 'Simpan'
                                  : 'Buat vendor'}
                        </Button>
                    </>
                }
            />
            <VendorContextPanel
                key={vendor?.id ?? 'new'}
                vendor={vendor}
                company={legalEntityName}
            >
                <main className="min-h-0 flex-1 overflow-y-auto p-4">
                    {saveError && (
                        <p
                            role="alert"
                            className="mb-4 text-sm text-destructive"
                        >
                            {saveError}
                        </p>
                    )}
                    <form
                        id="vendor-form"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void save();
                        }}
                    >
                        <CollapsibleSectionGroup
                            defaultValue={
                                vendor ? ['general', 'addresses'] : ['general']
                            }
                        >
                            <CollapsibleSection
                                value="general"
                                title="General"
                                summary={`${legalEntityName} · ${vendor?.number ?? (automaticNumbers ? 'Nomor otomatis' : form.number || 'Nomor manual')}`}
                            >
                                <FieldGroup className="grid gap-4 sm:grid-cols-2">
                                    <VendorField
                                        label="Nomor vendor"
                                        error={error('number')}
                                        hint={
                                            automaticNumbers
                                                ? 'Nomor dibuat otomatis saat vendor disimpan dan unik di seluruh perusahaan.'
                                                : 'Isi nomor vendor yang belum dipakai di perusahaan mana pun.'
                                        }
                                    >
                                        <Input
                                            label="Nomor vendor"
                                            value={
                                                vendor?.number ??
                                                (automaticNumbers
                                                    ? 'Otomatis saat disimpan'
                                                    : form.number)
                                            }
                                            readOnly={
                                                !!vendor || automaticNumbers
                                            }
                                            required={
                                                !vendor && !automaticNumbers
                                            }
                                            onChange={(event) =>
                                                set(
                                                    'number',
                                                    event.target.value.toUpperCase(),
                                                )
                                            }
                                            aria-invalid={!!error('number')}
                                        />
                                    </VendorField>
                                    <VendorField
                                        label="Perusahaan"
                                        error={error('legal_entity_id')}
                                    >
                                        {vendor ? (
                                            <Input
                                                label="Perusahaan"
                                                value={legalEntityName}
                                                readOnly
                                            />
                                        ) : (
                                            <InlineLookup
                                                label="Perusahaan"
                                                required
                                                loadItems={loadCompanies}
                                                value={
                                                    legalEntities.find(
                                                        (entity) =>
                                                            entity.id ===
                                                            form.legal_entity_id,
                                                    ) ?? null
                                                }
                                                getItemValue={(entity) =>
                                                    entity.id
                                                }
                                                getItemLabel={(entity) =>
                                                    entity.name
                                                }
                                                getSearchText={(entity) =>
                                                    `${entity.company_code ?? ''} ${entity.name}`
                                                }
                                                columns={[
                                                    {
                                                        id: 'code',
                                                        header: 'Kode perusahaan',
                                                        cell: (entity) =>
                                                            entity.company_code ??
                                                            '—',
                                                        width: 140,
                                                    },
                                                    {
                                                        id: 'name',
                                                        header: 'Nama',
                                                        cell: (entity) =>
                                                            entity.name,
                                                        sortValue: (entity) =>
                                                            entity.name,
                                                        width: 320,
                                                    },
                                                ]}
                                                onValueChange={(entity) =>
                                                    set(
                                                        'legal_entity_id',
                                                        entity.id,
                                                    )
                                                }
                                            />
                                        )}
                                    </VendorField>
                                    {!vendor && (
                                        <VendorField
                                            label="Identitas vendor"
                                            hint="Gunakan identitas yang sudah ada jika vendor sudah tercatat, termasuk sebagai vendor perusahaan lain. Nama, alamat, dan kontak akan dipakai bersama."
                                        >
                                            <NativeSelect
                                                label="Identitas vendor"
                                                value={form.party_source}
                                                onChange={(event) =>
                                                    setForm((current) => ({
                                                        ...current,
                                                        party_source: event
                                                            .target
                                                            .value as Form['party_source'],
                                                        party_id: null,
                                                        party_name: '',
                                                    }))
                                                }
                                            >
                                                <NativeSelectOption value="new">
                                                    Buat identitas baru
                                                </NativeSelectOption>
                                                <NativeSelectOption value="existing">
                                                    Gunakan identitas yang sudah
                                                    ada
                                                </NativeSelectOption>
                                            </NativeSelect>
                                        </VendorField>
                                    )}
                                    {!vendor &&
                                    form.party_source === 'existing' ? (
                                        <VendorField
                                            label="Nama vendor"
                                            error={error('party_id')}
                                        >
                                            <InlineLookup
                                                onCreate={
                                                    canManageAddressBook
                                                        ? () =>
                                                              setCreatingParty(
                                                                  true,
                                                              )
                                                        : undefined
                                                }
                                                label="Nama vendor"
                                                required
                                                popupClassName="w-[58rem]"
                                                loadItems={loadParties}
                                                value={existingParty}
                                                getItemValue={(party) =>
                                                    party.id
                                                }
                                                getItemLabel={(party) =>
                                                    party.name
                                                }
                                                getSearchText={(party) =>
                                                    `${party.name} ${PARTY_TYPE_LABEL[party.type]} ${(party.vendor_accounts ?? []).map((account) => `${account.number} ${account.company}`).join(' ')}`
                                                }
                                                columns={[
                                                    {
                                                        id: 'name',
                                                        header: 'Nama',
                                                        cell: (party) =>
                                                            party.name,
                                                        sortValue: (party) =>
                                                            party.name,
                                                        width: 320,
                                                    },
                                                    {
                                                        id: 'vendor_accounts',
                                                        header: 'Nomor vendor',
                                                        cell: (party) =>
                                                            party.vendor_accounts
                                                                ?.map(
                                                                    (account) =>
                                                                        `${account.number}${account.status === 'inactive' ? ' (Nonaktif)' : ''}`,
                                                                )
                                                                .join(', ') ||
                                                            'Belum menjadi vendor',
                                                        width: 200,
                                                    },
                                                    {
                                                        id: 'companies',
                                                        header: 'Perusahaan',
                                                        cell: (party) =>
                                                            party.vendor_accounts
                                                                ?.map(
                                                                    (account) =>
                                                                        account.company,
                                                                )
                                                                .join(', ') ||
                                                            '—',
                                                        width: 260,
                                                    },
                                                    {
                                                        id: 'type',
                                                        header: 'Jenis',
                                                        cell: (party) =>
                                                            PARTY_TYPE_LABEL[
                                                                party.type
                                                            ],
                                                        width: 140,
                                                    },
                                                ]}
                                                onValueChange={(party) =>
                                                    setForm((current) => ({
                                                        ...current,
                                                        party_id: party.id,
                                                        party_name: party.name,
                                                        party_type: party.type,
                                                    }))
                                                }
                                            />
                                            {partyError && (
                                                <p
                                                    role="alert"
                                                    className="text-sm text-destructive"
                                                >
                                                    {partyError}
                                                </p>
                                            )}
                                        </VendorField>
                                    ) : (
                                        <VendorField
                                            label="Nama vendor"
                                            error={error(
                                                vendor ? 'name' : 'party_name',
                                            )}
                                            hint={
                                                vendor
                                                    ? 'Nama tersimpan di buku alamat bersama. Perubahan nama juga berlaku pada pihak dan akun vendor lain yang memakai identitas yang sama.'
                                                    : undefined
                                            }
                                        >
                                            <Input
                                                label={
                                                    (vendor?.party_type ??
                                                        form.party_type) ===
                                                    'person'
                                                        ? 'Nama lengkap'
                                                        : 'Nama organisasi'
                                                }
                                                required
                                                maxLength={200}
                                                value={
                                                    vendor
                                                        ? form.name
                                                        : form.party_name
                                                }
                                                onChange={(event) =>
                                                    set(
                                                        vendor
                                                            ? 'name'
                                                            : 'party_name',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            {duplicate && (
                                                <p className="text-sm text-warning">
                                                    Ada nama serupa di buku
                                                    alamat. Periksa melalui
                                                    pilihan identitas yang sudah
                                                    ada sebelum membuat vendor
                                                    baru.
                                                </p>
                                            )}
                                        </VendorField>
                                    )}
                                    {!vendor && form.party_source === 'new' && (
                                        <VendorField
                                            label="Jenis Vendor"
                                            error={error('party_type')}
                                        >
                                            <NativeSelect
                                                label="Jenis Vendor"
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
                                                    Organisasi
                                                </NativeSelectOption>
                                                <NativeSelectOption value="person">
                                                    Perorangan
                                                </NativeSelectOption>
                                            </NativeSelect>
                                        </VendorField>
                                    )}
                                    {vendor && (
                                        <VendorField
                                            label="NPWP"
                                            hint="NPWP mengikuti identitas vendor di buku alamat global. Ubah dari buku alamat agar semua akun yang terkait tetap sama."
                                        >
                                            <Input
                                                label="NPWP"
                                                value={vendor.tax_number ?? ''}
                                                readOnly
                                            />
                                        </VendorField>
                                    )}
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
                                        <VendorHint label="status aktif">
                                            Vendor nonaktif tidak dapat dipilih
                                            pada dokumen baru. Dokumen dan
                                            posting lama tetap menyimpan
                                            referensinya.
                                        </VendorHint>
                                    </Field>
                                </FieldGroup>
                            </CollapsibleSection>
                            {/* Prioritas pembayaran disembunyikan sampai ada integrasi/proses pembayaran yang memakainya. */}
                            {vendor && (
                                <VendorAddressTabs
                                    vendor={vendor}
                                    canRead={canReadAddressBook}
                                    canManage={canManageAddressBook}
                                />
                            )}
                        </CollapsibleSectionGroup>
                    </form>
                </main>
            </VendorContextPanel>
            {creatingParty && (
                <CreateVendorPartyDialog
                    onClose={() => setCreatingParty(false)}
                    onCreated={(party) => {
                        setCreatingParty(false);
                        if (party.status !== 'active') {
                            toast.message(
                                'Pihak tersimpan sebagai nonaktif. Aktifkan di buku alamat sebelum dipilih sebagai vendor.',
                            );
                            return;
                        }
                        const option: PartyOption = {
                            id: party.id,
                            name: party.name,
                            type:
                                party.type === 'person'
                                    ? 'person'
                                    : 'organization',
                        };
                        setParties((current) => [
                            option,
                            ...current.filter(
                                (existing) => existing.id !== option.id,
                            ),
                        ]);
                        setForm((current) => ({
                            ...current,
                            party_source: 'existing',
                            party_id: option.id,
                            party_name: option.name,
                            party_type: option.type,
                        }));
                        setPartyError('');
                    }}
                />
            )}
        </div>
    );
}

function VendorField({
    label,
    hint,
    error,
    children,
}: {
    label: string;
    hint?: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <Field data-invalid={Boolean(error)}>
            <div className="flex min-w-0 items-center gap-2">
                <div className="min-w-0 flex-1">{children}</div>
                {hint && <VendorHint label={label}>{hint}</VendorHint>}
            </div>
            <FieldError>{error}</FieldError>
        </Field>
    );
}

function VendorAddressTabs({
    vendor,
    canRead,
    canManage = false,
}: {
    vendor: Vendor;
    canRead: boolean;
    canManage?: boolean;
}) {
    if (!canRead) {
        return null;
    }
    return (
        <>
            <CollapsibleSection
                value="addresses"
                title="Alamat dan kontak"
                summary="Identitas bersama di buku alamat"
            >
                <div className="space-y-4">
                    <div className="flex items-center gap-2">
                        <h3 className="text-sm font-medium">Alamat</h3>
                        <VendorHint label="alamat dan kontak vendor">
                            Alamat dan kontak disimpan pada pihak yang sama di
                            buku alamat. Perubahan dari sini juga berlaku pada
                            akun vendor di perusahaan lain dan peran lain yang
                            memakai pihak tersebut.
                        </VendorHint>
                    </div>
                    <OrganizationAddressesSection
                        organizationId={vendor.party_id}
                        resource="parties"
                        countryCode="ID"
                        canManage={canManage}
                    />
                    <div className="border-t pt-4">
                        <h3 className="mb-3 text-sm font-medium">
                            Informasi kontak
                        </h3>
                        <OrganizationContactsSection
                            organizationId={vendor.party_id}
                            resource="parties"
                            canManage={canManage}
                        />
                    </div>
                </div>
            </CollapsibleSection>
        </>
    );
}

function VendorReadCard({
    vendor,
    company,
    canReadAddressBook,
    canManageAddressBook,
    onClose,
    onEdit,
}: {
    vendor: Vendor;
    company: string;
    canReadAddressBook: boolean;
    canManageAddressBook: boolean;
    onClose: () => void;
    onEdit?: () => void;
}) {
    return (
        <div className="flex h-full min-h-0 flex-col">
            <RecordActionBar
                title={`${vendor.number} · ${vendor.name}`}
                trailing={
                    <>
                        <Button variant="outline" onClick={onClose}>
                            Kembali ke daftar
                        </Button>
                        {onEdit && (
                            <ActionButton action="edit" onClick={onEdit}>
                                Ubah
                            </ActionButton>
                        )}
                    </>
                }
            />
            <VendorContextPanel
                key={vendor.id}
                vendor={vendor}
                company={company}
            >
                <main className="min-h-0 flex-1 overflow-y-auto p-4">
                    <CollapsibleSectionGroup
                        defaultValue={['general', 'addresses']}
                    >
                        <CollapsibleSection
                            value="general"
                            title="General"
                            summary={company}
                        >
                            <div className="grid gap-4 sm:grid-cols-2">
                                {[
                                    ['Nomor vendor', vendor.number],
                                    ['Perusahaan', company],
                                    [
                                        vendor.party_type === 'person'
                                            ? 'Nama lengkap'
                                            : 'Nama organisasi',
                                        vendor.name,
                                    ],
                                    [
                                        'Jenis Vendor',
                                        PARTY_TYPE_LABEL[vendor.party_type],
                                    ],
                                    ['NPWP', vendor.tax_number ?? '—'],
                                    [
                                        'Status',
                                        vendor.status === 'active'
                                            ? 'Aktif'
                                            : 'Nonaktif',
                                    ],
                                ].map(([label, value]) => (
                                    <Input
                                        key={label}
                                        label={label}
                                        value={value}
                                        readOnly
                                    />
                                ))}
                            </div>
                        </CollapsibleSection>
                        <VendorAddressTabs
                            vendor={vendor}
                            canRead={canReadAddressBook}
                            canManage={canManageAddressBook}
                        />
                        {/* Prioritas pembayaran disembunyikan sampai ada integrasi/proses pembayaran yang memakainya. */}
                    </CollapsibleSectionGroup>
                </main>
            </VendorContextPanel>
        </div>
    );
}

export default function Vendors({
    canManage,
    filters,
    legalEntities,
    vendors,
    automaticNumbers,
    initialParty,
    canReadAddressBook,
    canManageAddressBook,
}: Props) {
    const [editing, setEditing] = useState<Vendor | null | undefined>(
        initialParty && canManage ? null : undefined,
    );
    // Party dari GAB hanya mengisi pembukaan awal, bukan setiap klik Tambah vendor.
    const [draftParty, setDraftParty] = useState(initialParty);
    const [selectedVendorId, setSelectedVendorId] = useState<string | null>(
        null,
    );
    const selectedVendor =
        vendors.data.find((vendor) => vendor.id === selectedVendorId) ?? null;
    const [viewing, setViewing] = useState<Vendor | null>(null);
    const openExisting = useCallback((account: Vendor) => {
        setEditing(undefined);
        setViewing(account);
    }, []);
    const [importing, setImporting] = useState(false);
    const legalEntityName = (id: string) =>
        legalEntities.find((entity) => entity.id === id)?.name ?? id;

    if (editing !== undefined) {
        return (
            <>
                <Head title="Vendor" />
                <VendorCard
                    key={editing?.id ?? draftParty?.id ?? 'new'}
                    vendor={editing}
                    legalEntities={legalEntities}
                    automaticNumbers={automaticNumbers}
                    initialParty={draftParty}
                    canReadAddressBook={canReadAddressBook}
                    canManageAddressBook={canManageAddressBook}
                    onClose={() => setEditing(undefined)}
                    onCreated={setEditing}
                    onOpenExisting={openExisting}
                />
            </>
        );
    }

    if (viewing) {
        return (
            <>
                <Head title="Vendor" />
                <VendorReadCard
                    vendor={viewing}
                    company={legalEntityName(viewing.legal_entity_id)}
                    canReadAddressBook={canReadAddressBook}
                    canManageAddressBook={canManageAddressBook}
                    onClose={() => setViewing(null)}
                    onEdit={
                        canManage
                            ? () => {
                                  setViewing(null);
                                  setEditing(viewing);
                              }
                            : undefined
                    }
                />
            </>
        );
    }

    return (
        <>
            <Head title="Vendor" />
            <VendorContextPanel
                vendor={selectedVendor}
                company={
                    selectedVendor
                        ? legalEntityName(selectedVendor.legal_entity_id)
                        : '—'
                }
                draft={false}
            >
                <ListPageLayout
                    header={
                        <div className="flex flex-wrap items-center justify-between gap-3 p-4">
                            <div className="flex items-center gap-2">
                                <Heading title="Vendor" />
                                <VendorHint label="vendor">
                                    Akun vendor per perusahaan. Identitas,
                                    alamat, dan kontak memakai buku alamat
                                    bersama.
                                </VendorHint>
                            </div>
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
                                        onClick={() => {
                                            setDraftParty(null);
                                            setViewing(null);
                                            setEditing(null);
                                        }}
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
                        rowActivation="single-click"
                        activeRowKey={selectedVendorId ?? undefined}
                        onRowClick={(vendor) => setSelectedVendorId(vendor.id)}
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
                                    <Button
                                        type="button"
                                        variant="link"
                                        className="h-auto p-0"
                                        onClick={(event) => {
                                            event.stopPropagation();
                                            setViewing(vendor);
                                        }}
                                    >
                                        {vendor.number}
                                    </Button>
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
                                        {legalEntityName(
                                            vendor.legal_entity_id,
                                        )}
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
            </VendorContextPanel>
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
