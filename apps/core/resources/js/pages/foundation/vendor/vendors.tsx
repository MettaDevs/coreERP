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
import { FilterPane } from '@apperp/ui/filter-pane';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { InlineLookup } from '@apperp/ui/inline-lookup';
import type { LookupLoader } from '@apperp/ui/inline-lookup';
import { Input } from '@apperp/ui/input';
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import { usePageDialogRecord } from '@apperp/ui/page-dialog';
import { PageDialog, PageDialogContent } from '@apperp/ui/page-dialog';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Switch } from '@apperp/ui/switch';
import { useRecordNavigation } from '@apperp/ui/use-record-navigation';
import { useRecordTransition } from '@apperp/ui/use-record-transition';
import type { InfiniteScrollRef } from '@inertiajs/core';
import { Head, router, usePage } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import { useCallback, useEffect, useRef, useState, useId } from 'react';
import type { ReactNode, RefObject } from 'react';
import { toast } from 'sonner';
import { RecordListScroll } from '@/components/record-list-scroll';
import { useSaveFeedback } from '@/hooks/use-save-feedback';
import { apiJson, CoreApiError, errorText } from '@/lib/core-api';
import { loadLookupItems } from '@/lib/lookup-loader';
import type { Auth } from '@/types';
import type { BreadcrumbItem } from '@/types/navigation';
import {
    OrganizationAddressesSection,
    OrganizationContactsSection,
    usePartyDraftDetails,
} from '../../platform/organization/components/address-book-section';
import SupplierImport from './supplier-import';
import { useVendorListQuery } from './use-vendor-list-query';
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
    initialVendor: Vendor | null;
    canReadAddressBook: boolean;
    canManageAddressBook: boolean;
};

const PARTY_TYPE_LABEL: Record<PartyType, string> = {
    organization: 'Organisasi',
    person: 'Perorangan',
};

const vendorTitleHint =
    'Akun vendor per perusahaan. Identitas, alamat, dan kontak memakai buku alamat bersama.';

