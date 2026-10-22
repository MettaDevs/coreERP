import { ActionButton } from '@apperp/ui/action-button';
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
import { DataTable, DataTableText } from '@apperp/ui/data-table';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@apperp/ui/field';
import { InlineLookup } from '@apperp/ui/inline-lookup';
import type { LookupLoader } from '@apperp/ui/inline-lookup';
import { Input } from '@apperp/ui/input';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import {
    PageDialogToolbar,
    PageDialog as Dialog,
    PageDialogBody as DialogBody,
    PageDialogContent as DialogContent,
} from '@apperp/ui/page-dialog';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import { Switch } from '@apperp/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Textarea } from '@apperp/ui/textarea';
import { Trash2 } from 'lucide-react';
import {
    Check,
    Pencil,
    Star,
    Mail,
    Phone,
    Globe,
    MessageCircle,
    Printer,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { useSaveFeedback } from '@/hooks/use-save-feedback';
import { apiJson, apiRequest, CoreApiError, errorText } from '@/lib/core-api';
import { loadLookupItems } from '@/lib/lookup-loader';
import { AddressBookHint } from '../../address-book/components/address-book-hint';

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
    version: number;
    location_id: string;
    is_location_owner: boolean;
    owner_party_name: string | null;
    name: string;
    purposes: string[];
    is_primary: boolean;
    shared_with: number;
    country_region_code: string | null;
    province: string | null;
    city: string | null;
    district: string | null;
    village: string | null;
    rt: string | null;
    rw: string | null;
    street: string | null;
    building: string | null;
    postbox: string | null;
    postal_code: string | null;
    province_id: string | null;
    regency_id: string | null;
    district_id: string | null;
    village_id: string | null;
    postal_code_id: string | null;
    formatted: string;
};

type Country = { code: string; name: string };

type Purpose = { code: string; name: string };

type SharableLocation = Omit<
    Location,
    | 'version'
    | 'location_id'
    | 'purposes'
    | 'is_primary'
    | 'shared_with'
    | 'is_location_owner'
    | 'owner_party_name'
>;
type SharedLocationChoice =
    SharableLocation | { id: string; name: string; formatted: string };

type LocationForm = {
    location_id: string;
    name: string;
    purposes: string[];
    is_primary: boolean;
    country_region_code: string;
    street: string;
    building: string;
    district: string;
    village: string;
    rt: string;
    rw: string;
    city: string;
    province: string;
    postal_code: string;
    province_id: string;
    regency_id: string;
    district_id: string;
    village_id: string;
    postal_code_id: string;
    postbox: string;
};

const emptyLocation = (countryCode: string): LocationForm => ({
    province_id: '',
    regency_id: '',
    district_id: '',
    village_id: '',
    postal_code_id: '',
    location_id: '',
    name: '',
    purposes: ['business'],
    is_primary: false,
    country_region_code: countryCode,
    street: '',
    building: '',
    district: '',
    village: '',
    rt: '',
    rw: '',
    city: '',
    province: '',
    postal_code: '',
    postbox: '',
});

const locationToForm = (
    location: Omit<
        Location,
        | 'id'
        | 'version'
        | 'location_id'
        | 'shared_with'
        | 'is_location_owner'
        | 'owner_party_name'
    >,
): LocationForm => ({
    province_id: location.province_id ?? '',
    regency_id: location.regency_id ?? '',
    district_id: location.district_id ?? '',
    village_id: location.village_id ?? '',
    postal_code_id: location.postal_code_id ?? '',
    location_id: '',
    name: location.name,
    purposes: location.purposes,
    is_primary: location.is_primary,
    country_region_code: location.country_region_code ?? 'ID',
    street: location.street ?? '',
    building: location.building ?? '',
    district: location.district ?? '',
    village: location.village ?? '',
    rt: location.rt ?? '',
    rw: location.rw ?? '',
    city: location.city ?? '',
    province: location.province ?? '',
    postal_code: location.postal_code ?? '',
    postbox: location.postbox ?? '',
});

export function newPartyDraftKey() {
    return (
        globalThis.crypto?.randomUUID?.() ??
        `draft-${Date.now()}-${Math.random().toString(36).slice(2)}`
    );
}

export type PartyDraftValues = { locations: Location[]; contacts: Contact[] };
type DraftMetadata = {
    locationPurposes: Purpose[];
    countries: Country[];
    contactTypes: string[];
};
export type PartyDraftDetails = {
    value: PartyDraftValues;
    onChange: (value: PartyDraftValues) => void;
    metadata: DraftMetadata | null;
    loading: boolean;
    error: string;
    retry: () => void;
    existing?: { locations: Location[]; contacts: Contact[] };
};

