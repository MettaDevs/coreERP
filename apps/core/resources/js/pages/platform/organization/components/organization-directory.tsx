import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@apperp/ui/accordion';

import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@apperp/ui/empty';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import {
    Sheet,
    SheetTrigger,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetFooter,
} from '@apperp/ui/sheet';

import { Head, useForm, usePage } from '@inertiajs/react';

import {
    Building2,
    Check,
    ChevronRight,
    Image,
    Layers,
    Mail,
    MapPin,
    Pencil,
    Receipt,
    Search,
} from 'lucide-react';
import { useRef, useState } from 'react';
import type { RefObject } from 'react';
import Heading from '@/components/heading';

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
    organizationIds,
    onCreated,
}: {
    classification: Organization['classification'];
    operatingUnitTypes: Props['operatingUnitTypes'];
    triggerLabel: string;
    organizationIds: string[];
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
        <Sheet open={open} onOpenChange={setOpen}>
            <SheetTrigger asChild>
                <ActionButton action="create" size="sm">
                    Tambah {triggerLabel.toLocaleLowerCase()}
                </ActionButton>
            </SheetTrigger>
            <SheetContent ref={contentRef} side="right">
                <SheetHeader>
                    <SheetTitle>
                        {legalEntity
                            ? 'Buat entitas legal'
                            : 'Buat operating unit'}
                    </SheetTitle>
                </SheetHeader>
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
                                const created = (
                                    page.props.organizations as Organization[]
                                ).find(
                                    (organization) =>
                                        !organizationIds.includes(
                                            organization.id,
                                        ),
                                );

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
                                            ? 'Nama entitas legal'
                                            : 'Nama operating unit'
                                    }
                                    aria-invalid={Boolean(form.errors.name)}
                                />
                                <FieldError>{form.errors.name}</FieldError>
                            </Field>
                            {legalEntity ? (
                                <div className="grid gap-4 md:grid-cols-2">
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
                                <div className="grid gap-4 md:grid-cols-2">
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
                                            label="Nomor Unit"
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
                                            Nomor yang dikirim ke aplikasi
                                            finance. Huruf besar, angka, dan
                                            tanda hubung.
                                        </FieldDescription>
                                        <FieldError>
                                            {form.errors.operating_unit_number}
                                        </FieldError>
                                    </Field>
                                </div>
                            )}
                        </FieldGroup>
                    </div>
                    <SheetFooter>
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
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
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
}: {
    organization: Organization;
    canManage: boolean;
    initialEditing?: boolean;
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
                  title: 'Alamat Utama & Cabang',
                  description:
                      'Alamat utama dan lokasi kantor legal entity; alamat utama tampil pada kop dokumen.',
                  icon: MapPin,
              },
              {
                  value: 'contact-information',
                  title: 'Informasi Kontak & Komunikasi',
                  description:
                      'Email resmi, telepon, WhatsApp, faks, dan laman; kontak utama tampil pada kop dokumen.',
                  icon: Mail,
              },
              {
                  value: 'print-identity',
                  title: 'Identitas Cetak: Kop, Footer & Logo',
                  description:
                      'Nama pada kop, baris induk, NPWP/NIB, footer, dan logo pada semua dokumen yang dicetak.',
                  icon: Image,
              },
              {
                  value: 'finance-posting',
                  title: 'Posting ke Aplikasi Finance',
                  description:
                      'Aktif atau tidaknya pengiriman jurnal, tanggal cutover, dan kebijakan jurnal perolehan.',
                  icon: Receipt,
              },
          ]
        : [
              {
                  value: 'addresses',
                  title: 'Alamat Lokasi Kerja',
                  description: 'Alamat fisik unit operasional ini.',
                  icon: MapPin,
              },
              {
                  value: 'contact-information',
                  title: 'Informasi Kontak Unit',
                  description:
                      'Email dan kontak penanggung jawab unit operasional.',
                  icon: Mail,
              },
              {
                  value: 'print-identity',
                  title: 'Identitas Cetak Unit (Kop Sendiri)',
                  description:
                      'Isi hanya bila unit ini mencetak dengan kop sendiri, misalnya puskesmas di bawah dinas. Kosong berarti memakai kop legal entity.',
                  icon: Image,
              },
          ];
    const extraSections = allExtraSections.filter(
        (section) =>
            section.value !== 'finance-posting' ||
            permissions.has('core.finance-setup.read'),
    );

    return (
        <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col bg-card text-foreground">
            {/* Header Sticky Detail dengan Banner Khas */}
            <div className="sticky top-0 z-10 flex min-w-0 shrink-0 flex-wrap items-center justify-between gap-3 border-b border-border bg-card/90 px-6 py-4 backdrop-blur-md">
                <div className="flex min-w-0 items-center gap-3">
                    <div className="flex size-11 shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary shadow-2xs">
                        {legalEntity ? (
                            <Building2 className="size-5" />
                        ) : (
                            <Layers className="size-5" />
                        )}
                    </div>
                    <div className="min-w-0">
                        <div className="flex items-center gap-2">
                            <CardTitle className="truncate text-base font-bold text-foreground">
                                {organization.name}
                            </CardTitle>
                        </div>
                        <div className="mt-2 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <Badge variant="outline">
                                {legalEntity
                                    ? 'Legal entity'
                                    : 'Operating unit'}
                            </Badge>
                            <span>
                                {legalEntity
                                    ? `${organization.legal_entity?.company_code ?? 'Belum ada kode'} · ${organization.legal_entity?.country_code ?? 'Belum ada negara'}`
                                    : [
                                          unitType,
                                          organization.operating_unit?.number,
                                      ]
                                          .filter(Boolean)
                                          .join(' · ')}
                            </span>
                            {needsNumber(organization.operating_unit) && (
                                <MissingNumberBadge />
                            )}
                        </div>
                    </div>
                </div>
                {canManage && (
                    <RecordActionBar>
                        {!editing ? (
                            <Button
                                type="button"
                                size="sm"
                                onClick={() => setEditing(true)}
                                className="text-xs font-medium shadow-xs"
                            >
                                <Pencil className="mr-1.5 size-3.5" /> Ubah
                                Organisasi
                            </Button>
                        ) : (
                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    className="text-xs"
                                    onClick={() => {
                                        form.reset();
                                        form.clearErrors();
                                        setEditing(false);
                                    }}
                                    disabled={form.processing}
                                >
                                    Batal
                                </Button>
                                <Button
                                    type="submit"
                                    size="sm"
                                    className="text-xs shadow-xs"
                                    form={`organization-form-${organization.id}`}
                                    disabled={form.processing}
                                >
                                    <Check className="mr-1.5 size-3.5" /> Simpan
                                </Button>
                            </div>
                        )}
                    </RecordActionBar>
                )}
            </div>

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
                            { onSuccess: () => setEditing(false) },
                        );
                    }}
                >
                    <Accordion
                        type="multiple"
                        defaultValue={['general']}
                        className="space-y-3"
                    >
                        <AccordionItem
                            value="general"
                            className="rounded-xl border border-border/80 bg-card px-5 shadow-2xs"
                        >
                            <AccordionTrigger className="py-3.5 text-xs font-bold tracking-wider text-foreground uppercase hover:no-underline">
                                <span className="flex items-center gap-2">
                                    <Building2 className="size-4 shrink-0 text-primary" />
                                    Identitas Umum Organisasi
                                </span>
                            </AccordionTrigger>
                            <AccordionContent className="pt-1 pb-5">
                                <FieldGroup className="grid gap-4 md:grid-cols-2">
                                    <Field
                                        data-invalid={Boolean(form.errors.name)}
                                    >
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
                                            aria-invalid={Boolean(
                                                form.errors.name,
                                            )}
                                        />
                                        <FieldError>
                                            {form.errors.name}
                                        </FieldError>
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
                                                    value={
                                                        form.data.company_code
                                                    }
                                                    readOnly={!editing}
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'company_code',
                                                            event.target.value,
                                                        )
                                                    }
                                                    aria-invalid={Boolean(
                                                        form.errors
                                                            .company_code,
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
                                                    value={
                                                        form.data.country_code
                                                    }
                                                    readOnly={!editing}
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'country_code',
                                                            event.target.value,
                                                        )
                                                    }
                                                    aria-invalid={Boolean(
                                                        form.errors
                                                            .country_code,
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
                                                    form.setData(
                                                        'timezone',
                                                        value,
                                                    )
                                                }
                                                error={form.errors.timezone}
                                                editing={editing}
                                            />
                                        </>
                                    ) : (
                                        <>
                                            <Field
                                                data-invalid={Boolean(
                                                    form.errors
                                                        .operating_unit_type,
                                                )}
                                            >
                                                {editing ? (
                                                    <Select
                                                        label="Tipe operating unit"
                                                        required
                                                        items={Object.entries(
                                                            operatingUnitTypes,
                                                        ).map(
                                                            ([
                                                                value,
                                                                label,
                                                            ]) => ({
                                                                value,
                                                                label,
                                                            }),
                                                        )}
                                                        value={
                                                            form.data
                                                                .operating_unit_type ||
                                                            null
                                                        }
                                                        onValueChange={(
                                                            value,
                                                        ) =>
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
                                                    label="Nomor Unit"
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
                                                    Nomor yang dikirim ke
                                                    aplikasi finance sebagai
                                                    dimensi. Mengganti nomor
                                                    tidak mengubah jurnal yang
                                                    sudah terkirim.
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
                            </AccordionContent>
                        </AccordionItem>
                        {extraSections.map((section) => {
                            return (
                                <AccordionItem
                                    key={section.value}
                                    value={section.value}
                                    className="rounded-xl border border-border/80 bg-card px-5 shadow-2xs"
                                >
                                    <AccordionTrigger className="py-3.5 text-xs font-bold text-foreground hover:no-underline">
                                        <span className="flex items-center gap-2">
                                            {section.title}
                                        </span>
                                    </AccordionTrigger>
                                    <AccordionContent className="pt-1 pb-5">
                                        <OrganizationExtraSectionContent
                                            section={section}
                                            organization={organization}
                                            canManage={canManage}
                                            canManageFinancePosting={
                                                canManageFinancePosting
                                            }
                                        />
                                    </AccordionContent>
                                </AccordionItem>
                            );
                        })}
                    </Accordion>
                </form>
            </div>
        </div>
    );
}

export default function OrganizationDirectory({
    canManage,
    organizations,
    operatingUnitTypes,
    classification,
    title,
}: Pick<Props, 'canManage' | 'organizations' | 'operatingUnitTypes'> & {
    classification: Organization['classification'];
    title: string;
}) {
    const [selectedOrganizationId, setSelectedOrganizationId] = useState<
        string | null
    >(null);
    const [organizationSearch, setOrganizationSearch] = useState('');
    const [createdOrganizationId, setCreatedOrganizationId] = useState<
        string | null
    >(null);

    const visibleOrganizations = organizations.filter(
        (organization) => organization.classification === classification,
    );

    const organizationSearchTerm = organizationSearch
        .trim()
        .toLocaleLowerCase();
    const filteredOrganizations = visibleOrganizations.filter(
        (organization) => {
            if (!organizationSearchTerm) {
                return true;
            }

            return [
                organization.name,
                organization.legal_entity?.company_code,
                organization.legal_entity?.country_code,
                organization.operating_unit?.number ?? undefined,
                organization.operating_unit?.type,
                organization.operating_unit?.type
                    ? operatingUnitTypes[organization.operating_unit.type]
                    : undefined,
            ].some((value) =>
                value?.toLocaleLowerCase().includes(organizationSearchTerm),
            );
        },
    );
    const selectedOrganization =
        visibleOrganizations.find(
            (organization) => organization.id === selectedOrganizationId,
        ) ?? null;

    return (
        <>
            <Head title={title} />
            <main className="flex min-w-0 flex-col gap-6 p-4 sm:p-6">
                <Heading title={title} />
                <Card>
                    <CardHeader>
                        <CardTitle>
                            Daftar {title.toLocaleLowerCase()}
                        </CardTitle>
                        {canManage && (
                            <CardAction className="col-span-2 col-start-1 row-span-1 row-start-2 justify-self-start sm:col-span-1 sm:col-start-2 sm:row-start-1 sm:justify-self-end">
                                <CreateOrganizationDialog
                                    classification={classification}
                                    operatingUnitTypes={operatingUnitTypes}
                                    triggerLabel={title}
                                    organizationIds={organizations.map(
                                        (organization) => organization.id,
                                    )}
                                    onCreated={(organization) => {
                                        setSelectedOrganizationId(
                                            organization.id,
                                        );
                                        setCreatedOrganizationId(
                                            organization.id,
                                        );
                                        setOrganizationSearch('');
                                    }}
                                />
                            </CardAction>
                        )}
                    </CardHeader>
                    <CardContent>
                        {visibleOrganizations.length ? (
                            <div className="grid min-w-0 gap-0 lg:min-h-[32rem] lg:grid-cols-[20rem_minmax(0,1fr)]">
                                {/* Sidebar Daftar Organisasi Kiri */}
                                <aside className="flex min-w-0 flex-col overflow-hidden border-r border-border bg-card/40">
                                    <div className="shrink-0 space-y-2 border-b border-border p-4">
                                        <div className="flex items-center justify-between">
                                            <p className="text-sm font-medium text-muted-foreground">
                                                Pilih Organisasi
                                            </p>
                                            <Badge
                                                variant="outline"
                                                className="text-[10px]"
                                            >
                                                {filteredOrganizations.length}{' '}
                                                Total
                                            </Badge>
                                        </div>
                                        <div className="relative">
                                            <Search className="absolute top-2.5 left-2.5 size-3.5 text-muted-foreground" />
                                            <Input
                                                aria-label="Cari organisasi"
                                                placeholder="Cari nama atau kode..."
                                                value={organizationSearch}
                                                className="h-8 bg-background pl-8 text-xs"
                                                onChange={(event) =>
                                                    setOrganizationSearch(
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                    </div>
                                    <div className="min-h-0 flex-1 space-y-1 overflow-y-auto p-2">
                                        {filteredOrganizations.length ? (
                                            filteredOrganizations.map(
                                                (organization) => {
                                                    const selected =
                                                        organization.id ===
                                                        selectedOrganization?.id;
                                                    const subtitle =
                                                        organization.legal_entity
                                                            ? `${organization.legal_entity.company_code} · ${organization.legal_entity.country_code}`
                                                            : [
                                                                  operatingUnitTypes[
                                                                      organization
                                                                          .operating_unit
                                                                          ?.type ??
                                                                          ''
                                                                  ] ??
                                                                      organization
                                                                          .operating_unit
                                                                          ?.type,
                                                                  organization
                                                                      .operating_unit
                                                                      ?.number,
                                                              ]
                                                                  .filter(
                                                                      Boolean,
                                                                  )
                                                                  .join(' · ');

                                                    return (
                                                        <button
                                                            key={
                                                                organization.id
                                                            }
                                                            type="button"
                                                            aria-pressed={
                                                                selected
                                                            }
                                                            onClick={() => {
                                                                setSelectedOrganizationId(
                                                                    organization.id,
                                                                );
                                                                setCreatedOrganizationId(
                                                                    null,
                                                                );
                                                            }}
                                                            className={`group flex w-full items-center justify-between rounded-lg border-l-4 p-3 text-left transition-all ${
                                                                selected
                                                                    ? 'border-l-primary bg-primary/10 font-semibold text-primary shadow-2xs'
                                                                    : 'border-l-transparent text-foreground hover:bg-muted/50'
                                                            }`}
                                                        >
                                                            <div className="flex min-w-0 flex-1 items-start gap-2.5 pr-2">
                                                                {organization.legal_entity ? (
                                                                    <Building2 className="mt-0.5 size-4 shrink-0 text-primary" />
                                                                ) : (
                                                                    <Layers className="mt-0.5 size-4 shrink-0 text-primary" />
                                                                )}
                                                                <div className="min-w-0 flex-1">
                                                                    <span className="block truncate text-xs leading-tight font-semibold">
                                                                        {
                                                                            organization.name
                                                                        }
                                                                    </span>
                                                                    <span className="mt-0.5 block truncate text-[11px] font-normal text-muted-foreground">
                                                                        {
                                                                            subtitle
                                                                        }
                                                                    </span>
                                                                    {needsNumber(
                                                                        organization.operating_unit,
                                                                    ) && (
                                                                        <span className="mt-1 block">
                                                                            <MissingNumberBadge />
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            </div>
                                                            <ChevronRight className="size-4 shrink-0 text-muted-foreground/60 transition-transform group-hover:translate-x-0.5 group-hover:text-foreground" />
                                                        </button>
                                                    );
                                                },
                                            )
                                        ) : (
                                            <p className="p-4 text-center text-xs text-muted-foreground italic">
                                                Tidak ada organisasi yang cocok.
                                            </p>
                                        )}
                                    </div>
                                </aside>

                                {/* Section Detail Organisasi Terpilih */}
                                {selectedOrganization && (
                                    <div className="flex min-w-0 overflow-hidden bg-card">
                                        <OrganizationDetailPage
                                            key={selectedOrganization.id}
                                            initialEditing={
                                                createdOrganizationId ===
                                                selectedOrganization.id
                                            }
                                            organization={selectedOrganization}
                                            canManage={canManage}
                                            operatingUnitTypes={
                                                operatingUnitTypes
                                            }
                                        />
                                    </div>
                                )}
                            </div>
                        ) : (
                            <Empty className="py-16">
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">
                                        <Building2 className="size-8 text-muted-foreground" />
                                    </EmptyMedia>
                                    <EmptyTitle>
                                        Belum Ada{' '}
                                        {classification === 'legal_entity'
                                            ? 'Legal Entity'
                                            : 'Operating Unit'}
                                    </EmptyTitle>
                                    <EmptyDescription className="max-w-sm text-xs text-muted-foreground">
                                        {classification === 'legal_entity'
                                            ? 'Buat legal entity pertama agar transaksi resmi dan perpajakan memiliki identitas badan hukum.'
                                            : 'Buat operating unit bila proses bisnis membutuhkan struktur departemen atau divisi.'}
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        )}
                    </CardContent>
                </Card>
            </main>
        </>
    );
}
