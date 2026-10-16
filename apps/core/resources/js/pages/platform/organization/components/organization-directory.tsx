import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
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
    FieldDescription,
    FieldError,
    FieldGroup,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import { MasterDetailLayout } from '@apperp/ui/master-detail-layout';
import {
    PageDialog,
    PageDialogTrigger,
    PageDialogContent,
    PageDialogHeader,
    PageDialogTitle,
    PageDialogFooter,
} from '@apperp/ui/page-dialog';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { RecordEditBoundary } from '@apperp/ui/record-edit-boundary';
import { Select } from '@apperp/ui/select';
import { useIncrementalList } from '@apperp/ui/use-incremental-list';
import type { ListLoader } from '@apperp/ui/use-incremental-list';

import { Head, useForm, usePage } from '@inertiajs/react';

import { Image, Mail, MapPin, Receipt } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import type { RefObject } from 'react';
import { apiJson } from '@/lib/core-api';

import {
    OrganizationAddressesSection,
    OrganizationContactsSection,
} from './address-book-section';
import { FinancePostingSection } from './finance-posting-section';
import { needsNumber, MissingNumberBadge } from './organization-number-status';
import type { Props, Organization } from './organization-types';
import { PrintIdentitySection } from './print-identity-section';

type TimezoneOption = { value: string; label: string };

/**
 * Zona waktu entitas legal: bawaan bagi pengguna yang belum memilih zonanya sendiri di My Profile (K-10).
 * Saat form belum dalam mode ubah, nilainya tampil sebagai teks baca saja.
 */
function LegalEntityTimezoneField({
    value,
    onChange,
    error,
    editing = true,
    portalContainer,
}: {
    value: string;
    onChange: (value: string) => void;
    error?: string;
    editing?: boolean;
    portalContainer?: RefObject<HTMLElement | null>;
}) {
    const { timezones } = usePage<{ timezones: TimezoneOption[] }>().props;
    const label =
        timezones.find((zone) => zone.value === value)?.label ?? value;

    return (
        <Field data-invalid={Boolean(error)}>
            {editing ? (
                <Select
                    label="Zona waktu"
                    portalContainer={portalContainer}
                    items={timezones}
                    value={value || null}
                    onValueChange={(next) => next && onChange(next)}
                    searchPlaceholder="Cari zona waktu..."
                    emptyMessage="Zona waktu tidak ditemukan."
                />
            ) : (
                <Input label="Zona waktu" value={label} readOnly />
            )}
            <FieldDescription>
                Dipakai pengguna yang belum memilih zona waktunya sendiri di
                profil.
            </FieldDescription>
            <FieldError>{error}</FieldError>
        </Field>
    );
}

function organizationFields(
    data: {
        company_code: string;
        country_code: string;
        timezone: string;
        operating_unit_type: string;
        operating_unit_number: string;
    },
    legalEntity: boolean,
) {
    return legalEntity
        ? {
              company_code: data.company_code,
              country_code: data.country_code,
              timezone: data.timezone,
          }
        : {
              operating_unit_type: data.operating_unit_type,
              operating_unit_number: data.operating_unit_number,
          };
}