export function usePartyDraftDetails(
    enabled: boolean,
    partyId?: string | null,
) {
    const [value, onChange] = useState<PartyDraftValues>({
        locations: [],
        contacts: [],
    });
    const [metadata, setMetadata] = useState<DraftMetadata | null>(null);
    const [error, setError] = useState('');
    const [attempt, setAttempt] = useState(0);
    const [existing, setExisting] = useState<{
        partyId: string;
        locations: Location[];
        contacts: Contact[];
        error: string;
    } | null>(null);
    useEffect(() => {
        if (!enabled || !partyId) {
            return;
        }

        const controller = new AbortController();
        Promise.all([
            apiJson<{ data: Location[] }>(
                `/api/v1/parties/${partyId}/locations`,
                { signal: controller.signal },
            ),
            apiJson<{ data: Contact[] }>(
                `/api/v1/parties/${partyId}/contacts`,
                { signal: controller.signal },
            ),
        ])
            .then(([locations, contacts]) => {
                if (!controller.signal.aborted) {
                    setExisting({
                        partyId,
                        locations: locations.data,
                        contacts: contacts.data,
                        error: '',
                    });
                }
            })
            .catch((caught) => {
                if (!controller.signal.aborted) {
                    setExisting({
                        partyId,
                        locations: [],
                        contacts: [],
                        error: errorText(
                            caught,
                            'Alamat dan kontak tersimpan belum dapat dimuat.',
                        ),
                    });
                }
            });

        return () => controller.abort();
    }, [enabled, partyId, attempt]);
    const existingDetails = existing?.partyId === partyId ? existing : null;
    useEffect(() => {
        if (!enabled) {
            return;
        }

        const controller = new AbortController();
        apiJson<{ data: DraftMetadata }>('/api/v1/parties/metadata', {
            signal: controller.signal,
        })
            .then(({ data }) => {
                if (controller.signal.aborted) {
                    return;
                }

                setMetadata(data);
                setError('');
            })
            .catch((caught) => {
                if (!controller.signal.aborted) {
                    setError(
                        errorText(
                            caught,
                            'Pilihan alamat dan kontak belum dapat dimuat.',
                        ),
                    );
                }
            });

        return () => controller.abort();
    }, [enabled, attempt]);
    const payload =
        enabled && (value.locations.length > 0 || value.contacts.length > 0)
            ? {
                  locations: value.locations.map((location) => ({
                      ...locationToForm(location),
                      key: location.id,
                      location_id: location.location_id || null,
                      province_id: location.province_id || null,
                      regency_id: location.regency_id || null,
                      district_id: location.district_id || null,
                      village_id: location.village_id || null,
                      postal_code_id: location.postal_code_id || null,
                  })),
                  contacts: value.contacts.map((contact) => ({
                      type: contact.type,
                      value: contact.value,
                      purpose: contact.purpose,
                      is_primary: contact.is_primary,
                      ...(contact.address_id &&
                      value.locations.some(
                          (location) => location.id === contact.address_id,
                      )
                          ? { address_key: contact.address_id }
                          : { address_id: contact.address_id }),
                  })),
              }
            : undefined;

    return {
        value,
        onChange,
        metadata,
        error: error || existingDetails?.error || '',
        existing: partyId
            ? {
                  locations: existingDetails?.locations ?? [],
                  contacts: existingDetails?.contacts ?? [],
              }
            : undefined,
        loading:
            enabled &&
            ((metadata === null && error === '') ||
                (Boolean(partyId) && existingDetails === null)),
        retry: () => setAttempt((current) => current + 1),
        payload,
    };
}

