import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import { DataTable, DataTableText } from '@apperp/ui/data-table';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import {
    Field,
    FieldLegend,
    FieldError,
    FieldLabel,
    FieldSet,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Switch } from '@apperp/ui/switch';
import { Textarea } from '@apperp/ui/textarea';
import { useRef, useState } from 'react';
import { apiJson, CoreApiError, errorText } from '@/lib/core-api';
import {
    OrganizationAddressesSection,
    OrganizationContactsSection,
} from '../../organization/components/address-book-section';
import type {
    DirectoryMetadata,
    Party,
    PartyDetail,
    PartyProfile,
} from '../types';
import { partyTypeLabel } from '../types';
import { AddressBookHint } from './address-book-hint';

type ProfileField = {
    key: keyof PartyProfile;
    label: string;
    type?: 'date' | 'number' | 'select';
};
export const commonProfileFields: ProfileField[] = [
    { key: 'language', label: 'Bahasa', type: 'select' },
];
// shortcut: ABC disembunyikan sampai ada kriteria dan proses yang memakainya.
export const organizationProfileFields: ProfileField[] = [];
export const personProfileFields: ProfileField[] = [
    { key: 'gender', label: 'Jenis kelamin', type: 'select' },
    { key: 'marital_status', label: 'Status pernikahan', type: 'select' },
    { key: 'birth_date', label: 'Tanggal lahir', type: 'date' },
    { key: 'anniversary_date', label: 'Tanggal pernikahan', type: 'date' },
    { key: 'number_of_children', label: 'Jumlah anak', type: 'number' },
    { key: 'interests', label: 'Minat' },
];