function CreateOrganizationDialog({
    classification,
    operatingUnitTypes,
    triggerLabel,
    onCreated,
}: {
    classification: Organization['classification'];
    operatingUnitTypes: Props['operatingUnitTypes'];
    triggerLabel: string;
    onCreated: (organization: Organization) => void;
}) {
    const [open, setOpen] = useState(false);
    const contentRef = useRef<HTMLDivElement>(null);
    const { clock } = usePage().props;
    const form = useForm({
        classification,
        name: '',
        company_code: '',
        country_code: 'ID',
        // Bawaannya zona pengguna yang membuatnya; kosong berarti bawaan kolom di server.
        timezone:
            classification === 'legal_entity' ? (clock?.timezone ?? '') : '',
        operating_unit_type: 'department',
        operating_unit_number: '',
    });
    const legalEntity = classification === 'legal_entity';

    return (
        <PageDialog
            open={open}
            onOpenChange={(next) => {
                if (!form.processing) {
                    setOpen(next);
                }
            }}
        >
            <PageDialogTrigger asChild>
                <ActionButton action="create" size="sm">
                    Tambah {triggerLabel.toLocaleLowerCase()}
                </ActionButton>
            </PageDialogTrigger>
            <PageDialogContent ref={contentRef}>
                <PageDialogHeader>
                    <PageDialogTitle>
                        {legalEntity
                            ? 'Buat perusahaan'
                            : 'Buat operating unit'}
                    </PageDialogTitle>
                </PageDialogHeader>
                <form
                    className="flex min-h-0 flex-1 flex-col"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            classification: data.classification,
                            name: data.name,
                            ...organizationFields(data, legalEntity),
                        }));
                        form.post('/settings/organization/organizations', {
                            onSuccess: (page) => {
                                const created = page.props
                                    .savedOrganization as Organization | null;

                                if (created) {
                                    onCreated(created);
                                }

                                form.reset();
                                setOpen(false);
                            },
                        });
                    }}
                >
                    <div className="min-h-0 flex-1 overflow-y-auto px-6 py-4">
                        <FieldGroup>
                            <Field data-invalid={Boolean(form.errors.name)}>
                                <Input
                                    label="Nama Organisasi"
                                    required
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                    placeholder={
                                        legalEntity
                                            ? 'Nama perusahaan'
                                            : 'Nama operating unit'
                                    }
                                    aria-invalid={Boolean(form.errors.name)}
                                />
                                <FieldError>{form.errors.name}</FieldError>
                            </Field>
                            {legalEntity ? (
                                <div className="grid gap-4 py-2 md:grid-cols-2">
                                    <Field
                                        data-invalid={Boolean(
                                            form.errors.company_code,
                                        )}
                                    >
                                        <Input
                                            label="Kode Perusahaan"
                                            required
                                            value={form.data.company_code}
                                            onChange={(event) =>
                                                form.setData(
                                                    'company_code',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Contoh: PT-ERS"
                                            aria-invalid={Boolean(
                                                form.errors.company_code,
                                            )}
                                        />
                                        <FieldDescription>
                                            2–16 karakter unik.
                                        </FieldDescription>
                                        <FieldError>
                                            {form.errors.company_code}
                                        </FieldError>
                                    </Field>
                                    <Field
                                        data-invalid={Boolean(
                                            form.errors.country_code,
                                        )}
                                    >
                                        <Input
                                            label="Kode Negara (ISO)"
                                            required
                                            value={form.data.country_code}
                                            onChange={(event) =>
                                                form.setData(
                                                    'country_code',
                                                    event.target.value,
                                                )
                                            }
                                            aria-invalid={Boolean(
                                                form.errors.country_code,
                                            )}
                                            maxLength={2}
                                            placeholder="ID"
                                        />
                                        <FieldError>
                                            {form.errors.country_code}
                                        </FieldError>
                                    </Field>
                                    <LegalEntityTimezoneField
                                        portalContainer={contentRef}
                                        value={form.data.timezone}
                                        onChange={(value) =>
                                            form.setData('timezone', value)
                                        }
                                        error={form.errors.timezone}
                                    />
                                </div>
                            ) : (
                                <div className="grid gap-4 py-2 md:grid-cols-2">
                                    <Field
                                        data-invalid={Boolean(
                                            form.errors.operating_unit_type,
                                        )}
                                    >
                                        <Select
                                            label="Tipe operating unit"
                                            required
                                            items={Object.entries(
                                                operatingUnitTypes,
                                            ).map(([value, label]) => ({
                                                value,
                                                label,
                                            }))}
                                            value={
                                                form.data.operating_unit_type ||
                                                null
                                            }
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'operating_unit_type',
                                                    value ?? '',
                                                )
                                            }
                                            portalContainer={contentRef}
                                            aria-invalid={Boolean(
                                                form.errors.operating_unit_type,
                                            )}
                                        />
                                        <FieldError>
                                            {form.errors.operating_unit_type}
                                        </FieldError>
                                    </Field>
                                    <Field
                                        data-invalid={Boolean(
                                            form.errors.operating_unit_number,
                                        )}
                                    >
                                        <Input
                                            label="Kode unit"
                                            required={[
                                                'business_unit',
                                                'department',
                                            ].includes(
                                                form.data.operating_unit_type,
                                            )}
                                            value={
                                                form.data.operating_unit_number
                                            }
                                            onChange={(event) =>
                                                form.setData(
                                                    'operating_unit_number',
                                                    event.target.value.toUpperCase(),
                                                )
                                            }
                                            maxLength={30}
                                            placeholder="Contoh: KLN-A"
                                            aria-invalid={Boolean(
                                                form.errors
                                                    .operating_unit_number,
                                            )}
                                        />
                                        <FieldDescription>
                                            Kode unik untuk membedakan unit yang
                                            namanya sama. Gunakan huruf besar,
                                            angka, atau tanda hubung. Kode ini
                                            juga dikirim ke Finance.
                                        </FieldDescription>
                                        <FieldError>
                                            {form.errors.operating_unit_number}
                                        </FieldError>
                                    </Field>
                                </div>
                            )}
                        </FieldGroup>
                    </div>
                    <PageDialogFooter>
                        <Button type="submit" disabled={form.processing}>
                            Simpan Organisasi
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                            disabled={form.processing}
                        >
                            Batal
                        </Button>
                    </PageDialogFooter>
                </form>
            </PageDialogContent>
        </PageDialog>
    );
}