function Filters({
    filters,
    legalEntities,
    onChange,
}: Pick<Props, 'filters' | 'legalEntities'> & {
    onChange: (next: Partial<Props['filters']>) => void;
}) {
    return (
        <FormGrid columns={1}>
            <FormControl className="w-full">
                <NativeSelect
                    label="Perusahaan"
                    value={filters.legal_entity ?? ''}
                    onChange={(event) =>
                        onChange({ legal_entity: event.target.value || null })
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
            </FormControl>
            <FormControl className="w-full">
                <NativeSelect
                    label="Status"
                    value={filters.status ?? ''}
                    onChange={(event) =>
                        onChange({
                            status:
                                event.target.value === 'active' ||
                                event.target.value === 'inactive'
                                    ? event.target.value
                                    : null,
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
            </FormControl>
        </FormGrid>
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
    onSaved,
    onOpenExisting,
    portalContainer,
    onBusyChange,
    onDirtyChange,
    lockedLegalEntityId,
}: {
    vendor: Vendor | null;
    lockedLegalEntityId?: string;

    legalEntities: LegalEntity[];
    automaticNumbers: boolean;
    onClose: () => void;
    initialParty: PartyOption | null;
    canReadAddressBook: boolean;
    canManageAddressBook: boolean;
    onCreated: (vendor: Vendor) => void;
    onSaved: () => void;
    onOpenExisting: (vendor: Vendor) => void;
    portalContainer: RefObject<HTMLDivElement | null>;
    onBusyChange: (busy: boolean) => void;
    onDirtyChange: (dirty: boolean) => void;
}) {
    const [factBoxToolbar, setFactBoxToolbar] = useState<HTMLDivElement | null>(
        null,
    );
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
    const draftDetails = usePartyDraftDetails(
        !vendor && canManageAddressBook,
        !vendor && form.party_source === 'existing' ? form.party_id : null,
    );
    const [initialForm] = useState(form);
    const dirty =
        JSON.stringify(form) !== JSON.stringify(initialForm) ||
        draftDetails.value.locations.length > 0 ||
        draftDetails.value.contacts.length > 0;
    useEffect(() => {
        onDirtyChange(dirty);

        return () => onDirtyChange(false);
    }, [dirty, onDirtyChange]);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const {
        report: reportSaveError,
        clear: clearSaveError,
        summary: saveErrorSummary,
        scopeRef: saveScopeRef,
    } = useSaveFeedback<HTMLDivElement>();
    const [openSections, setOpenSections] = useState(
        vendor ? ['general', 'addresses'] : ['general'],
    );
    const [saving, setSaving] = useState(false);
    const detailTransition = useRecordTransition(
        draftDetails.value.locations.length > 0 ||
            draftDetails.value.contacts.length > 0,
        saving,
    );
    const changeParty = (next: Partial<Form>) =>
        detailTransition.request(() => {
            draftDetails.onChange({ locations: [], contacts: [] });
            setForm((current) => ({ ...current, ...next }));
        });
    const [saveError, setSaveError] = useState('');
    const [parties, setParties] = useState<PartyOption[]>(
        initialParty ? [initialParty] : [],
    );
    const [partyError, setPartyError] = useState('');
    const existingLookupKey =
        vendor ||
        form.party_source !== 'existing' ||
        !form.party_id ||
        !form.legal_entity_id
            ? null
            : JSON.stringify([form.legal_entity_id, form.party_id]);
    const [resolvedLookupKey, setResolvedLookupKey] = useState<string | null>(
        null,
    );
    const resolvingExisting =
        existingLookupKey !== null && resolvedLookupKey !== existingLookupKey;
    useEffect(() => {
        onBusyChange(saving || resolvingExisting);

        return () => onBusyChange(false);
    }, [saving, resolvingExisting, onBusyChange]);
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

            if (!request.signal.aborted) {
                setParties((current) => {
                    const unique = new Map(
                        current.map((party) => [party.id, party]),
                    );
                    result.items.forEach((party) =>
                        unique.set(party.id, party),
                    );

                    return [...unique.values()];
                });
            }

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
                    if (controller.signal.aborted) {
                        return;
                    }

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
        if (!existingLookupKey || existingLookupKey === resolvedLookupKey) {
            return;
        }

        const controller = new AbortController();
        apiJson<{ data: Vendor[] }>(
            `/api/v1/vendors/lookup?legal_entity_id=${form.legal_entity_id}&party_id=${form.party_id}`,
            { signal: controller.signal },
        )
            .then((result) => {
                if (!controller.signal.aborted && result.data[0]) {
                    onOpenExisting(result.data[0]);
                }
            })
            .catch((caught) => {
                if (!controller.signal.aborted) {
                    setSaveError(
                        errorText(caught, 'Akun vendor belum dapat diperiksa.'),
                    );
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setResolvedLookupKey(existingLookupKey);
                }
            });

        return () => controller.abort();
    }, [
        existingLookupKey,
        resolvedLookupKey,
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
        clearSaveError();

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
                              party_details: draftDetails.payload,
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

            if (vendor) {
                onSaved();
            } else {
                onCreated(result.data);
            }
        } catch (caught) {
            if (caught instanceof CoreApiError) {
                setErrors(caught.errors);
                setOpenSections(['general', 'addresses']);
            }

            reportSaveError(caught, 'Vendor belum disimpan.');
        } finally {
            setSaving(false);
        }
    };

    usePageDialogRecord({
        recordTitle: vendor
            ? [vendor.number, form.name].filter(Boolean).join(' · ')
            : form.party_name || 'Vendor baru',
        editing: true,
        recordUrl: vendor
            ? `/settings/vendors?vendor=${encodeURIComponent(vendor.id)}`
            : undefined,
    });
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
        <div ref={saveScopeRef} className="flex h-full min-h-0 flex-col">
            <RecordActionBar
                feedback={saveErrorSummary}
                trailing={
                    <div
                        ref={setFactBoxToolbar}
                        className="flex items-center gap-1"
                    />
                }
            >
                <ActionButton
                    action="save"
                    type="submit"
                    form="vendor-form"
                    disabled={saving || resolvingExisting}
                    pending={saving}
                />
                <ActionButton
                    action="cancel"
                    type="button"
                    disabled={saving || resolvingExisting}
                    onClick={onClose}
                />
            </RecordActionBar>
            <VendorContextPanel
                key={vendor?.id ?? 'new'}
                toolbarContainer={factBoxToolbar}
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
                        noValidate
                        id="vendor-form"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void save();
                        }}
                    >
                        <CollapsibleSectionGroup
                            value={openSections}
                            onValueChange={setOpenSections}
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
                                        field="number"
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
                                        field="legal_entity_id"
                                    >
                                        {vendor || lockedLegalEntityId ? (
                                            <Input
                                                label="Perusahaan"
                                                value={legalEntityName}
                                                readOnly
                                            />
                                        ) : (
                                            <InlineLookup
                                                portalContainer={
                                                    portalContainer
                                                }
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
                                                onChange={(event) => {
                                                    const source =
                                                        event.target.value;
                                                    changeParty({
                                                        party_source:
                                                            source ===
                                                            'existing'
                                                                ? 'existing'
                                                                : 'new',
                                                        party_id: null,
                                                        party_name: '',
                                                    });
                                                }}
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
                                            field="party_id"
                                        >
                                            <InlineLookup
                                                portalContainer={
                                                    portalContainer
                                                }
                                                onCreate={
                                                    canManageAddressBook
                                                        ? () =>
                                                              changeParty({
                                                                  party_source:
                                                                      'new',
                                                                  party_id:
                                                                      null,
                                                                  party_name:
                                                                      '',
                                                              })
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
                                                    changeParty({
                                                        party_id: party.id,
                                                        party_name: party.name,
                                                        party_type: party.type,
                                                    })
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
                                            field={
                                                vendor ? 'name' : 'party_name'
                                            }
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
                                            field="party_type"
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
                                            disabled={Boolean(
                                                !vendor && lockedLegalEntityId,
                                            )}
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
                            {(vendor ||
                                (form.party_source === 'new' &&
                                    canManageAddressBook) ||
                                (form.party_source === 'existing' &&
                                    form.party_id)) && (
                                <VendorAddressTabs
                                    vendor={vendor}
                                    partyId={
                                        form.party_source === 'existing'
                                            ? (form.party_id ?? undefined)
                                            : undefined
                                    }
                                    draft={
                                        !vendor && canManageAddressBook
                                            ? draftDetails
                                            : undefined
                                    }
                                    canRead={canReadAddressBook}
                                    canManage={canManageAddressBook}
                                />
                            )}
                        </CollapsibleSectionGroup>
                    </form>
                </main>
            </VendorContextPanel>
            {detailTransition.confirmation}
        </div>
    );
}

function VendorField({
    label,
    field,
    hint,
    error,
    children,
}: {
    label: string;
    field?: string;
    hint?: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <Field data-error-field={field} data-invalid={Boolean(error)}>
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
    partyId,
    draft,
    canRead,
    canManage = false,
}: {
    vendor?: Vendor | null;
    partyId?: string;
    draft?: ReturnType<typeof usePartyDraftDetails>;
    canRead: boolean;
    canManage?: boolean;
}) {
    if (!canRead && !draft) {
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
                    {draft?.existing && draft.existing.locations.length > 0 && (
                        <>
                            <h4 className="text-sm font-medium">
                                Alamat tersimpan
                            </h4>
                            <OrganizationAddressesSection
                                organizationId={partyId ?? ''}
                                resource="parties"
                                countryCode="ID"
                                canManage={false}
                                readOnlyData={{
                                    items: draft.existing.locations.map(
                                        (row) => ({
                                            ...row,
                                            is_primary:
                                                row.is_primary &&
                                                !draft.value.locations.some(
                                                    (location) =>
                                                        location.is_primary,
                                                ),
                                        }),
                                    ),
                                    meta: {
                                        purposes:
                                            draft.metadata?.locationPurposes ??
                                            [],
                                        countries:
                                            draft.metadata?.countries ?? [],
                                    },
                                }}
                            />
                        </>
                    )}
                    <OrganizationAddressesSection
                        organizationId={vendor?.party_id ?? partyId ?? ''}
                        draft={draft}
                        resource="parties"
                        countryCode="ID"
                        canManage={canManage}
                    />
                    <div className="border-t pt-4">
                        <h3 className="mb-3 text-sm font-medium">
                            Informasi kontak
                        </h3>
                        {draft?.existing &&
                            draft.existing.contacts.length > 0 && (
                                <>
                                    <h4 className="text-sm font-medium">
                                        Kontak tersimpan
                                    </h4>
                                    <OrganizationContactsSection
                                        organizationId={partyId ?? ''}
                                        resource="parties"
                                        canManage={false}
                                        readOnlyData={{
                                            items: draft.existing.contacts.map(
                                                (row) => ({
                                                    ...row,
                                                    is_primary:
                                                        row.is_primary &&
                                                        !draft.value.contacts.some(
                                                            (contact) =>
                                                                contact.type ===
                                                                    row.type &&
                                                                contact.is_primary,
                                                        ),
                                                }),
                                            ),
                                            meta: {
                                                types:
                                                    draft.metadata
                                                        ?.contactTypes ?? [],
                                            },
                                        }}
                                    />
                                </>
                            )}
                        <OrganizationContactsSection
                            organizationId={vendor?.party_id ?? partyId ?? ''}
                            draft={draft}
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
    const [factBoxToolbar, setFactBoxToolbar] = useState<HTMLDivElement | null>(
        null,
    );
    const embedded = usePageDialogRecord({
        recordTitle: [vendor.number, vendor.name].filter(Boolean).join(' · '),
        onEdit,
        editing: false,
        recordUrl: `/settings/vendors?vendor=${encodeURIComponent(vendor.id)}`,
    });

    return (
        <div className="flex h-full min-h-0 flex-col">
            <RecordActionBar
                trailing={
                    <div
                        ref={setFactBoxToolbar}
                        className="flex items-center gap-1"
                    />
                }
            >
                {!embedded && (
                    <>
                        <Button variant="outline" onClick={onClose}>
                            Kembali ke daftar
                        </Button>
                        {onEdit && (
                            <ActionButton action="edit" onClick={onEdit} />
                        )}
                    </>
                )}
            </RecordActionBar>
            <VendorContextPanel
                toolbarContainer={factBoxToolbar}
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

type VendorCreateContext = Pick<
    Props,
    | 'legalEntities'
    | 'automaticNumbers'
    | 'canReadAddressBook'
    | 'canManageAddressBook'
>;

/** Kartu vendor Core yang sama dapat dibuka dari lookup tanpa meninggalkan draft induk. */
export function VendorCreateCard({
    legalEntityId,
    onClose,
    onCreated,
}: {
    legalEntityId: string;
    onClose: () => void;
    onCreated: (vendor: Vendor) => void;
}) {
    const [context, setContext] = useState<VendorCreateContext | null>(null);
    const [error, setError] = useState('');
    const [reload, setReload] = useState(0);
    const [saving, setSaving] = useState(false);
    const [dirty, setDirty] = useState(false);
    const portal = useRef<HTMLDivElement>(null);
    const transition = useRecordTransition(dirty, saving);
    useEffect(() => {
        const request = new AbortController();
        apiJson<{ data: VendorCreateContext }>(
            `/api/v1/vendors/create-context?legal_entity_id=${encodeURIComponent(legalEntityId)}`,
            { signal: request.signal },
        )
            .then((result) => {
                if (!request.signal.aborted) {
                    setContext(result.data);
                    setError('');
                }
            })
            .catch((caught) => {
                if (!request.signal.aborted) {
                    setError(
                        errorText(caught, 'Form vendor belum dapat dimuat.'),
                    );
                }
            });

        return () => request.abort();
    }, [legalEntityId, reload]);

    return (
        <>
            <PageDialog
                open
                onOpenChange={(open) => {
                    if (!open) {
                        transition.request(onClose);
                    }
                }}
            >
                <PageDialogContent
                    ref={portal}
                    title="Vendor"
                    titleHint={vendorTitleHint}
                    busy={saving}
                >
                    {error && (
                        <div
                            role="alert"
                            className="p-4 text-sm text-destructive"
                        >
                            {error}
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setReload((value) => value + 1)}
                            >
                                Coba lagi
                            </Button>
                        </div>
                    )}
                    {!context && !error && (
                        <p
                            role="status"
                            className="p-4 text-sm text-muted-foreground"
                        >
                            Memuat form vendor…
                        </p>
                    )}
                    {context && (
                        <VendorCard
                            vendor={null}
                            lockedLegalEntityId={legalEntityId}
                            legalEntities={context.legalEntities}
                            automaticNumbers={context.automaticNumbers}
                            canReadAddressBook={context.canReadAddressBook}
                            canManageAddressBook={context.canManageAddressBook}
                            initialParty={null}
                            portalContainer={portal}
                            onBusyChange={setSaving}
                            onDirtyChange={setDirty}
                            onClose={() => transition.request(onClose)}
                            onCreated={onCreated}
                            onSaved={onClose}
                            onOpenExisting={onCreated}
                        />
                    )}
                </PageDialogContent>
            </PageDialog>
            {transition.confirmation}
        </>
    );
}

export default function Vendors({
    canManage,
    filters,
    legalEntities,
    vendors,
    automaticNumbers,
    initialParty,
    initialVendor,
    canReadAddressBook,
    canManageAddressBook,
}: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const [factBoxToolbar, setFactBoxToolbar] = useState<HTMLDivElement | null>(
        null,
    );
    const [filtersOpen, setFiltersOpen] = useState(false);
    const filterPanelId = useId();
    const [editing, setEditing] = useState<Vendor | null | undefined>(
        !initialVendor && initialParty && canManage ? null : undefined,
    );
    // Party dari GAB hanya mengisi pembukaan awal, bukan setiap klik Tambah vendor.
    const [draftParty, setDraftParty] = useState(
        initialVendor ? null : initialParty,
    );
    const [draftKey, setDraftKey] = useState(0);
    const [selectedVendorId, setSelectedVendorId] = useState<string | null>(
        null,
    );
    const selectedVendor =
        vendors.data.find((vendor) => vendor.id === selectedVendorId) ?? null;
    const [viewing, setViewing] = useState<Vendor | null>(initialVendor);
    const { query, onQueryChange, applyFilters } = useVendorListQuery(
        filters,
        editing !== undefined || viewing !== null,
    );
    const [vendorSaving, setVendorSaving] = useState(false);
    const [vendorDirty, setVendorDirty] = useState(false);
    const transition = useRecordTransition(vendorDirty, vendorSaving);
    const listRef = useRef<InfiniteScrollRef>(null);
    const [listLoading, setListLoading] = useState(false);
    const vendorDialogRef = useRef<HTMLDivElement>(null);
    const replaceVendorUrl = useCallback(
        (id: string | null, refresh = false) => {
            const url = new URL(window.location.href);
            url.searchParams.delete('party');

            if (id) {
                url.searchParams.set('vendor', id);
            } else {
                url.searchParams.delete('vendor');
            }

            router.replace({
                url: url.pathname + url.search + url.hash,
                preserveState: true,
                preserveScroll: true,
                props: (current) => ({
                    ...current,
                    initialVendor: null,
                    initialParty: null,
                }),
                onSuccess: refresh
                    ? () => router.reload({ only: ['vendors'] })
                    : undefined,
            });
        },
        [],
    );
    const closeCard = (refresh = false) => {
        setEditing(undefined);
        setViewing(null);
        replaceVendorUrl(null, refresh);
    };
    const openExisting = useCallback(
        (account: Vendor) => {
            setEditing(undefined);
            setViewing(account);
            replaceVendorUrl(account.id);
        },
        [setEditing, setViewing, replaceVendorUrl],
    );
    const [importing, setImporting] = useState(false);
    const legalEntityName = (id: string) =>
        legalEntities.find((entity) => entity.id === id)?.name ?? id;
    const startNew = () =>
        transition.request(() => {
            setDraftParty(null);
            setViewing(null);
            setEditing(null);
            setDraftKey((key) => key + 1);
            replaceVendorUrl(null);
        });
    const navigation = useRecordNavigation({
        items: vendors.data,
        currentId: editing?.id ?? viewing?.id ?? null,
        getId: (vendor) => vendor.id,
        onNavigate: (id) => {
            const vendor = vendors.data.find((row) => row.id === id);

            if (!vendor) {
                return;
            }

            replaceVendorUrl(vendor.id);

            if (editing !== undefined) {
                setEditing(vendor);
            } else {
                setViewing(vendor);
            }
        },
        scopeKey: JSON.stringify(filters),
        disabled: vendorSaving || vendorDirty,
        hasMore: Boolean(vendors.next_page_url),
        loading: listLoading,
        loadMore: () => {
            if (!listRef.current?.hasNext()) {
                return;
            }

            setListLoading(true);
            listRef.current.fetchNext({
                onFinish: () => setListLoading(false),
                onCancel: () => setListLoading(false),
            });
        },
    });

    return (
        <>
            <Head title="Vendor" />
            <ListPageLayout
                filterPanel={
                    filtersOpen ? (
                        <FilterPane
                            id={filterPanelId}
                            title="Filter vendor"
                            description="Hasil diperbarui otomatis saat filter diubah."
                            side="left"
                            onClose={() => setFiltersOpen(false)}
                        >
                            <div className="p-4">
                                <Filters
                                    filters={filters}
                                    legalEntities={legalEntities}
                                    onChange={applyFilters}
                                />
                            </div>
                        </FilterPane>
                    ) : null
                }
                header={
                    <RecordActionBar
                        title="Vendor"
                        titleHint={vendorTitleHint}
                        search={{
                            value: query,
                            onValueChange: onQueryChange,
                            onSubmit: () => applyFilters({}),
                            label: 'Cari vendor',
                            placeholder: 'Cari nomor, nama, atau NPWP',
                            maxLength: 100,
                        }}
                        filter={{
                            open: filtersOpen,
                            onOpenChange: setFiltersOpen,
                            active: Boolean(
                                filters.legal_entity || filters.status,
                            ),
                            controls: filterPanelId,
                        }}
                        preferenceKey={
                            auth.membership
                                ? `core.vendors:${auth.membership.tenant_id}:${auth.user.id}`
                                : undefined
                        }
                        groups={
                            canManage
                                ? [
                                      {
                                          id: 'home',
                                          label: 'Home',
                                          actions: [
                                              {
                                                  id: 'import',
                                                  label: 'Impor vendor CSV',
                                                  icon: <Upload />,
                                                  onSelect: () =>
                                                      setImporting(true),
                                              },
                                          ],
                                      },
                                  ]
                                : undefined
                        }
                        trailing={
                            <div
                                ref={setFactBoxToolbar}
                                className="flex items-center gap-1"
                            />
                        }
                    >
                        {canManage && (
                            <ActionButton
                                action="create"
                                disabled={legalEntities.length === 0}
                                onClick={startNew}
                            />
                        )}
                    </RecordActionBar>
                }
            >
                <VendorContextPanel
                    vendor={selectedVendor}
                    company={
                        selectedVendor
                            ? legalEntityName(selectedVendor.legal_entity_id)
                            : '—'
                    }
                    draft={false}
                    toolbarContainer={factBoxToolbar}
                    className="flex-1 pt-3"
                >
                    <RecordListScroll
                        listRef={listRef}
                        data="vendors"
                        className="flex h-full min-h-0 flex-1 flex-col"
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
                            onRowAction={(action, vendor) => {
                                replaceVendorUrl(vendor.id);

                                if (action === 'edit') {
                                    setViewing(null);
                                    setEditing(vendor);
                                } else {
                                    setEditing(undefined);
                                    setViewing(vendor);
                                }
                            }}
                            rowActivation="single-click"
                            activeRowKey={selectedVendorId ?? undefined}
                            onRowClick={(vendor) =>
                                setSelectedVendorId(vendor.id)
                            }
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
                                        <DataTableText>
                                            {vendor.name}
                                        </DataTableText>
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
                                            {
                                                PARTY_TYPE_LABEL[
                                                    vendor.party_type
                                                ]
                                            }
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
                    </RecordListScroll>
                </VendorContextPanel>
            </ListPageLayout>
            {editing !== undefined && (
                <PageDialog
                    open
                    onOpenChange={(open) => {
                        if (!open && !vendorSaving) {
                            transition.request(closeCard);
                        }
                    }}
                >
                    <PageDialogContent
                        ref={vendorDialogRef}
                        aria-describedby={undefined}
                        onEscapeKeyDown={(event) => {
                            if (vendorSaving) {
                                event.preventDefault();
                            }
                        }}
                        title="Vendor"
                        titleHint={vendorTitleHint}
                        onNew={canManage ? startNew : undefined}
                        busy={vendorSaving}
                        navigation={editing ? navigation : undefined}
                    >
                        <VendorCard
                            portalContainer={vendorDialogRef}
                            onBusyChange={setVendorSaving}
                            onDirtyChange={setVendorDirty}
                            key={
                                editing?.id ??
                                `${draftParty?.id ?? 'new'}-${draftKey}`
                            }
                            vendor={editing}
                            legalEntities={legalEntities}
                            automaticNumbers={automaticNumbers}
                            initialParty={draftParty}
                            canReadAddressBook={canReadAddressBook}
                            canManageAddressBook={canManageAddressBook}
                            onClose={() =>
                                transition.request(() => setEditing(undefined))
                            }
                            onCreated={(vendor) => {
                                setEditing(vendor);
                                replaceVendorUrl(vendor.id, true);
                            }}
                            onSaved={() => closeCard(true)}
                            onOpenExisting={openExisting}
                        />
                    </PageDialogContent>
                </PageDialog>
            )}
            {viewing && (
                <PageDialog
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            closeCard();
                        }
                    }}
                >
                    <PageDialogContent
                        aria-describedby={undefined}
                        title="Vendor"
                        titleHint={vendorTitleHint}
                        onNew={canManage ? startNew : undefined}
                        busy={vendorSaving}
                        navigation={navigation}
                    >
                        <VendorReadCard
                            vendor={viewing}
                            company={legalEntityName(viewing.legal_entity_id)}
                            canReadAddressBook={canReadAddressBook}
                            canManageAddressBook={canManageAddressBook}
                            onClose={() => closeCard()}
                            onEdit={
                                canManage
                                    ? () => {
                                          setViewing(null);
                                          setEditing(viewing);
                                      }
                                    : undefined
                            }
                        />
                    </PageDialogContent>
                </PageDialog>
            )}
            {transition.confirmation}
            <Dialog open={importing} onOpenChange={setImporting}>
                <DialogContent size="wide">
                    <DialogHeader>
                        <DialogTitle>Impor vendor</DialogTitle>
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