export function PartyForm({
    party,
    metadata,
    onClose,
    onSaved,
    embedded = false,
    onBusyChange,
}: {
    party: PartyDetail | null;
    metadata: DirectoryMetadata;
    onClose: () => void;
    onSaved: (party: Party) => void;
    embedded?: boolean;
    onBusyChange?: (busy: boolean) => void;
}) {
    const [form, setForm] = useState<Record<string, string | number | null>>(
        () => ({
            name: party?.name ?? '',
            search_name: party?.search_name ?? '',
            tax_number: party?.tax_number ?? '',
            organization_number: party?.organization_number ?? '',
            type: party?.type ?? 'organization',
            // Pihak baru langsung aktif; status pihak lama tetap mengikuti record.
            status: party ? party.status : 'active',
            ...Object.fromEntries(
                [
                    ...commonProfileFields,
                    ...organizationProfileFields,
                    ...personProfileFields,
                    { key: 'memo' },
                ].map((field) => [
                    field.key,
                    party?.[field.key as keyof PartyProfile] ??
                        (field.key === 'language' ? 'id' : null),
                ]),
            ),
        }),
    );
    const [bookIds, setBookIds] = useState(party?.address_book_ids ?? []);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const creationKey = useRef(crypto.randomUUID());
    const set = (key: string, value: string | number | null) =>
        setForm((current) => ({ ...current, [key]: value }));
    const searchEdited = useRef(
        Boolean(
            party?.search_name &&
            party.search_name !==
                party.name.trim().replace(/\s+/g, ' ').toLocaleLowerCase(),
        ),
    );
    const changeName = (name: string) =>
        setForm((current) => ({
            ...current,
            name,
            ...(!searchEdited.current ? { search_name: name } : {}),
        }));
    const internalOrganization = Boolean(party?.organization_id);
    // shortcut: Pembaca integrasi jumlah karyawan belum tersedia. Jangan menerima input
    // manual; sembunyikan nilai kosong/0. Saat integrasi tersedia, tampilkan hasilnya di sini.
    const employeeCount = party?.number_of_employees ?? 0;
    async function save(event: React.FormEvent) {
        if (event.target !== event.currentTarget) {
            return;
        }

        event.preventDefault();
        setBusy(true);
        onBusyChange?.(true);
        setError('');
        setErrors({});
        const data = Object.fromEntries(
            Object.entries(form).map(([key, value]) => [
                key,
                value === '' && !['name', 'type', 'status'].includes(key)
                    ? null
                    : value,
            ]),
        );

        try {
            const result = await apiJson<{ data: Party }>(
                party ? `/api/v1/parties/${party.id}` : '/api/v1/parties',
                {
                    method: party ? 'PATCH' : 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Idempotency-Key': creationKey.current,
                    },
                    body: JSON.stringify({
                        ...data,
                        address_book_ids: bookIds,
                        ...(party ? { version: party.version } : {}),
                    }),
                },
            );
            onSaved(result.data);
        } catch (caught) {
            setError(errorText(caught, 'Pihak belum dapat disimpan.'));

            if (caught instanceof CoreApiError) {
                setErrors(caught.errors);
            }
        } finally {
            setBusy(false);
            onBusyChange?.(false);
        }
    }
    const fields = (items: ProfileField[]) => (
        <div className="grid gap-4 sm:grid-cols-2">
            {items.map((field) => (
                <Field key={field.key}>
                    {field.type === 'select' ? (
                        <NativeSelect
                            label={field.label}
                            value={String(form[field.key] ?? '')}
                            disabled={busy}
                            onChange={(event) =>
                                set(field.key, event.target.value || null)
                            }
                        >
                            <NativeSelectOption value="">
                                Belum diisi
                            </NativeSelectOption>
                            {(metadata.referenceValues[field.key] ?? []).map(
                                (value) => (
                                    <NativeSelectOption
                                        key={value.code}
                                        value={value.code}
                                    >
                                        {value.name}
                                    </NativeSelectOption>
                                ),
                            )}
                        </NativeSelect>
                    ) : (
                        <Input
                            label={field.label}
                            type={field.type ?? 'text'}
                            min={field.type === 'number' ? 0 : undefined}
                            value={String(form[field.key] ?? '')}
                            disabled={busy}
                            onChange={(event) =>
                                set(
                                    field.key,
                                    field.type === 'number'
                                        ? event.target.value === ''
                                            ? null
                                            : Number(event.target.value)
                                        : event.target.value,
                                )
                            }
                        />
                    )}
                    <FieldError>{errors[field.key]?.[0]}</FieldError>
                </Field>
            ))}
        </div>
    );

    return (
        <div className="flex h-full min-h-0 flex-col">
            <RecordActionBar
                title={
                    embedded
                        ? undefined
                        : party
                          ? `${party.number ?? ''} · ${party.name}`
                          : 'Tambah Party'
                }
                trailing={
                    <>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={onClose}
                        >
                            Batal
                        </Button>
                        <Button type="submit" form="party-form" disabled={busy}>
                            {busy ? 'Menyimpan…' : 'Simpan'}
                        </Button>
                    </>
                }
            />
            <form
                id="party-form"
                onSubmit={(event) => void save(event)}
                className="min-h-0 flex-1 overflow-y-auto p-4"
            >
                {error && (
                    <p role="alert" className="text-sm text-destructive">
                        {error}
                    </p>
                )}
                <CollapsibleSectionGroup defaultValue={['general']}>
                    <CollapsibleSection
                        value="general"
                        title="General"
                        summary={String(form.name) || 'Identitas Party'}
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field>
                                <Input
                                    label="Nomor pihak"
                                    value={
                                        party?.number ??
                                        'Diterbitkan otomatis setelah disimpan'
                                    }
                                    readOnly
                                />
                            </Field>
                            <Field>
                                {party ? (
                                    <Input
                                        label="Jenis pihak"
                                        readOnly
                                        value={partyTypeLabel(
                                            party.type,
                                            metadata.partyTypes,
                                        )}
                                    />
                                ) : (
                                    <NativeSelect
                                        label="Jenis pihak"
                                        required
                                        value={String(form.type)}
                                        disabled={busy}
                                        onChange={(event) =>
                                            set('type', event.target.value)
                                        }
                                    >
                                        {metadata.partyTypes
                                            .filter((type) =>
                                                [
                                                    'person',
                                                    'organization',
                                                ].includes(type.code),
                                            )
                                            .map((type) => (
                                                <NativeSelectOption
                                                    key={type.code}
                                                    value={type.code}
                                                >
                                                    {type.name}
                                                </NativeSelectOption>
                                            ))}
                                    </NativeSelect>
                                )}
                                <FieldError>{errors.type?.[0]}</FieldError>
                            </Field>
                            {form.type !== 'person' && (
                                <Field>
                                    <Input
                                        label="NIB (Nomor Induk Berusaha)"
                                        value={String(
                                            form.organization_number ?? '',
                                        )}
                                        disabled={busy}
                                        onChange={(event) =>
                                            set(
                                                'organization_number',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <FieldError>
                                        {errors.organization_number?.[0]}
                                    </FieldError>
                                </Field>
                            )}
                            <Field>
                                <div className="flex items-center gap-2">
                                    <Input
                                        label={
                                            form.type === 'person'
                                                ? 'Nama lengkap'
                                                : 'Nama organisasi'
                                        }
                                        required
                                        value={String(form.name)}
                                        readOnly={internalOrganization}
                                        disabled={busy}
                                        onChange={(event) =>
                                            changeName(event.target.value)
                                        }
                                    />
                                    {internalOrganization && (
                                        <AddressBookHint label="nama organisasi">
                                            Nama organisasi diubah dari halaman
                                            Organisasi.
                                        </AddressBookHint>
                                    )}
                                </div>
                                <FieldError>{errors.name?.[0]}</FieldError>
                            </Field>
                            <Field>
                                <Input
                                    label="NPWP"
                                    maxLength={32}
                                    inputMode="numeric"
                                    value={String(form.tax_number ?? '')}
                                    disabled={busy}
                                    onChange={(event) =>
                                        set('tax_number', event.target.value)
                                    }
                                />
                                <FieldError>
                                    {errors.tax_number?.[0]}
                                </FieldError>
                            </Field>
                            <Field>
                                <Input
                                    label="Nama pencarian"
                                    value={String(form.search_name ?? '')}
                                    disabled={busy}
                                    onChange={(event) => {
                                        searchEdited.current = true;
                                        set('search_name', event.target.value);
                                    }}
                                />
                                <FieldError>
                                    {errors.search_name?.[0]}
                                </FieldError>
                            </Field>
                            <Field orientation="horizontal">
                                <Switch
                                    id="party-active"
                                    checked={form.status === 'active'}
                                    disabled={busy}
                                    onCheckedChange={(checked) =>
                                        set(
                                            'status',
                                            checked ? 'active' : 'inactive',
                                        )
                                    }
                                />
                                <FieldLabel htmlFor="party-active">
                                    Aktif
                                </FieldLabel>
                            </Field>
                        </div>
                    </CollapsibleSection>
                    {(form.type === 'person' || employeeCount > 0) && (
                        <CollapsibleSection
                            value="profile"
                            title="Rincian pihak"
                            summary={
                                form.type === 'person'
                                    ? 'Perorangan'
                                    : 'Organisasi'
                            }
                        >
                            {fields(
                                form.type === 'person'
                                    ? personProfileFields
                                    : organizationProfileFields,
                            )}
                            {form.type !== 'person' && employeeCount > 0 && (
                                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                    <Input
                                        label="Jumlah karyawan"
                                        value={employeeCount}
                                        readOnly
                                    />
                                </div>
                            )}
                        </CollapsibleSection>
                    )}
                    <CollapsibleSection
                        value="additional"
                        title="Informasi tambahan"
                        summary="Bahasa dan catatan"
                    >
                        <div className="space-y-4">
                            {fields(commonProfileFields)}
                            <Textarea
                                label="Catatan"
                                value={String(form.memo ?? '')}
                                disabled={busy}
                                onChange={(event) =>
                                    set('memo', event.target.value)
                                }
                            />
                        </div>
                    </CollapsibleSection>
                    <CollapsibleSection
                        value="books"
                        title="Buku alamat"
                        summary={`${bookIds.length} buku alamat`}
                    >
                        <FieldSet className="gap-4">
                            <FieldLegend
                                variant="label"
                                hint="Kelompok untuk memudahkan pencarian pihak. Pengelompokan ini tidak memberi izin akses."
                            >
                                Keanggotaan buku alamat
                            </FieldLegend>
                            {metadata.addressBooks.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada buku alamat. Anda tetap dapat
                                    menyimpan pihak tanpa kelompok.
                                </p>
                            ) : (
                                metadata.addressBooks.map((book) => (
                                    <Field
                                        key={book.id}
                                        orientation="horizontal"
                                    >
                                        <Checkbox
                                            id={`book-${book.id}`}
                                            checked={bookIds.includes(book.id)}
                                            disabled={busy}
                                            onCheckedChange={(checked) =>
                                                setBookIds((ids) =>
                                                    checked
                                                        ? [...ids, book.id]
                                                        : ids.filter(
                                                              (id) =>
                                                                  id !==
                                                                  book.id,
                                                          ),
                                                )
                                            }
                                        />
                                        <FieldLabel htmlFor={`book-${book.id}`}>
                                            {book.name}
                                        </FieldLabel>
                                    </Field>
                                ))
                            )}
                        </FieldSet>
                    </CollapsibleSection>
                    <CollapsibleSection
                        value="addresses"
                        title="Alamat"
                        summary="Alamat bersama Party"
                    >
                        {party ? (
                            <OrganizationAddressesSection
                                organizationId={party.id}
                                resource="parties"
                                countryCode="ID"
                                canManage={metadata.canManage}
                            />
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Simpan pihak terlebih dahulu untuk menambahkan
                                alamat.
                            </p>
                        )}
                    </CollapsibleSection>
                    <CollapsibleSection
                        value="contacts"
                        title="Informasi kontak"
                        summary="Telepon, email, dan kontak lainnya"
                    >
                        {party ? (
                            <OrganizationContactsSection
                                organizationId={party.id}
                                resource="parties"
                                canManage={metadata.canManage}
                            />
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Simpan pihak terlebih dahulu untuk menambahkan
                                kontak.
                            </p>
                        )}
                    </CollapsibleSection>
                    <CollapsibleSection
                        value="roles"
                        title="Peran bisnis"
                        summary={`${party?.roles.length ?? 0} peran bisnis`}
                    >
                        {!party?.roles.length ? (
                            <Empty>
                                <EmptyHeader>
                                    <EmptyTitle>
                                        Belum ada peran bisnis
                                    </EmptyTitle>
                                    <EmptyDescription>
                                        Peran muncul setelah pihak ini dipakai
                                        sebagai akun bisnis, misalnya vendor.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <DataTable
                                scrollMode="inline"
                                data={party?.roles ?? []}
                                getRowKey={(role) =>
                                    `${role.code}-${role.legal_entity_id}`
                                }
                                emptyMessage="Peran muncul setelah pihak ini dipakai sebagai akun bisnis, misalnya vendor."
                                columns={[
                                    {
                                        id: 'role',
                                        header: 'Peran',
                                        cell: (role) => (
                                            <DataTableText>
                                                {metadata.referenceValues.role?.find(
                                                    (value) =>
                                                        value.code ===
                                                        role.code,
                                                )?.name ?? role.code}
                                            </DataTableText>
                                        ),
                                    },
                                    {
                                        id: 'company',
                                        header: 'Perusahaan',
                                        cell: (role) => (
                                            <DataTableText>
                                                {role.company ?? '—'}
                                            </DataTableText>
                                        ),
                                    },
                                ]}
                            />
                        )}
                    </CollapsibleSection>
                </CollapsibleSectionGroup>
            </form>
        </div>
    );
}