type OrganizationExtraSection = {
    value: string;
    title: string;
    description: string;
};

function OrganizationExtraSectionContent({
    section,
    organization,
    canManage,
    canManageFinancePosting,
}: {
    section: OrganizationExtraSection;
    organization: Organization;
    canManage: boolean;
    canManageFinancePosting: boolean;
}) {
    if (section.value === 'addresses') {
        return (
            <OrganizationAddressesSection
                organizationId={organization.id}
                countryCode={organization.legal_entity?.country_code ?? 'ID'}
                canManage={canManage}
            />
        );
    }

    if (section.value === 'contact-information') {
        return (
            <OrganizationContactsSection
                organizationId={organization.id}
                canManage={canManage}
            />
        );
    }

    if (section.value === 'print-identity') {
        return (
            <PrintIdentitySection
                organizationId={organization.id}
                organizationName={organization.name}
                canManage={canManage}
            />
        );
    }

    if (section.value === 'finance-posting') {
        return (
            <FinancePostingSection
                organizationId={organization.id}
                canManage={canManageFinancePosting}
            />
        );
    }

    return null;
}

function OrganizationDetailPage({
    organization,
    canManage,
    operatingUnitTypes,
    initialEditing = false,
    onUpdated,
}: {
    organization: Organization;
    canManage: boolean;
    initialEditing?: boolean;
    onUpdated: (organization: Organization) => void;
    operatingUnitTypes: Props['operatingUnitTypes'];
}) {
    const legalEntity = organization.classification === 'legal_entity';
    const [editing, setEditing] = useState(initialEditing);
    const form = useForm({
        name: organization.name,
        company_code: organization.legal_entity?.company_code ?? '',
        country_code: organization.legal_entity?.country_code ?? 'ID',
        timezone: organization.legal_entity?.timezone ?? '',
        operating_unit_type: organization.operating_unit?.type ?? 'department',
        operating_unit_number: organization.operating_unit?.number ?? '',
    });
    const unitType =
        operatingUnitTypes[organization.operating_unit?.type ?? ''] ??
        organization.operating_unit?.type;
    // Setelan posting ke aplikasi finance termasuk setup finance, bukan organisasi: bagiannya hanya tampil bagi
    // pemegang duty Lihat setup finance, dan mengubahnya butuh Kelola setup finance.
    const permissions = new Set(
        usePage().props.auth.membership?.permissions ?? [],
    );
    const canManageFinancePosting = permissions.has(
        'core.finance-setup.update',
    );
    const allExtraSections: (OrganizationExtraSection & {
        icon: React.ComponentType<{ className?: string }>;
    })[] = legalEntity
        ? [
              {
                  value: 'addresses',
                  title: 'Alamat',
                  description:
                      'Alamat utama dan lokasi kantor perusahaan; alamat utama tampil pada kop dokumen.',
                  icon: MapPin,
              },
              {
                  value: 'contact-information',
                  title: 'Informasi kontak',
                  description:
                      'Email resmi, telepon, WhatsApp, faks, dan laman; kontak utama tampil pada kop dokumen.',
                  icon: Mail,
              },
              {
                  value: 'print-identity',
                  title: 'Identitas cetak',
                  description:
                      'Nama pada kop, baris induk, NPWP/NIB, footer, dan logo pada semua dokumen yang dicetak.',
                  icon: Image,
              },
              {
                  value: 'finance-posting',
                  title: 'Posting finance',
                  description:
                      'Aktif atau tidaknya pengiriman jurnal, tanggal cutover, dan kebijakan jurnal perolehan.',
                  icon: Receipt,
              },
          ]
        : [
              {
                  value: 'addresses',
                  title: 'Alamat',
                  description: 'Alamat fisik unit operasional ini.',
                  icon: MapPin,
              },
              {
                  value: 'contact-information',
                  title: 'Informasi kontak',
                  description:
                      'Email dan kontak penanggung jawab unit operasional.',
                  icon: Mail,
              },
              {
                  value: 'print-identity',
                  title: 'Identitas cetak',
                  description:
                      'Isi hanya bila unit ini mencetak dengan kop sendiri, misalnya puskesmas di bawah dinas. Kosong berarti memakai kop perusahaan.',
                  icon: Image,
              },
          ];
    const extraSections = allExtraSections.filter(
        (section) =>
            section.value !== 'finance-posting' ||
            permissions.has('core.finance-setup.read'),
    );

    return (
        <RecordEditBoundary
            editing={editing}
            canEdit={canManage}
            disabled={form.processing}
            onEdit={() => setEditing(true)}
            className="flex h-full min-h-0 min-w-0 flex-1 flex-col bg-card text-foreground"
        >
            <RecordActionBar
                title={organization.name}
                trailing={
                    <>
                        <Badge variant="outline">
                            {organization.status === 'active'
                                ? 'Aktif'
                                : 'Nonaktif'}
                        </Badge>
                        {needsNumber(organization.operating_unit) && (
                            <MissingNumberBadge />
                        )}
                    </>
                }
            >
                {canManage &&
                    (!editing ? (
                        <ActionButton
                            action="edit"
                            onClick={() => setEditing(true)}
                        >
                            Ubah
                        </ActionButton>
                    ) : (
                        <>
                            <Button
                                type="submit"
                                form={`organization-form-${organization.id}`}
                                disabled={form.processing}
                            >
                                Simpan
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() => {
                                    form.reset();
                                    form.clearErrors();
                                    setEditing(false);
                                }}
                                disabled={form.processing}
                            >
                                Batal
                            </Button>
                        </>
                    ))}
            </RecordActionBar>
            {/* Content Accordion */}
            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-6">
                <form
                    id={`organization-form-${organization.id}`}
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            name: data.name,
                            ...organizationFields(data, legalEntity),
                            version: organization.version,
                        }));
                        form.patch(
                            `/settings/organization/organizations/${organization.id}`,
                            {
                                onSuccess: (page) => {
                                    setEditing(false);
                                    const saved = page.props
                                        .savedOrganization as Organization | null;

                                    if (saved) {
                                        onUpdated(saved);
                                    }
                                },
                            },
                        );
                    }}
                >
                    <CollapsibleSectionGroup
                        defaultValue={['general']}
                        className="space-y-3"
                    >
                        <CollapsibleSection
                            value="general"
                            title="Umum"
                            summary={
                                legalEntity
                                    ? `${organization.legal_entity?.company_code ?? '-'} - ${organization.name}`
                                    : `${organization.operating_unit?.number ?? '-'} - ${organization.name}`
                            }
                        >
                            <FieldGroup className="grid gap-4 py-2 md:grid-cols-2">
                                <Field data-invalid={Boolean(form.errors.name)}>
                                    <Input
                                        label="Nama Organisasi"
                                        required
                                        value={form.data.name}
                                        readOnly={!editing}
                                        onChange={(event) =>
                                            form.setData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={Boolean(form.errors.name)}
                                    />
                                    <FieldError>{form.errors.name}</FieldError>
                                </Field>
                                {legalEntity ? (
                                    <>
                                        <Field
                                            data-invalid={Boolean(
                                                form.errors.company_code,
                                            )}
                                        >
                                            <Input
                                                label="Kode Perusahaan"
                                                required
                                                value={form.data.company_code}
                                                readOnly={!editing}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'company_code',
                                                        event.target.value,
                                                    )
                                                }
                                                aria-invalid={Boolean(
                                                    form.errors.company_code,
                                                )}
                                            />
                                            <FieldError>
                                                {form.errors.company_code}
                                            </FieldError>
                                        </Field>
                                        <Field
                                            data-invalid={Boolean(
                                                form.errors.country_code,
                                            )}
                                        >
                                            <Input
                                                label="Kode Negara (ISO)"
                                                required
                                                value={form.data.country_code}
                                                readOnly={!editing}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'country_code',
                                                        event.target.value,
                                                    )
                                                }
                                                aria-invalid={Boolean(
                                                    form.errors.country_code,
                                                )}
                                                maxLength={2}
                                            />
                                            <FieldError>
                                                {form.errors.country_code}
                                            </FieldError>
                                        </Field>
                                        <LegalEntityTimezoneField
                                            value={form.data.timezone}
                                            onChange={(value) =>
                                                form.setData('timezone', value)
                                            }
                                            error={form.errors.timezone}
                                            editing={editing}
                                        />
                                    </>
                                ) : (
                                    <>
                                        <Field
                                            data-invalid={Boolean(
                                                form.errors.operating_unit_type,
                                            )}
                                        >
                                            {editing ? (
                                                <Select
                                                    label="Tipe operating unit"
                                                    required
                                                    items={Object.entries(
                                                        operatingUnitTypes,
                                                    ).map(([value, label]) => ({
                                                        value,
                                                        label,
                                                    }))}
                                                    value={
                                                        form.data
                                                            .operating_unit_type ||
                                                        null
                                                    }
                                                    onValueChange={(value) =>
                                                        form.setData(
                                                            'operating_unit_type',
                                                            value ?? '',
                                                        )
                                                    }
                                                    aria-invalid={Boolean(
                                                        form.errors
                                                            .operating_unit_type,
                                                    )}
                                                />
                                            ) : (
                                                <Input
                                                    label="Tipe operating unit"
                                                    value={unitType ?? ''}
                                                    readOnly
                                                />
                                            )}
                                            <FieldError>
                                                {
                                                    form.errors
                                                        .operating_unit_type
                                                }
                                            </FieldError>
                                        </Field>
                                        <Field
                                            data-invalid={Boolean(
                                                form.errors
                                                    .operating_unit_number,
                                            )}
                                        >
                                            <Input
                                                label="Kode unit"
                                                required={
                                                    editing &&
                                                    [
                                                        'business_unit',
                                                        'department',
                                                    ].includes(
                                                        form.data
                                                            .operating_unit_type,
                                                    )
                                                }
                                                value={
                                                    form.data
                                                        .operating_unit_number
                                                }
                                                readOnly={!editing}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'operating_unit_number',
                                                        event.target.value.toUpperCase(),
                                                    )
                                                }
                                                maxLength={30}
                                                placeholder={
                                                    editing
                                                        ? 'Contoh: KLN-A'
                                                        : 'Belum diisi'
                                                }
                                                aria-invalid={Boolean(
                                                    form.errors
                                                        .operating_unit_number,
                                                )}
                                            />
                                            <FieldDescription>
                                                Kode unik untuk membedakan unit
                                                yang namanya sama. Gunakan huruf
                                                besar, angka, atau tanda hubung.
                                                Kode ini juga dikirim ke
                                                Finance; perubahan tidak
                                                mengubah jurnal yang sudah
                                                terkirim.
                                            </FieldDescription>
                                            <FieldError>
                                                {
                                                    form.errors
                                                        .operating_unit_number
                                                }
                                            </FieldError>
                                        </Field>
                                    </>
                                )}
                            </FieldGroup>
                        </CollapsibleSection>
                        {extraSections.map((section) => {
                            return (
                                <CollapsibleSection
                                    key={section.value}
                                    value={section.value}
                                    title={section.title}
                                    summary={section.description}
                                >
                                    <OrganizationExtraSectionContent
                                        section={section}
                                        organization={organization}
                                        canManage={canManage}
                                        canManageFinancePosting={
                                            canManageFinancePosting
                                        }
                                    />
                                </CollapsibleSection>
                            );
                        })}
                    </CollapsibleSectionGroup>
                </form>
            </div>
        </RecordEditBoundary>
    );
}