function useList<T, M>(url: string, fallback: string, enabled = true) {
    const [items, setItems] = useState<T[]>([]);
    const [meta, setMeta] = useState<M | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [reloadKey, setReloadKey] = useState(0);

    useEffect(() => {
        if (!enabled) {
            return;
        }

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
    }, [url, fallback, reloadKey, enabled]);

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
    resource = 'organizations',
    countryCode,
    canManage,
    draft,
    readOnlyData,
}: {
    organizationId: string;
    resource?: 'organizations' | 'parties';
    countryCode: string;
    canManage: boolean;
    draft?: PartyDraftDetails;
    readOnlyData?: {
        items: Location[];
        meta: { purposes: Purpose[]; countries: Country[] };
    };
}) {
    const base = `/api/v1/${resource}/${organizationId}/locations`;
    const storedList = useList<
        Location,
        { purposes: Purpose[]; countries: Country[] }
    >(base, 'Alamat belum dapat dimuat.', !draft && !readOnlyData);
    const list = readOnlyData
        ? {
              ...readOnlyData,
              loading: false,
              error: '',
              reload: () => undefined,
          }
        : draft
          ? {
                items: draft.value.locations,
                meta: {
                    purposes: draft.metadata?.locationPurposes ?? [],
                    countries: draft.metadata?.countries ?? [],
                },
                loading: draft.loading,
                error: draft.error,
                reload: draft.retry,
            }
          : storedList;
    const {
        report: saveReport,
        clear: saveClear,
        summary: saveSummary,
        scopeRef: saveScopeRef,
    } = useSaveFeedback<HTMLDivElement>(base);
    const {
        report: formReport,
        clear: formClear,
        summary: formSummary,
        scopeRef: formScopeRef,
    } = useSaveFeedback(base);
    const [editing, setEditing] = useState<Location | 'new' | null>(null);
    const [form, setForm] = useState<LocationForm>(emptyLocation(countryCode));
    const [saving, setSaving] = useState(false);
    const [formError, setFormError] = useState('');
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>(
        {},
    );
    const [sharable, setSharable] = useState<SharableLocation[]>([]);

    const loadSharedLocations = useCallback<LookupLoader<SharedLocationChoice>>(
        async (request) => {
            const result = await loadLookupItems<SharableLocation>(
                `/api/v1/${resource}/${organizationId}/sharable-locations`,
                request,
            );

            if (!request.signal.aborted) {
                setSharable((current) => {
                    const unique = new Map(
                        current.map((location) => [location.id, location]),
                    );
                    result.items.forEach((location) =>
                        unique.set(location.id, location),
                    );

                    return [...unique.values()];
                });
            }

            return {
                ...result,
                items: request.cursor
                    ? result.items
                    : [
                          { id: '', name: 'Tulis alamat baru', formatted: '' },
                          ...result.items,
                      ],
            };
        },
        [resource, organizationId],
    );

    const linkedLocationIds = new Set(
        list.items.map((location) => location.location_id),
    );
    const sharableChoices = sharable.filter(
        (location) => !linkedLocationIds.has(location.id),
    );
    const dialogRef = useRef<HTMLDivElement>(null);
    type RegionOption = {
        id: string;
        name?: string;
        postal_code?: string;
        area_name?: string;
    };
    const regionKey = [
        editing === 'new' ? 'new' : (editing?.id ?? ''),
        form.country_region_code,
        form.province_id,
        form.regency_id,
        form.district_id,
        form.village_id,
    ].join('|');
    const [regionResult, setRegionResult] = useState<{
        key: string;
        regions: Record<string, RegionOption[]>;
        error: string;
    }>({ key: '', regions: {}, error: '' });
    const regions = regionResult.key === regionKey ? regionResult.regions : {};
    const regionError =
        form.country_region_code === 'ID' && regionResult.key === regionKey
            ? regionResult.error
            : '';
    const regionsLoading =
        editing !== null &&
        form.country_region_code === 'ID' &&
        regionResult.key !== regionKey;
    useEffect(() => {
        if (editing === null || form.country_region_code !== 'ID') {
            return;
        }

        const controller = new AbortController();
        const lookups = [
            ['province_id', 'country', form.country_region_code],
            ['regency_id', 'province', form.province_id],
            ['district_id', 'regency', form.regency_id],
            ['village_id', 'district', form.district_id],
            ['postal_code_id', 'village', form.village_id],
        ];
        Promise.all(
            lookups.map(async ([field, parentType, parentId]) => [
                field,
                parentId
                    ? await apiJson<RegionOption[]>(
                          `/settings/address-setup/lookup/top-down?parent_type=${parentType}&parent_id=${encodeURIComponent(parentId)}`,
                          { signal: controller.signal },
                      )
                    : [],
            ]),
        )
            .then((items) => {
                if (!controller.signal.aborted) {
                    setRegionResult({
                        key: regionKey,
                        regions: Object.fromEntries(items),
                        error: '',
                    });
                }
            })
            .catch((caught) => {
                if (!controller.signal.aborted) {
                    setRegionResult({
                        key: regionKey,
                        regions: {},
                        error: errorText(
                            caught,
                            'Pilihan wilayah belum dapat dimuat.',
                        ),
                    });
                }
            });

        return () => controller.abort();
    }, [
        editing,
        form.country_region_code,
        form.province_id,
        form.regency_id,
        form.district_id,
        form.village_id,
        regionKey,
    ]);
    const regionFields = [
        ['province_id', 'province', 'Provinsi'],
        ['regency_id', 'city', 'Kota / kabupaten'],
        ['district_id', 'district', 'Kecamatan'],
        ['village_id', 'village', 'Kelurahan / desa'],
        ['postal_code_id', 'postal_code', 'Kode pos'],
    ] as const;
    const regionSelect = (index: number) => {
        const [field, textField, label] = regionFields[index];
        const choices = regions[field] ?? [];
        const legacyText = !form[field] && form[textField];

        return (
            <FieldSet
                key={field}
                disabled={
                    regionsLoading ||
                    Boolean(regionError) ||
                    (index > 0 && !form[regionFields[index - 1][0]])
                }
            >
                <Field>
                    <Select
                        label={label}
                        portalContainer={dialogRef}
                        items={choices.map((item) => ({
                            value: item.id,
                            label:
                                item.name ??
                                `${item.postal_code} ${item.area_name ?? ''}`.trim(),
                        }))}
                        value={form[field] || null}
                        placeholder={
                            legacyText
                                ? `Tersimpan: ${legacyText}`
                                : `Pilih ${label.toLowerCase()}`
                        }
                        searchPlaceholder={`Cari ${label.toLowerCase()}`}
                        emptyMessage="Belum ada data pada master wilayah."
                        onValueChange={(value) => {
                            const selected = choices.find(
                                (item) => item.id === value,
                            );
                            setForm((current) => {
                                const next = {
                                    ...current,
                                    [field]: value ?? '',
                                    [textField]:
                                        selected?.name ??
                                        selected?.postal_code ??
                                        '',
                                };

                                for (const [
                                    child,
                                    childText,
                                ] of regionFields.slice(index + 1)) {
                                    next[child] = '';
                                    next[childText] = '';
                                }

                                return next;
                            });
                        }}
                    />
                </Field>
            </FieldSet>
        );
    };
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
        formClear();
        setFieldErrors({});
        setEditing(target);
    };

    const save = async () => {
        formClear();
        setFieldErrors({});

        if (!chosenShared && (regionsLoading || regionError)) {
            setFormError('Tunggu sampai pilihan wilayah berhasil dimuat.');

            return;
        }

        if (draft && editing) {
            const id = editing === 'new' ? newPartyDraftKey() : editing.id;
            const isPrimary =
                form.is_primary ||
                (draft.value.locations.length === 0 &&
                    !draft.existing?.locations.length);
            const location: Location = {
                ...form,
                id,
                version: 0,
                location_id: chosenShared?.id ?? '',
                is_location_owner: !chosenShared,
                owner_party_name: null,
                shared_with: 0,
                is_primary: isPrimary,
                formatted:
                    chosenShared?.formatted ||
                    [
                        form.street,
                        form.building,
                        form.village,
                        form.district,
                        form.city,
                        form.province,
                        form.postal_code,
                    ]
                        .filter(Boolean)
                        .join(', '),
            };
            const locations = draft.value.locations
                .filter((row) => row.id !== id)
                .map((row) =>
                    isPrimary ? { ...row, is_primary: false } : row,
                );
            draft.onChange({
                ...draft.value,
                locations: [...locations, location],
            });
            setEditing(null);

            return;
        }

        setSaving(true);

        saveClear();

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
                            : {
                                  ...form,
                                  ...Object.fromEntries(
                                      regionFields.map(([field]) => [
                                          field,
                                          form[field] || null,
                                      ]),
                                  ),
                                  location_id: null,
                              },
                    ),
                });
                toast.success('Alamat ditambahkan.');
            } else if (editing) {
                await apiJson(`${base}/${editing.id}`, {
                    method: 'PUT',
                    body: JSON.stringify({
                        ...form,
                        ...Object.fromEntries(
                            regionFields.map(([field]) => [
                                field,
                                form[field] || null,
                            ]),
                        ),
                        location_id: null,
                        version: editing.version,
                    }),
                });
                toast.success('Alamat disimpan.');
            }

            setEditing(null);
            list.reload();
        } catch (caught) {
            if (caught instanceof CoreApiError) {
                setFieldErrors(caught.errors);
            }

            formReport(caught, 'Alamat belum dapat disimpan.');
        } finally {
            setSaving(false);
        }
    };

    const remove = async (location: Location) => {
        if (draft) {
            const locations = draft.value.locations.filter(
                (row) => row.id !== location.id,
            );

            if (location.is_primary && locations[0]) {
                locations[0] = { ...locations[0], is_primary: true };
            }

            draft.onChange({
                locations,
                contacts: draft.value.contacts.map((contact) =>
                    contact.address_id === location.id
                        ? { ...contact, address_id: null, address_name: null }
                        : contact,
                ),
            });

            return;
        }

        saveClear();

        try {
            await apiRequest(`${base}/${location.id}`, {
                method: 'DELETE',
                headers: { 'If-Match': `W/"${location.version}"` },
            });
            toast.success('Alamat diarsipkan.');
            list.reload();
        } catch (caught) {
            saveReport(caught, 'Alamat belum dapat diarsipkan.');
        }
    };

    const makePrimary = async (location: Location) => {
        if (draft) {
            draft.onChange({
                ...draft.value,
                locations: draft.value.locations.map((row) => ({
                    ...row,
                    is_primary: row.id === location.id,
                })),
            });

            return;
        }

        saveClear();

        try {
            await apiJson(base, {
                method: 'POST',
                body: JSON.stringify({
                    location_id: location.location_id,
                    purposes: location.purposes,
                    is_primary: true,
                }),
            });
            toast.success('Alamat utama diganti.');
            list.reload();
        } catch (caught) {
            saveReport(caught, 'Alamat utama belum dapat diganti.');
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
        <Field
            key={key}
            data-error-field={key}
            data-invalid={Boolean(fieldErrors[key])}
        >
            <Input
                label={label}
                aria-invalid={Boolean(fieldErrors[key])}
                placeholder={placeholder}
                value={form[key]}
                onChange={(event) =>
                    setForm((current) => ({
                        ...current,
                        [key]: event.target.value,
                        ...(current.country_region_code !== 'ID' &&
                        [
                            'province',
                            'city',
                            'district',
                            'village',
                            'postal_code',
                        ].includes(key)
                            ? {
                                  province_id: '',
                                  regency_id: '',
                                  district_id: '',
                                  village_id: '',
                                  postal_code_id: '',
                              }
                            : {}),
                    }))
                }
            />
            <FieldError>{fieldErrors[key]?.[0]}</FieldError>
        </Field>
    );

    if (list.loading) {
        return <p className="text-sm text-muted-foreground">Memuat alamat…</p>;
    }

    return (
        <div ref={saveScopeRef} className="space-y-3">
            {list.error && (
                <p className="text-sm text-destructive">{list.error}</p>
            )}
            <RecordActionBar feedback={saveSummary}>
                {canManage && (
                    <ActionButton
                        action="create"
                        type="button"
                        className="shrink-0"
                        disabled={Boolean(list.error)}
                        onClick={() => open('new')}
                    />
                )}
                <AddressBookHint label="alamat">
                    Alamat utama tampil pada kop dokumen. Alamat lain dapat
                    dipakai untuk pengiriman atau lokasi cabang.
                </AddressBookHint>
            </RecordActionBar>

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
                                        {!location.is_location_owner && (
                                            <span className="block text-xs font-normal text-muted-foreground">
                                                Pemilik:{' '}
                                                {location.owner_party_name ??
                                                    'Belum ditetapkan'}
                                                . Ubah alamat melalui pihak
                                                pemilik di buku alamat.
                                            </span>
                                        )}
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
                                                disabled={
                                                    !location.is_location_owner
                                                }
                                                title={
                                                    !location.is_location_owner
                                                        ? 'Ubah alamat melalui pihak pemiliknya di buku alamat.'
                                                        : undefined
                                                }
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
                                                        aria-label={`Delete ${location.name}`}
                                                    >
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                </AlertDialogTrigger>
                                                <AlertDialogContent>
                                                    <AlertDialogHeader>
                                                        <AlertDialogTitle>
                                                            Delete alamat{' '}
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
                                                            Delete
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
                onOpenChange={(isOpen) => {
                    if (!isOpen && !saving) {
                        setEditing(null);
                    }
                }}
            >
                <DialogContent
                    ref={dialogRef}
                    onEscapeKeyDown={(event) => {
                        if (
                            saving ||
                            dialogRef.current?.querySelector(
                                '[role="combobox"][aria-expanded="true"]',
                            )
                        ) {
                            event.preventDefault();
                        }
                    }}
                    title={
                        <>
                            <span className="inline-flex items-center gap-2">
                                {editing === 'new'
                                    ? 'Alamat baru'
                                    : 'Ubah alamat'}
                                <AddressBookHint label="alamat">
                                    Bentuk tercetaknya disusun otomatis: jalan
                                    dan gedung, kelurahan atau kecamatan, lalu
                                    kota, provinsi, dan kode pos.
                                </AddressBookHint>
                            </span>
                        </>
                    }
                    description={
                        <>
                            Bentuk tercetaknya disusun otomatis: jalan dan
                            gedung, kelurahan atau kecamatan, lalu kota,
                            provinsi, dan kode pos.
                        </>
                    }
                >
                    <form
                        noValidate
                        ref={formScopeRef}
                        className="flex min-h-0 flex-1 flex-col"
                        onSubmit={(event) => {
                            // Dialog dirender lewat portal di dalam form organisasi; React
                            // tetap meneruskan submit ke form induk bila tidak dihentikan.
                            event.preventDefault();
                            event.stopPropagation();
                            void save();
                        }}
                    >
                        <PageDialogToolbar>
                            {draft ? (
                                editing === 'new' ? (
                                    <ActionButton
                                        action="add"
                                        type="button"
                                        onClick={(event) =>
                                            event.currentTarget.form?.requestSubmit()
                                        }
                                    />
                                ) : (
                                    <Button type="submit" variant="ghost">
                                        <Check aria-hidden="true" />
                                        Selesai
                                    </Button>
                                )
                            ) : (
                                <ActionButton
                                    action={chosenShared ? 'add' : 'save'}
                                    type="submit"
                                    disabled={saving}
                                    pending={saving}
                                />
                            )}
                            <ActionButton
                                action="cancel"
                                type="button"
                                disabled={saving}
                                onClick={() => setEditing(null)}
                            />
                        </PageDialogToolbar>
                        {formSummary}
                        <DialogBody className="space-y-4 py-3">
                            {editing === 'new' &&
                                (!draft || organizationId !== '') && (
                                    <FieldGroup className="grid gap-4 md:grid-cols-2">
                                        <Field>
                                            <InlineLookup
                                                hint="Pilih alamat yang sudah ada jika tempatnya sama, atau tulis alamat baru. Alamat yang dipilih akan dipakai bersama. Jika alamat tersebut diubah, perubahan berlaku untuk semua pihak yang memakainya."
                                                loadItems={loadSharedLocations}
                                                value={
                                                    chosenShared ?? {
                                                        id: '',
                                                        name: 'Tulis alamat baru',
                                                        formatted: '',
                                                    }
                                                }
                                                label="Gunakan alamat yang sudah ada"
                                                placeholder="Cari nama atau alamat…"
                                                emptyMessage="Alamat tidak ditemukan."
                                                getItemValue={(item) => item.id}
                                                getItemLabel={(item) =>
                                                    item.name
                                                }
                                                getSearchText={(item) =>
                                                    `${item.name} ${item.formatted}`
                                                }
                                                columns={[
                                                    {
                                                        id: 'name',
                                                        header: 'Keterangan alamat',
                                                        cell: (item) =>
                                                            item.name,
                                                        sortValue: (item) =>
                                                            item.name,
                                                        width: 180,
                                                    },
                                                    {
                                                        id: 'address',
                                                        header: 'Alamat',
                                                        cell: (item) =>
                                                            item.formatted.replaceAll(
                                                                '\n',
                                                                ', ',
                                                            ),
                                                        sortValue: (item) =>
                                                            item.formatted,
                                                        width: 400,
                                                    },
                                                ]}
                                                portalContainer={dialogRef}
                                                onValueChange={(item) => {
                                                    const location =
                                                        sharableChoices.find(
                                                            (choice) =>
                                                                choice.id ===
                                                                item?.id,
                                                        );
                                                    setForm((current) => ({
                                                        ...(location
                                                            ? locationToForm({
                                                                  ...location,
                                                                  purposes:
                                                                      current.purposes,
                                                                  is_primary:
                                                                      current.is_primary,
                                                              })
                                                            : {
                                                                  ...emptyLocation(
                                                                      countryCode,
                                                                  ),
                                                                  purposes:
                                                                      current.purposes,
                                                                  is_primary:
                                                                      current.is_primary,
                                                              }),
                                                        location_id:
                                                            location?.id ?? '',
                                                    }));
                                                }}
                                            />
                                            {chosenShared && (
                                                <p
                                                    role="note"
                                                    className="rounded-md border border-warning/40 bg-warning/10 p-3 text-sm"
                                                >
                                                    Alamat ini dipakai bersama.
                                                    Jika alamat ini diubah,
                                                    perubahan berlaku untuk
                                                    semua pihak yang memakai
                                                    alamat tersebut.
                                                </p>
                                            )}
                                        </Field>
                                    </FieldGroup>
                                )}
                            {editing !== null &&
                                editing !== 'new' &&
                                editing.shared_with > 0 && (
                                    <p className="rounded-md border border-warning/40 bg-warning/10 p-3 text-sm">
                                        Alamat ini dipakai juga oleh{' '}
                                        {editing.shared_with} pihak lain. Jika
                                        alamat ini diubah, perubahan berlaku
                                        untuk semua pihak yang memakai alamat
                                        tersebut.
                                    </p>
                                )}
                            <FieldSet disabled={Boolean(chosenShared)}>
                                <FieldGroup className="grid gap-4 md:grid-cols-2">
                                    <Field>
                                        <Textarea
                                            label="Keterangan alamat"
                                            required
                                            maxLength={120}
                                            value={form.name}
                                            onChange={(event) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    name: event.target.value,
                                                }))
                                            }
                                        />
                                    </Field>
                                </FieldGroup>
                            </FieldSet>
                            <FieldSet>
                                <FieldLegend hint="Satu alamat boleh punya beberapa kegunaan, misalnya kantor sekaligus pengiriman.">
                                    Kegunaan
                                </FieldLegend>
                                <div className="flex flex-wrap gap-x-5 gap-y-2">
                                    {(list.meta?.purposes ?? [])
                                        .filter(
                                            (purpose) =>
                                                ![
                                                    'invoice',
                                                    'payment',
                                                ].includes(purpose.code),
                                        )
                                        .map((purpose) => (
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
                                        ))}
                                </div>
                            </FieldSet>
                            <FieldSet disabled={Boolean(chosenShared)}>
                                <>
                                    <FieldGroup className="grid gap-4 md:grid-cols-2">
                                        <Field>
                                            <Select
                                                label="Negara"
                                                portalContainer={dialogRef}
                                                items={(
                                                    list.meta?.countries ?? []
                                                ).map((country) => ({
                                                    value: country.code,
                                                    label: country.name,
                                                }))}
                                                value={form.country_region_code}
                                                onValueChange={(value) =>
                                                    setForm((current) => ({
                                                        ...current,
                                                        country_region_code:
                                                            value ?? '',
                                                        province_id: '',
                                                        regency_id: '',
                                                        district_id: '',
                                                        village_id: '',
                                                        postal_code_id: '',
                                                        province: '',
                                                        city: '',
                                                        district: '',
                                                        village: '',
                                                        postal_code: '',
                                                    }))
                                                }
                                            />
                                        </Field>
                                        {form.country_region_code === 'ID' &&
                                        !chosenShared
                                            ? regionFields.map((_, index) =>
                                                  regionSelect(index),
                                              )
                                            : regionFields.map(
                                                  ([, field, label]) =>
                                                      text(field, label),
                                              )}
                                        {text('street', 'Jalan dan nomor')}
                                        {text(
                                            'building',
                                            'Gedung / blok / lantai',
                                        )}
                                        {text('rt', 'RT')}
                                        {text('rw', 'RW')}
                                        {text('postbox', 'PO Box')}
                                    </FieldGroup>
                                    {regionError && (
                                        <p
                                            role="alert"
                                            className="text-sm text-destructive"
                                        >
                                            {regionError}
                                        </p>
                                    )}
                                </>
                            </FieldSet>
                            <Field>
                                <div className="flex items-center gap-2">
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
                                    <AddressBookHint label="alamat utama">
                                        Alamat pertama otomatis menjadi utama.
                                        Alamat utama tampil pada kop dokumen.
                                    </AddressBookHint>
                                </div>
                            </Field>
                            {formError && (
                                <p className="text-sm text-destructive">
                                    {formError}
                                </p>
                            )}
                        </DialogBody>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}