export default function OrganizationDirectory({
    canManage,
    operatingUnitTypes,
    classification,
    title,
}: Pick<
    Props,
    'canManage' | 'organizations' | 'operatingUnitTypes' | 'savedOrganization'
> & {
    classification: Organization['classification'];
    title: string;
}) {
    const [selectedOrganization, setSelectedOrganization] =
        useState<Organization | null>(null);
    const [showDetail, setShowDetail] = useState(false);
    const [organizationSearch, setOrganizationSearch] = useState('');
    const [createdOrganizationId, setCreatedOrganizationId] = useState<
        string | null
    >(null);
    const path =
        classification === 'legal_entity'
            ? '/settings/legal-entities'
            : '/settings/operating-units';
    const loader = useCallback<ListLoader<Organization>>(
        async ({ query, cursor, sort, signal }) => {
            const params = new URLSearchParams({ incremental: '1', q: query });

            if (cursor) {
                params.set('cursor', cursor);
            }

            if (sort) {
                params.set('sort', sort.columnId);
                params.set('direction', sort.direction);
            }

            const result = await apiJson<{
                data: Organization[];
                next_cursor: string | null;
            }>(`${path}?${params}`, { signal });

            return { items: result.data, nextCursor: result.next_cursor };
        },
        [path],
    );
    const list = useIncrementalList({
        loadItems: loader,
        query: organizationSearch,
        getKey: (item) => item.id,
    });
    const code = (item: Organization) =>
        item.legal_entity?.company_code ?? item.operating_unit?.number ?? '';
    const saved = (organization: Organization) => {
        setSelectedOrganization(organization);
        setShowDetail(true);
        list.reload();
    };

    return (
        <>
            <Head title={title} />
            <ListPageLayout
                header={
                    <RecordActionBar title={title}>
                        {canManage && (
                            <CreateOrganizationDialog
                                classification={classification}
                                operatingUnitTypes={operatingUnitTypes}
                                triggerLabel={title}
                                onCreated={(organization) => {
                                    saved(organization);
                                    setCreatedOrganizationId(organization.id);
                                    setOrganizationSearch('');
                                }}
                            />
                        )}
                    </RecordActionBar>
                }
            >
                <MasterDetailLayout
                    detailOpen={showDetail}
                    onDetailOpenChange={setShowDetail}
                    list={
                        <aside className="flex h-full min-h-0 min-w-0 flex-col">
                            <div className="shrink-0 border-b p-3">
                                <Input
                                    type="search"
                                    aria-label={`Cari ${title.toLocaleLowerCase()}`}
                                    placeholder="Cari nama atau kode..."
                                    value={organizationSearch}
                                    onChange={(event) =>
                                        setOrganizationSearch(
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                            <DataTable
                                data={list.items}
                                getRowKey={(item) => item.id}
                                columns={[
                                    {
                                        id: 'code',
                                        header: 'Kode',
                                        cell: (item) => (
                                            <DataTableText>
                                                {code(item) ||
                                                    'Belum ada nomor'}
                                            </DataTableText>
                                        ),
                                        sortValue: code,
                                        width: 110,
                                    },
                                    {
                                        id: 'name',
                                        header: 'Nama',
                                        cell: (item) => (
                                            <DataTableText>
                                                {item.name}
                                            </DataTableText>
                                        ),
                                        sortValue: (item) => item.name,
                                    },
                                ]}
                                activeRowKey={selectedOrganization?.id}
                                rowActivation="single-click"
                                onRowClick={(organization) => {
                                    setShowDetail(true);

                                    if (
                                        organization.id !==
                                        selectedOrganization?.id
                                    ) {
                                        setCreatedOrganizationId(null);
                                        setSelectedOrganization(organization);
                                    }
                                }}
                                sort={list.sort}
                                onSortChange={list.onSortChange}
                                serverSort
                                incremental={list}
                                emptyMessage="Belum ada organisasi yang cocok."
                            />
                        </aside>
                    }
                >
                    <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col">
                        {selectedOrganization ? (
                            <OrganizationDetailPage
                                key={`${selectedOrganization.id}:${selectedOrganization.version}`}
                                organization={selectedOrganization}
                                initialEditing={
                                    createdOrganizationId ===
                                    selectedOrganization.id
                                }
                                canManage={canManage}
                                operatingUnitTypes={operatingUnitTypes}
                                onUpdated={(organization) => {
                                    setCreatedOrganizationId(null);
                                    saved(organization);
                                }}
                            />
                        ) : (
                            <Empty>
                                <EmptyHeader>
                                    <EmptyTitle>
                                        Pilih {title.toLocaleLowerCase()}
                                    </EmptyTitle>
                                    <EmptyDescription>
                                        Pilih organisasi dari daftar untuk
                                        melihat atau mengubah rinciannya.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        )}
                    </div>
                </MasterDetailLayout>
            </ListPageLayout>
        </>
    );
}