type Contact = {
    id: string;
    version: number;
    is_location_owner: boolean;
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

const CONTACT_ICON: Record<string, typeof Phone> = {
    phone: Phone,
    email: Mail,
    whatsapp: MessageCircle,
    fax: Printer,
    url: Globe,
};
const TYPE_VALUE_LABEL: Record<string, string> = {
    phone: 'Nomor telepon',
    whatsapp: 'Nomor WhatsApp',
    email: 'Alamat email',
    fax: 'Nomor faks',
    url: 'Alamat website',
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
    resource = 'organizations',
    canManage,
    draft,
    readOnlyData,
}: {
    organizationId: string;
    resource?: 'organizations' | 'parties';
    canManage: boolean;
    draft?: PartyDraftDetails;
    readOnlyData?: { items: Contact[]; meta: { types: string[] } };
}) {
    const base = `/api/v1/${resource}/${organizationId}/contacts`;
    const storedList = useList<Contact, { types: string[] }>(
        base,
        'Informasi kontak belum dapat dimuat.',
        !draft && !readOnlyData,
    );
    const list = readOnlyData
        ? {
              ...readOnlyData,
              loading: false,
              error: '',
              reload: () => undefined,
          }
        : draft
          ? {
                items: draft.value.contacts,
                meta: { types: draft.metadata?.contactTypes ?? [] },
                loading: draft.loading,
                error: draft.error,
                reload: draft.retry,
            }
          : storedList;
    // Kontak menempel ke alamat; daftar alamat dibaca saat form dibuka.
    const {
        report: saveReport,
        clear: saveClear,
        summary: saveSummary,
        scopeRef: saveScopeRef,
    } = useSaveFeedback<HTMLDivElement>(base);
    const {
        report: formReport,
        clear: formClear,
        summary: formSummary,
        scopeRef: formScopeRef,
    } = useSaveFeedback(base);
    const [editing, setEditing] = useState<Contact | 'new' | null>(null);
    const [form, setForm] = useState<ContactForm>({
        type: 'phone',
        value: '',
        purpose: '',
        is_primary: false,
        address_id: '',
    });

    const [saving, setSaving] = useState(false);
    const [formError, setFormError] = useState('');
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>(
        {},
    );
    useEffect(() => {
        if (editing === null) {
            return;
        }

        if (draft) {
            return;
        }

        let cancelled = false;
        apiJson<{ data: Location[] }>(
            `/api/v1/${resource}/${organizationId}/locations`,
        )
            .then((result) => {
                if (cancelled) {
                    return;
                }

                if (editing === 'new') {
                    const primary = result.data.find(
                        (address) =>
                            address.is_primary && address.is_location_owner,
                    );
                    setForm((current) => ({
                        ...current,
                        address_id: primary?.id ?? '',
                    }));
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setFormError(
                        'Alamat utama belum dapat dimuat. Coba buka form kontak lagi.',
                    );
                }
            });

        return () => {
            cancelled = true;
        };
    }, [editing, organizationId, resource, draft]);

    const open = (target: Contact | 'new') => {
        setForm(
            target === 'new'
                ? {
                      type: 'phone',
                      value: '',
                      purpose: '',
                      is_primary: false,
                      address_id:
                          (
                              draft?.value.locations.find(
                                  (location) =>
                                      location.is_primary &&
                                      location.is_location_owner,
                              ) ??
                              draft?.existing?.locations.find(
                                  (location) =>
                                      location.is_primary &&
                                      location.is_location_owner,
                              )
                          )?.id ?? '',
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
        formClear();
        setFieldErrors({});
        setEditing(target);
    };

    const save = async () => {
        formClear();
        setFieldErrors({});

        if (draft && editing) {
            const id = editing === 'new' ? newPartyDraftKey() : editing.id;
            const isPrimary =
                form.is_primary ||
                (!draft.value.contacts.some(
                    (contact) => contact.type === form.type,
                ) &&
                    !draft.existing?.contacts.some(
                        (contact) => contact.type === form.type,
                    ));
            const contact: Contact = {
                ...form,
                id,
                version: 0,
                is_location_owner: true,
                is_primary: isPrimary,
                address_id: form.address_id || null,
                address_name:
                    draft.value.locations.find(
                        (location) => location.id === form.address_id,
                    )?.name ?? null,
            };
            const contacts = draft.value.contacts
                .filter((row) => row.id !== id)
                .map((row) =>
                    isPrimary && row.type === form.type
                        ? { ...row, is_primary: false }
                        : row,
                );
            draft.onChange({
                ...draft.value,
                contacts: [...contacts, contact],
            });
            setEditing(null);

            return;
        }

        setSaving(true);

        saveClear();

        try {
            const body = JSON.stringify({
                ...form,
                address_id: form.address_id || null,
            });

            if (editing === 'new') {
                await apiJson(base, { method: 'POST', body });
                toast.success('Kontak ditambahkan.');
            } else if (editing) {
                await apiJson(`${base}/${editing.id}`, {
                    method: 'PUT',
                    body: JSON.stringify({
                        ...form,
                        address_id: form.address_id || null,
                        version: editing.version,
                    }),
                });
                toast.success('Kontak disimpan.');
            }

            setEditing(null);
            list.reload();
        } catch (caught) {
            if (caught instanceof CoreApiError) {
                setFieldErrors(caught.errors);
            }

            formReport(caught, 'Kontak belum dapat disimpan.');
        } finally {
            setSaving(false);
        }
    };

    const remove = async (contact: Contact) => {
        if (draft) {
            const contacts = draft.value.contacts.filter(
                (row) => row.id !== contact.id,
            );
            const replacement = contacts.findIndex(
                (row) => row.type === contact.type,
            );

            if (contact.is_primary && replacement >= 0) {
                contacts[replacement] = {
                    ...contacts[replacement],
                    is_primary: true,
                };
            }

            draft.onChange({ ...draft.value, contacts });

            return;
        }

        saveClear();

        try {
            await apiRequest(`${base}/${contact.id}`, {
                method: 'DELETE',
                headers: { 'If-Match': `W/"${contact.version}"` },
            });
            toast.success('Kontak diarsipkan.');
            list.reload();
        } catch (caught) {
            saveReport(caught, 'Kontak belum dapat diarsipkan.');
        }
    };

    const makePrimary = async (contact: Contact) => {
        if (draft) {
            draft.onChange({
                ...draft.value,
                contacts: draft.value.contacts.map((row) =>
                    row.type === contact.type
                        ? { ...row, is_primary: row.id === contact.id }
                        : row,
                ),
            });

            return;
        }

        saveClear();

        try {
            await apiJson(`${base}/${contact.id}`, {
                method: 'PUT',
                body: JSON.stringify({
                    type: contact.type,
                    value: contact.value,
                    purpose: contact.purpose ?? '',
                    is_primary: true,
                    version: contact.version,
                }),
            });
            toast.success('Kontak utama diganti.');
            list.reload();
        } catch (caught) {
            saveReport(caught, 'Kontak utama belum dapat diganti.');
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
        <div ref={saveScopeRef} className="space-y-3">
            {list.error && (
                <p className="text-sm text-destructive">{list.error}</p>
            )}
            <RecordActionBar feedback={saveSummary}>
                {canManage && (
                    <ActionButton
                        action="create"
                        type="button"
                        className="shrink-0"
                        disabled={Boolean(list.error)}
                        onClick={() => open('new')}
                    />
                )}
                <AddressBookHint label="informasi kontak">
                    Kontak utama tiap jenis (telepon, WhatsApp, email, faks,
                    laman) tampil pada kop dokumen. Kontak lain disimpan untuk
                    keperluan internal.
                </AddressBookHint>
            </RecordActionBar>

            {list.items.length === 0 ? (
                <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                    Belum ada kontak. Telepon dan email pada kop dokumen kosong
                    sampai ada kontak utama.
                </p>
            ) : (
                <DataTable
                    scrollMode="inline"
                    data={list.items}
                    getRowKey={(contact) => contact.id}
                    showRowNumbers={false}
                    actionsPlacement="inline"
                    actions={
                        canManage
                            ? [
                                  { id: 'edit', label: 'Ubah' },
                                  { id: 'archive', label: 'Delete' },
                              ]
                            : []
                    }
                    onRowAction={(action, contact) => {
                        if (!contact.is_location_owner) {
                            saveReport(
                                new Error(
                                    'Kontak bersama hanya dapat diubah melalui pihak pemilik alamat di buku alamat.',
                                ),
                                'Kontak belum dapat diubah.',
                            );

                            return;
                        }

                        if (action === 'edit') {
                            open(contact);
                        } else {
                            void remove(contact);
                        }
                    }}
                    columns={[
                        {
                            id: 'description',
                            header: 'Keterangan',
                            cell: (contact) => (
                                <DataTableText>
                                    {contact.purpose || '—'}
                                </DataTableText>
                            ),
                        },
                        {
                            id: 'type',
                            header: 'Tipe',
                            width: 140,
                            cell: (contact) => (
                                <DataTableText>
                                    {TYPE_LABEL[contact.type] ?? contact.type}
                                </DataTableText>
                            ),
                        },
                        {
                            id: 'value',
                            header: 'Nomor / alamat',
                            width: 320,
                            cell: (contact) => {
                                const Icon =
                                    CONTACT_ICON[contact.type] ?? Phone;
                                const href =
                                    contact.type === 'email'
                                        ? `mailto:${contact.value}`
                                        : ['phone', 'fax'].includes(
                                                contact.type,
                                            )
                                          ? `tel:${contact.value.replace(/[^+0-9]/g, '')}`
                                          : contact.type === 'url' &&
                                              /^https?:\/\//i.test(
                                                  contact.value,
                                              )
                                            ? contact.value
                                            : undefined;

                                return (
                                    <div className="flex items-center gap-2">
                                        <DataTableText>
                                            {contact.value}
                                        </DataTableText>
                                        {href ? (
                                            <a
                                                href={href}
                                                aria-label={`${TYPE_LABEL[contact.type]} ${contact.value}`}
                                            >
                                                <Icon className="size-4 shrink-0" />
                                            </a>
                                        ) : (
                                            <Icon
                                                aria-label={
                                                    TYPE_LABEL[contact.type]
                                                }
                                                className="size-4 shrink-0"
                                            />
                                        )}
                                    </div>
                                );
                            },
                        },
                        {
                            id: 'primary',
                            header: 'Utama',
                            width: 90,
                            cell: (contact) => (
                                <Checkbox
                                    checked={contact.is_primary}
                                    disabled={
                                        !canManage ||
                                        !contact.is_location_owner ||
                                        contact.is_primary
                                    }
                                    aria-label={`Kontak utama ${contact.value}`}
                                    onCheckedChange={(checked) => {
                                        if (checked) {
                                            void makePrimary(contact);
                                        }
                                    }}
                                />
                            ),
                        },
                    ]}
                />
            )}

            <Dialog
                open={editing !== null}
                onOpenChange={(isOpen) => {
                    if (!isOpen && !saving) {
                        setEditing(null);
                    }
                }}
            >
                <DialogContent
                    onEscapeKeyDown={(event) => {
                        if (saving) {
                            event.preventDefault();
                        }
                    }}
                    title={
                        <>
                            <span className="inline-flex items-center gap-2">
                                {editing === 'new'
                                    ? 'Kontak baru'
                                    : 'Ubah kontak'}
                                <AddressBookHint label="kontak">
                                    Satu kontak utama per jenis di setiap
                                    alamat; kontak utama di alamat utama yang
                                    tampil pada kop dokumen.
                                </AddressBookHint>
                            </span>
                        </>
                    }
                    description={
                        <>
                            Satu kontak utama per jenis di setiap alamat; kontak
                            utama di alamat utama yang tampil pada kop dokumen.
                        </>
                    }
                >
                    <form
                        noValidate
                        ref={formScopeRef}
                        className="flex min-h-0 flex-1 flex-col"
                        onSubmit={(event) => {
                            // Dialog dirender lewat portal di dalam form organisasi; React
                            // tetap meneruskan submit ke form induk bila tidak dihentikan.
                            event.preventDefault();
                            event.stopPropagation();
                            void save();
                        }}
                    >
                        <PageDialogToolbar>
                            {draft ? (
                                editing === 'new' ? (
                                    <ActionButton
                                        action="add"
                                        type="button"
                                        onClick={(event) =>
                                            event.currentTarget.form?.requestSubmit()
                                        }
                                    />
                                ) : (
                                    <Button type="submit" variant="ghost">
                                        <Check aria-hidden="true" />
                                        Selesai
                                    </Button>
                                )
                            ) : (
                                <ActionButton
                                    action="save"
                                    type="submit"
                                    disabled={saving}
                                    pending={saving}
                                />
                            )}
                            <ActionButton
                                action="cancel"
                                type="button"
                                disabled={saving}
                                onClick={() => setEditing(null)}
                            />
                        </PageDialogToolbar>
                        {formSummary}
                        <DialogBody className="space-y-4 py-3">
                            <FieldGroup>
                                {' '}
                                <Field>
                                    <Textarea
                                        label="Keterangan"
                                        maxLength={30}
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
                                        data-error-field="value"
                                        aria-invalid={Boolean(
                                            fieldErrors.value,
                                        )}
                                        label={
                                            TYPE_VALUE_LABEL[form.type] ??
                                            'Nomor / alamat'
                                        }
                                        placeholder={
                                            TYPE_PLACEHOLDER[form.type]
                                        }
                                        type={
                                            form.type === 'email'
                                                ? 'email'
                                                : form.type === 'url'
                                                  ? 'url'
                                                  : 'tel'
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
                                    <FieldError>
                                        {fieldErrors.value?.[0]}
                                    </FieldError>
                                </Field>
                                <Field>
                                    <div className="flex items-center gap-2">
                                        <label className="flex items-center gap-3 text-sm">
                                            <Checkbox
                                                checked={form.is_primary}
                                                onCheckedChange={(checked) =>
                                                    setForm((current) => ({
                                                        ...current,
                                                        is_primary:
                                                            checked === true,
                                                    }))
                                                }
                                            />
                                            Jadikan kontak utama untuk jenis ini
                                        </label>
                                        <AddressBookHint label="kontak utama">
                                            Kontak pertama dari suatu jenis
                                            otomatis menjadi utama.
                                        </AddressBookHint>
                                    </div>
                                </Field>
                            </FieldGroup>
                            {formError && (
                                <p className="text-sm text-destructive">
                                    {formError}
                                </p>
                            )}
                        </DialogBody>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
