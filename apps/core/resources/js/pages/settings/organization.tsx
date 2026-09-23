import '@xyflow/react/dist/style.css';

import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@apperp/ui/accordion';
import {
    AlertDialog,
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
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
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
    DialogTrigger,
} from '@apperp/ui/dialog';
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
    FieldLegend,
    FieldSet,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { NativeSelect } from '@apperp/ui/native-select';
import { ToggleGroup, ToggleGroupItem } from '@apperp/ui/toggle-group';
import { Tooltip, TooltipContent, TooltipTrigger } from '@apperp/ui/tooltip';
import { Head, Link, useForm } from '@inertiajs/react';
import type {
    Edge,
    Node as FlowNode,
    NodeProps,
    NodeTypes,
} from '@xyflow/react';
import {
    Background,
    Controls,
    Handle,
    MiniMap,
    Position,
    ReactFlow,
} from '@xyflow/react';
import {
    Building2,
    Check,
    ChevronRight,
    CircleAlert,
    CreditCard,
    FileText,
    Hash,
    Image,
    Layers,
    Mail,
    MapPin,
    Network,
    Pencil,
    Plus,
    Receipt,
    Search,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import {
    OrganizationAddressesSection,
    OrganizationContactsSection,
} from '@/components/organization/address-book-section';
import { FinancePostingSection } from '@/components/organization/finance-posting-section';
import { PrintIdentitySection } from '@/components/organization/print-identity-section';

type OperatingUnit = { type: string; number: string | null };
type Organization = {
    id: string;
    name: string;
    classification: 'legal_entity' | 'operating_unit';
    status: string;
    legal_entity: { company_code: string; country_code: string } | null;
    operating_unit: OperatingUnit | null;
};
type Purpose = {
    code: string;
    name: string;
    description: string;
    allowed_organization_types?: { organization_type: string }[];
};
type HierarchyNode = {
    id: string;
    organization: Pick<Organization, 'id' | 'name' | 'classification'> & {
        operating_unit?: OperatingUnit | null;
    };
    parent_node: {
        id: string;
        organization: Pick<Organization, 'id' | 'name'>;
    } | null;
};
type Version = {
    id: string;
    version_number: number;
    status: 'draft' | 'published';
    effective_from: string;
    nodes: HierarchyNode[];
};
type Hierarchy = {
    id: string;
    name: string;
    status: string;
    purposes: Purpose[];
    versions: Version[];
};
type Props = {
    canManage: boolean;
    section: 'legal-entities' | 'operating-units' | 'hierarchies';
    tenant: { id: string; name: string };
    organizations: Organization[];
    hierarchies: Hierarchy[];
    purposes: Purpose[];
    operatingUnitTypes: Record<string, string>;
};

/**
 * Tipe yang nomornya dipakai sebagai nilai dimensi keuangan: business unit untuk dimensi
 * klinik, department untuk dimensi poli. Unit bertipe ini yang belum bernomor membuat posting
 * finance-nya tertahan, jadi kekosongannya ditandai sebelum ada transaksi.
 */
const TIPE_BERDIMENSI = ['business_unit', 'department'];

function perluNomor(unit: OperatingUnit | null | undefined): boolean {
    return (
        unit !== null &&
        unit !== undefined &&
        TIPE_BERDIMENSI.includes(unit.type) &&
        !unit.number
    );
}

function NomorBelumAda() {
    return (
        <Badge
            variant="outline"
            className="border-warning/40 text-warning"
            title="Posting finance untuk unit ini akan tertahan sampai nomornya diisi."
        >
            <CircleAlert />
            Belum bernomor
        </Badge>
    );
}

function CreateOrganizationDialog({
    classification,
    operatingUnitTypes,
    triggerLabel,
}: {
    classification: Organization['classification'];
    operatingUnitTypes: Props['operatingUnitTypes'];
    triggerLabel: string;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        classification,
        name: '',
        company_code: '',
        country_code: 'ID',
        operating_unit_type: 'department',
        operating_unit_number: '',
    });
    const legalEntity = classification === 'legal_entity';

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" className="text-xs font-medium shadow-xs">
                    <Plus className="mr-1.5 size-3.5" />
                    {triggerLabel}
                </Button>
            </DialogTrigger>
            <DialogContent size="wide">
                <DialogHeader>
                    <div className="flex items-center gap-3">
                        <div className="flex size-10 shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                            {legalEntity ? (
                                <Building2 className="size-5" />
                            ) : (
                                <Layers className="size-5" />
                            )}
                        </div>
                        <div>
                            <DialogTitle>
                                {legalEntity
                                    ? 'Legal Entity Baru'
                                    : 'Operating Unit Baru'}
                            </DialogTitle>
                            <DialogDescription>
                                {legalEntity
                                    ? 'Simpan identitas badan hukum resmi yang dipakai untuk transaksi, pajak, dan laporan.'
                                    : 'Simpan unit operasional seperti departemen, divisi, atau cabang perusahaan.'}
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/settings/organization/organizations', {
                            onSuccess: () => {
                                form.reset();
                                setOpen(false);
                            },
                        });
                    }}
                >
                    <DialogBody className="space-y-4 py-3">
                        <FieldGroup>
                            <Field data-invalid={Boolean(form.errors.name)}>
                                <Input
                                    label="Nama Organisasi"
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                    placeholder={
                                        legalEntity
                                            ? 'Contoh: PT ERP Solusi Nusantara'
                                            : 'Contoh: Departemen Keuangan & Akuntansi'
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
                                </div>
                            ) : (
                                <div className="grid gap-4 md:grid-cols-2">
                                    <Field
                                        data-invalid={Boolean(
                                            form.errors.operating_unit_type,
                                        )}
                                    >
                                        <NativeSelect
                                            label="Tipe Operating Unit"
                                            value={
                                                form.data.operating_unit_type
                                            }
                                            onChange={(event) =>
                                                form.setData(
                                                    'operating_unit_type',
                                                    event.target.value,
                                                )
                                            }
                                            aria-invalid={Boolean(
                                                form.errors.operating_unit_type,
                                            )}
                                        >
                                            {Object.entries(
                                                operatingUnitTypes,
                                            ).map(([value, label]) => (
                                                <option
                                                    key={value}
                                                    value={value}
                                                >
                                                    {label}
                                                </option>
                                            ))}
                                        </NativeSelect>
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
                                            Kode tetap yang dikirim ke aplikasi
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
                    </DialogBody>
                    <DialogFooter>
                        <DialogAction type="submit" disabled={form.processing}>
                            Simpan Organisasi
                        </DialogAction>
                        <DialogCancel />
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

type OrganizationExtraSection = {
    value: string;
    title: string;
    description: string;
    action?: string;
};

function OrganizationExtraSectionContent({
    section,
    organization,
    canManage,
}: {
    section: OrganizationExtraSection;
    organization: Organization;
    canManage: boolean;
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
                canManage={canManage}
            />
        );
    }

    return (
        <div className="rounded-lg border border-dashed border-border bg-card/40 p-4">
            <p className="text-sm text-muted-foreground">
                {section.description}
            </p>
            {section.action && (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="mt-3 text-xs"
                    disabled
                >
                    {section.action}
                </Button>
            )}
            <p className="mt-2 text-xs text-muted-foreground italic">
                Wadah tersedia; fungsi ini akan dihubungkan oleh modul
                pemiliknya.
            </p>
        </div>
    );
}

function OrganizationDetailPage({
    organization,
    canManage,
    operatingUnitTypes,
}: {
    organization: Organization;
    canManage: boolean;
    operatingUnitTypes: Props['operatingUnitTypes'];
}) {
    const legalEntity = organization.classification === 'legal_entity';
    const [editing, setEditing] = useState(false);
    const form = useForm({
        name: organization.name,
        company_code: organization.legal_entity?.company_code ?? '',
        country_code: organization.legal_entity?.country_code ?? 'ID',
        operating_unit_type: organization.operating_unit?.type ?? 'department',
        operating_unit_number: organization.operating_unit?.number ?? '',
    });
    const unitType =
        operatingUnitTypes[organization.operating_unit?.type ?? ''] ??
        organization.operating_unit?.type;
    const extraSections: (OrganizationExtraSection & {
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
                  value: 'statutory-reporting',
                  title: 'Pelaporan Wajib & Regulasi',
                  description:
                      'Konfigurasi pelaporan resmi pemerintah dan periode pelaporan.',
                  action: 'Pengaturan Pelaporan',
                  icon: FileText,
              },
              {
                  value: 'registration-numbers',
                  title: 'Nomor Registrasi Legal (NIB / NPWP)',
                  description:
                      'Nomor registrasi legalitas badan hukum perusahaan.',
                  action: 'Tambah Registrasi',
                  icon: Hash,
              },
              {
                  value: 'bank-account-information',
                  title: 'Informasi Rekening Bank',
                  description:
                      'Rekening bank resmi yang terdaftar atas nama legal entity.',
                  action: 'Tambah Rekening',
                  icon: CreditCard,
              },
              {
                  value: 'number-sequences',
                  title: 'Nomor Urut Dokumen (Sequences)',
                  description:
                      'Pengaturan penomoran otomatis dokumen transaksi legal entity ini.',
                  action: 'Kelola Nomor Urut',
                  icon: Hash,
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
                  value: 'operating-unit-details',
                  title: 'Detail Spesifik Operating Unit',
                  description: `Atribut khusus untuk tipe ${unitType ?? 'Operating Unit'}.`,
                  action: 'Buka Detail Unit',
                  icon: Layers,
              },
              {
                  value: 'print-identity',
                  title: 'Identitas Cetak Unit (Kop Sendiri)',
                  description:
                      'Isi hanya bila unit ini mencetak dengan kop sendiri, misalnya puskesmas di bawah dinas. Kosong berarti memakai kop legal entity.',
                  icon: Image,
              },
          ];

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
                            <Tooltip clickToPin>
                                <TooltipTrigger asChild>
                                    <button
                                        type="button"
                                        aria-label="Lihat rincian"
                                        className="shrink-0 cursor-pointer rounded-full p-0.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                    >
                                        <CircleAlert className="size-4" />
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent
                                    side="right"
                                    className="max-w-xs text-xs"
                                >
                                    Detail identitas dan atribut resmi
                                    organisasi {organization.name}.
                                </TooltipContent>
                            </Tooltip>
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
                            {perluNomor(organization.operating_unit) && (
                                <NomorBelumAda />
                            )}
                        </div>
                    </div>
                </div>
                {canManage && (
                    <div className="shrink-0">
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
                    </div>
                )}
            </div>

            {/* Content Accordion */}
            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-6">
                <form
                    id={`organization-form-${organization.id}`}
                    onSubmit={(event) => {
                        event.preventDefault();
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
                                        </>
                                    ) : (
                                        <>
                                            <Field
                                                data-invalid={Boolean(
                                                    form.errors
                                                        .operating_unit_type,
                                                )}
                                            >
                                                <NativeSelect
                                                    label="Tipe Operating Unit"
                                                    value={
                                                        form.data
                                                            .operating_unit_type
                                                    }
                                                    aria-readonly={!editing}
                                                    onMouseDown={(event) => {
                                                        if (!editing) {
                                                            event.preventDefault();
                                                        }
                                                    }}
                                                    onKeyDown={(event) => {
                                                        if (!editing) {
                                                            event.preventDefault();
                                                        }
                                                    }}
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'operating_unit_type',
                                                            event.target.value,
                                                        )
                                                    }
                                                    aria-invalid={Boolean(
                                                        form.errors
                                                            .operating_unit_type,
                                                    )}
                                                >
                                                    {Object.entries(
                                                        operatingUnitTypes,
                                                    ).map(([value, label]) => (
                                                        <option
                                                            key={value}
                                                            value={value}
                                                        >
                                                            {label}
                                                        </option>
                                                    ))}
                                                </NativeSelect>
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
                                                    Kode tetap yang dikirim ke
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
                            const SectionIcon = section.icon;

                            return (
                                <AccordionItem
                                    key={section.value}
                                    value={section.value}
                                    className="rounded-xl border border-border/80 bg-card px-5 shadow-2xs"
                                >
                                    <AccordionTrigger className="py-3.5 text-xs font-bold text-foreground hover:no-underline">
                                        <span className="flex items-center gap-2">
                                            <SectionIcon className="size-4 shrink-0 text-primary" />
                                            {section.title}
                                        </span>
                                    </AccordionTrigger>
                                    <AccordionContent className="pt-1 pb-5">
                                        <OrganizationExtraSectionContent
                                            section={section}
                                            organization={organization}
                                            canManage={canManage}
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

function CreateHierarchyDialog({
    organizations,
    purposes,
}: Pick<Props, 'organizations' | 'purposes'>) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        name: '',
        purpose_codes: [] as string[],
        root_organization_id: organizations[0]?.id ?? '',
        effective_from: new Date().toISOString().slice(0, 10),
    });

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" className="text-xs font-medium shadow-xs">
                    <Plus className="mr-1.5 size-3.5" /> Hierarchy Baru
                </Button>
            </DialogTrigger>
            <DialogContent size="wide">
                <DialogHeader>
                    <div className="flex items-center gap-3">
                        <div className="flex size-10 shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                            <Network className="size-5" />
                        </div>
                        <div>
                            <DialogTitle>Draft Hierarchy Baru</DialogTitle>
                            <DialogDescription>
                                Satu hierarchy dapat dipakai oleh beberapa
                                tujuan proses bisnis yang memiliki susunan sama.
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/settings/organization/hierarchies', {
                            onSuccess: () => {
                                form.reset();
                                setOpen(false);
                            },
                        });
                    }}
                >
                    <DialogBody className="space-y-4 py-3">
                        <FieldGroup>
                            <Field data-invalid={Boolean(form.errors.name)}>
                                <Input
                                    label="Nama Hierarchy"
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                    placeholder="Contoh: Bagan Organisasi Holding Utama"
                                    aria-invalid={Boolean(form.errors.name)}
                                />
                                <FieldError>{form.errors.name}</FieldError>
                            </Field>
                            <FieldSet
                                data-invalid={Boolean(
                                    form.errors.purpose_codes,
                                )}
                            >
                                <FieldLegend hint="Pilih proses bisnis yang akan membaca susunan ini.">
                                    Tujuan Hierarchy
                                </FieldLegend>
                                <ToggleGroup
                                    type="multiple"
                                    variant="outline"
                                    className="grid grid-cols-2 gap-2"
                                    value={form.data.purpose_codes}
                                    onValueChange={(values) =>
                                        form.setData('purpose_codes', values)
                                    }
                                >
                                    {purposes.map((purpose) => (
                                        <ToggleGroupItem
                                            key={purpose.code}
                                            value={purpose.code}
                                            className="border-border/80 text-xs font-medium"
                                        >
                                            {purpose.name}
                                        </ToggleGroupItem>
                                    ))}
                                </ToggleGroup>
                                <FieldError>
                                    {form.errors.purpose_codes}
                                </FieldError>
                            </FieldSet>
                            <div className="grid gap-4 md:grid-cols-2">
                                <Field
                                    data-invalid={Boolean(
                                        form.errors.root_organization_id,
                                    )}
                                >
                                    <NativeSelect
                                        label="Organisasi Paling Atas (Root)"
                                        value={form.data.root_organization_id}
                                        onChange={(event) =>
                                            form.setData(
                                                'root_organization_id',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={Boolean(
                                            form.errors.root_organization_id,
                                        )}
                                    >
                                        {organizations.map((organization) => (
                                            <option
                                                key={organization.id}
                                                value={organization.id}
                                            >
                                                {organization.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                    <FieldError>
                                        {form.errors.root_organization_id}
                                    </FieldError>
                                </Field>
                                <Field
                                    data-invalid={Boolean(
                                        form.errors.effective_from,
                                    )}
                                >
                                    <Input
                                        label="Berlaku Mulai Tanggal"
                                        type="date"
                                        value={form.data.effective_from}
                                        onChange={(event) =>
                                            form.setData(
                                                'effective_from',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={Boolean(
                                            form.errors.effective_from,
                                        )}
                                    />
                                    <FieldError>
                                        {form.errors.effective_from}
                                    </FieldError>
                                </Field>
                            </div>
                        </FieldGroup>
                    </DialogBody>
                    <DialogFooter>
                        <DialogAction type="submit" disabled={form.processing}>
                            Buat Draf Hierarchy
                        </DialogAction>
                        <DialogCancel />
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function DraftActions({
    version,
    organizations,
}: {
    version: Version;
    organizations: Organization[];
}) {
    const placedIds = new Set(
        version.nodes.map((node) => node.organization.id),
    );
    const unplaced = organizations.filter(
        (organization) => !placedIds.has(organization.id),
    );
    const form = useForm({
        organization_id: unplaced[0]?.id ?? '',
        parent_organization_id: version.nodes[0]?.organization.id ?? '',
    });

    return (
        <form
            className="flex flex-col gap-3 rounded-xl border border-border/80 bg-card p-4 shadow-2xs"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(
                    `/settings/organization/hierarchy-versions/${version.id}/placements`,
                    {
                        preserveScroll: true,
                        onSuccess: () => form.setData('organization_id', ''),
                    },
                );
            }}
        >
            <div className="grid gap-3 md:grid-cols-2">
                <Field data-invalid={Boolean(form.errors.organization_id)}>
                    <NativeSelect
                        label="Organisasi Yang Ditambahkan"
                        value={form.data.organization_id}
                        onChange={(event) =>
                            form.setData('organization_id', event.target.value)
                        }
                        disabled={!unplaced.length}
                    >
                        <option value="">Pilih organisasi</option>
                        {unplaced.map((organization) => (
                            <option
                                key={organization.id}
                                value={organization.id}
                            >
                                {organization.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <FieldError>{form.errors.organization_id}</FieldError>
                </Field>
                <Field
                    data-invalid={Boolean(form.errors.parent_organization_id)}
                >
                    <NativeSelect
                        label="Berada Di Bawah Parent"
                        value={form.data.parent_organization_id}
                        onChange={(event) =>
                            form.setData(
                                'parent_organization_id',
                                event.target.value,
                            )
                        }
                    >
                        {version.nodes.map((node) => (
                            <option
                                key={node.organization.id}
                                value={node.organization.id}
                            >
                                {node.organization.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <FieldError>
                        {form.errors.parent_organization_id}
                    </FieldError>
                </Field>
            </div>
            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border/40 pt-2">
                <p className="text-xs text-muted-foreground">
                    Hubungan ini berlaku pada draf hierarchy ini untuk menyusun
                    struktur pohon.
                </p>
                <div className="flex gap-2">
                    <Button
                        variant="outline"
                        type="submit"
                        size="sm"
                        className="text-xs font-medium"
                        disabled={!unplaced.length || form.processing}
                    >
                        <Plus className="mr-1.5 size-3.5" /> Tambah Penempatan
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        className="text-xs font-medium shadow-xs"
                        disabled={form.processing}
                        onClick={() =>
                            form.post(
                                `/settings/organization/hierarchy-versions/${version.id}/publish`,
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Check className="mr-1.5 size-3.5" /> Publikasikan
                    </Button>
                </div>
            </div>
        </form>
    );
}

type OrganizationHierarchyFlowData = {
    label: string;
    classification: Organization['classification'];
    operatingUnitType: string | null;
    operatingUnitNumber: string | null;
    needsNumber: boolean;
};
type OrganizationHierarchyFlowNode = FlowNode<
    OrganizationHierarchyFlowData,
    'organization'
>;

function OrganizationHierarchyFlowNode({
    data,
}: NodeProps<OrganizationHierarchyFlowNode>) {
    return (
        <div className="min-w-56 rounded-xl border border-border bg-card px-4 py-3 text-foreground shadow-xs">
            <Handle
                type="target"
                position={Position.Top}
                className="!size-3 !border-2 !border-background !bg-muted-foreground"
            />
            <div className="flex items-center gap-2">
                <span className="flex size-5 items-center justify-center rounded bg-primary/10 text-[10px] font-bold text-primary">
                    {data.classification === 'legal_entity' ? 'LE' : 'OU'}
                </span>
                <p className="truncate text-xs font-bold text-foreground">
                    {data.label}
                </p>
            </div>
            <p className="mt-1 text-[11px] text-muted-foreground">
                {data.classification === 'legal_entity'
                    ? 'Legal Entity (Badan Hukum)'
                    : [
                          data.operatingUnitType ?? 'Operating Unit',
                          data.operatingUnitNumber,
                      ]
                          .filter(Boolean)
                          .join(' · ')}
            </p>
            {data.needsNumber && (
                <div className="mt-1.5">
                    <NomorBelumAda />
                </div>
            )}
            <Handle
                type="source"
                position={Position.Bottom}
                className="!size-3 !border-2 !border-background !bg-muted-foreground"
            />
        </div>
    );
}

const organizationHierarchyNodeTypes: NodeTypes = {
    organization: OrganizationHierarchyFlowNode,
};

function buildHierarchyGraph(
    version: Version,
    operatingUnitTypes: Props['operatingUnitTypes'],
): {
    nodes: OrganizationHierarchyFlowNode[];
    edges: Edge[];
} {
    const nodeById = new Map(version.nodes.map((node) => [node.id, node]));
    const childrenByParent = new Map<string, HierarchyNode[]>();
    const roots: HierarchyNode[] = [];

    for (const node of version.nodes) {
        const parentId = node.parent_node?.id;

        if (!parentId || !nodeById.has(parentId)) {
            roots.push(node);
            continue;
        }

        const children = childrenByParent.get(parentId) ?? [];
        children.push(node);
        childrenByParent.set(parentId, children);
    }

    const nodeWidth = 224;
    const horizontalGap = 64;
    const verticalGap = 180;
    const subtreeWidths = new Map<string, number>();
    const measure = (node: HierarchyNode, path = new Set<string>()): number => {
        if (path.has(node.id)) {
            return nodeWidth;
        }

        const known = subtreeWidths.get(node.id);

        if (known !== undefined) {
            return known;
        }

        const children = childrenByParent.get(node.id) ?? [];
        const nextPath = new Set(path).add(node.id);
        const childrenWidth = children.reduce(
            (total, child, index) =>
                total +
                measure(child, nextPath) +
                (index > 0 ? horizontalGap : 0),
            0,
        );
        const width = Math.max(nodeWidth, childrenWidth);
        subtreeWidths.set(node.id, width);

        return width;
    };
    const positions = new Map<string, { x: number; y: number }>();
    const place = (
        node: HierarchyNode,
        left: number,
        depth: number,
        path = new Set<string>(),
    ): void => {
        if (path.has(node.id)) {
            return;
        }

        const width = subtreeWidths.get(node.id) ?? measure(node);
        positions.set(node.id, {
            x: left + (width - nodeWidth) / 2,
            y: depth * verticalGap,
        });

        const children = childrenByParent.get(node.id) ?? [];
        const childWidths = children.map(
            (child) => subtreeWidths.get(child.id) ?? measure(child),
        );
        const childrenWidth = childWidths.reduce(
            (total, childWidth, index) =>
                total + childWidth + (index > 0 ? horizontalGap : 0),
            0,
        );
        let childLeft = left + (width - childrenWidth) / 2;
        const nextPath = new Set(path).add(node.id);

        children.forEach((child, index) => {
            place(child, childLeft, depth + 1, nextPath);
            childLeft += childWidths[index] + horizontalGap;
        });
    };

    const layoutRoots = roots.length ? roots : version.nodes.slice(0, 1);
    let rootLeft = 0;

    for (const root of layoutRoots) {
        const width = measure(root);
        place(root, rootLeft, 0);
        rootLeft += width + horizontalGap;
    }

    const nodes = version.nodes.map((node) => ({
        id: node.id,
        type: 'organization' as const,
        position: positions.get(node.id) ?? { x: 0, y: 0 },
        data: {
            label: node.organization.name,
            classification: node.organization.classification,
            // Label tipe, bukan kodenya: daftar operating unit memakai label yang sama.
            operatingUnitType: node.organization.operating_unit
                ? (operatingUnitTypes[node.organization.operating_unit.type] ??
                  node.organization.operating_unit.type)
                : null,
            operatingUnitNumber:
                node.organization.operating_unit?.number ?? null,
            needsNumber: perluNomor(node.organization.operating_unit),
        },
    }));
    const edges = version.nodes.flatMap((node) =>
        node.parent_node
            ? [
                  {
                      id: `${node.parent_node.id}-${node.id}`,
                      source: node.parent_node.id,
                      target: node.id,
                      type: 'smoothstep',
                  },
              ]
            : [],
    );

    return { nodes, edges };
}

function HierarchyCanvas({
    version,
    operatingUnitTypes,
}: {
    version: Version;
    operatingUnitTypes: Props['operatingUnitTypes'];
}) {
    const graph = buildHierarchyGraph(version, operatingUnitTypes);

    return (
        <div className="overflow-hidden rounded-xl border border-border bg-background shadow-xs">
            <div className="flex items-center justify-between border-b border-border bg-card/80 px-4 py-2.5 text-xs font-semibold text-foreground">
                <span className="flex items-center gap-2">
                    <Network className="size-4 text-primary" /> Visualisasi
                    Organigram Struktur Perusahaan
                </span>
                <Badge
                    variant="outline"
                    className="border-primary/20 bg-primary/5 font-mono text-[10px] text-primary"
                >
                    {version.nodes.length} Unit Terhubung
                </Badge>
            </div>
            <div className="h-[520px] bg-background">
                <ReactFlow
                    nodes={graph.nodes}
                    edges={graph.edges}
                    nodeTypes={organizationHierarchyNodeTypes}
                    nodesConnectable={false}
                    nodesDraggable={false}
                    fitView
                    proOptions={{ hideAttribution: true }}
                >
                    <Background gap={18} size={1} />
                    <Controls />
                    <MiniMap
                        pannable
                        zoomable
                        className="!right-3 !bottom-3 overflow-hidden !rounded-lg border border-border/60"
                    />
                </ReactFlow>
            </div>
        </div>
    );
}

function RemovePlacementAction({
    version,
    node,
}: {
    version: Version;
    node: HierarchyNode;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({});

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogTrigger asChild>
                <Button type="button" variant="outline" size="sm">
                    <Trash2 />
                    Batalkan
                </Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Batalkan penempatan?</AlertDialogTitle>
                    <AlertDialogDescription>
                        {node.organization.name} akan dilepas dari draft ini dan
                        dapat ditempatkan kembali di bawah organisasi yang
                        benar.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={form.processing}>
                        Kembali
                    </AlertDialogCancel>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={form.processing}
                        onClick={() =>
                            form.delete(
                                `/settings/organization/hierarchy-versions/${version.id}/placements/${node.id}`,
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setOpen(false),
                                },
                            )
                        }
                    >
                        Batalkan Penempatan
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

function CreateVersionDraftAction({ version }: { version: Version }) {
    const form = useForm({
        effective_from: new Date().toISOString().slice(0, 10),
    });
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    className="text-xs font-medium"
                >
                    <Plus className="mr-1.5 size-3.5" /> Buat Versi Baru
                </Button>
            </DialogTrigger>
            <DialogContent size="wide">
                <DialogHeader>
                    <DialogTitle>Buat Draft Versi Baru</DialogTitle>
                    <DialogDescription>
                        Versi yang sudah dipublikasikan tetap menjadi riwayat
                        resmi.
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(
                            `/settings/organization/hierarchy-versions/${version.id}/drafts`,
                            { onSuccess: () => setOpen(false) },
                        );
                    }}
                >
                    <DialogBody className="space-y-4 py-3">
                        <FieldGroup>
                            <Field
                                data-invalid={Boolean(
                                    form.errors.effective_from,
                                )}
                            >
                                <Input
                                    label="Berlaku Mulai Tanggal"
                                    type="date"
                                    value={form.data.effective_from}
                                    onChange={(event) =>
                                        form.setData(
                                            'effective_from',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={Boolean(
                                        form.errors.effective_from,
                                    )}
                                />
                                <FieldError>
                                    {form.errors.effective_from}
                                </FieldError>
                            </Field>
                        </FieldGroup>
                    </DialogBody>
                    <DialogFooter>
                        <DialogAction type="submit" disabled={form.processing}>
                            Buat Draft
                        </DialogAction>
                        <DialogCancel />
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function OrganizationPage({
    canManage,
    section,
    tenant,
    organizations,
    hierarchies,
    purposes,
    operatingUnitTypes,
}: Props) {
    const [selectedOrganizationId, setSelectedOrganizationId] = useState<
        string | null
    >(null);
    const [organizationSearch, setOrganizationSearch] = useState('');
    const isHierarchySection = section === 'hierarchies';
    const classification =
        section === 'operating-units' ? 'operating_unit' : 'legal_entity';

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
        ) ??
        filteredOrganizations[0] ??
        null;

    return (
        <>
            <Head title="Organisasi" />
            <main className="mx-auto flex min-h-screen w-full max-w-7xl min-w-0 flex-col gap-6 p-6">
                {/* Header Judul Utama */}
                <Heading
                    title="Organisasi Perusahaan"
                    description={`Pengelolaan identitas entitas legal, unit operasional, dan bagan hirarki organisasi untuk ${tenant.name}.`}
                />

                {/* Navigasi Tab Bagian Organisasi */}
                <nav
                    aria-label="Bagian organisasi"
                    className="flex flex-wrap gap-1.5 rounded-xl border border-border/80 bg-muted/40 p-1.5 shadow-2xs"
                >
                    {[
                        ['legal-entities', 'Legal Entities', Building2],
                        ['operating-units', 'Operating Units', Layers],
                        ['hierarchies', 'Hierarchy Organisasi', Network],
                    ].map(([value, label, IconComp]) => {
                        const isActive = section === value;

                        return (
                            <Link
                                key={value as string}
                                href={`/settings/organization?section=${value}`}
                                aria-current={isActive ? 'page' : undefined}
                                className={`flex items-center gap-2 rounded-lg px-4 py-2 text-xs font-semibold transition-all ${
                                    isActive
                                        ? 'border border-border/80 bg-card font-bold text-foreground shadow-xs'
                                        : 'text-muted-foreground hover:bg-background/60 hover:text-foreground'
                                }`}
                            >
                                <IconComp
                                    className={`size-3.5 ${isActive ? 'text-primary' : 'text-muted-foreground'}`}
                                />
                                {label as string}
                            </Link>
                        );
                    })}
                </nav>

                {!isHierarchySection && (
                    <Card className="w-full min-w-0 overflow-hidden border border-border shadow-xs">
                        <CardHeader className="border-b border-border bg-card/50 px-6 py-4">
                            <div className="flex w-full items-center justify-between gap-4">
                                <div className="flex min-w-0 flex-1 items-center gap-3">
                                    <div className="flex size-9 shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                                        {classification === 'legal_entity' ? (
                                            <Building2 className="size-4" />
                                        ) : (
                                            <Layers className="size-4" />
                                        )}
                                    </div>
                                    <div className="min-w-0">
                                        <CardTitle>
                                            {classification === 'legal_entity'
                                                ? 'Daftar Legal Entities'
                                                : 'Daftar Operating Units'}
                                        </CardTitle>
                                        <CardDescription className="mt-0.5 text-sm text-muted-foreground">
                                            {classification === 'legal_entity'
                                                ? 'Badan hukum untuk transaksi resmi, pajak, dan laporan.'
                                                : 'Unit operasional untuk proses, akses, dan hierarchy organisasi.'}
                                        </CardDescription>
                                    </div>
                                </div>
                                {canManage && (
                                    <CardAction className="shrink-0">
                                        <CreateOrganizationDialog
                                            classification={classification}
                                            operatingUnitTypes={
                                                operatingUnitTypes
                                            }
                                            triggerLabel={
                                                classification ===
                                                'legal_entity'
                                                    ? 'Legal Entity'
                                                    : 'Operating Unit'
                                            }
                                        />
                                    </CardAction>
                                )}
                            </div>
                        </CardHeader>
                        <CardContent className="p-0">
                            {visibleOrganizations.length ? (
                                <div className="grid min-w-0 gap-0 lg:h-[calc(100vh-16rem)] lg:min-h-[34rem] lg:grid-cols-[20rem_minmax(0,1fr)]">
                                    {/* Sidebar Daftar Organisasi Kiri */}
                                    <aside className="flex min-w-0 flex-col overflow-hidden border-r border-border bg-card/40">
                                        <div className="shrink-0 space-y-2 border-b border-border p-4">
                                            <div className="flex items-center justify-between">
                                                <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                    Pilih Organisasi
                                                </p>
                                                <Badge
                                                    variant="outline"
                                                    className="text-[10px]"
                                                >
                                                    {
                                                        filteredOrganizations.length
                                                    }{' '}
                                                    Total
                                                </Badge>
                                            </div>
                                            <div className="relative">
                                                <Search className="absolute top-2.5 left-2.5 size-3.5 text-muted-foreground" />
                                                <Input
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
                                                                      .join(
                                                                          ' · ',
                                                                      );

                                                        return (
                                                            <button
                                                                key={
                                                                    organization.id
                                                                }
                                                                type="button"
                                                                aria-pressed={
                                                                    selected
                                                                }
                                                                onClick={() =>
                                                                    setSelectedOrganizationId(
                                                                        organization.id,
                                                                    )
                                                                }
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
                                                                        {perluNomor(
                                                                            organization.operating_unit,
                                                                        ) && (
                                                                            <span className="mt-1 block">
                                                                                <NomorBelumAda />
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
                                                    Tidak ada organisasi yang
                                                    cocok.
                                                </p>
                                            )}
                                        </div>
                                    </aside>

                                    {/* Section Detail Organisasi Terpilih */}
                                    {selectedOrganization && (
                                        <div className="flex min-w-0 overflow-hidden bg-card">
                                            <OrganizationDetailPage
                                                key={selectedOrganization.id}
                                                organization={
                                                    selectedOrganization
                                                }
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
                )}

                {isHierarchySection && (
                    <Card className="overflow-hidden border border-border shadow-xs">
                        <CardHeader className="border-b border-border bg-card/50 px-6 py-4">
                            <div className="flex w-full items-center justify-between gap-4">
                                <div className="flex min-w-0 flex-1 items-center gap-3">
                                    <div className="flex size-9 shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                                        <Network className="size-4" />
                                    </div>
                                    <div className="min-w-0">
                                        <CardTitle>
                                            Hierarchy Organisasi
                                        </CardTitle>
                                        <CardDescription className="mt-0.5 text-sm text-muted-foreground">
                                            Susun hubungan parent-child hanya
                                            untuk proses bisnis yang
                                            membutuhkannya.
                                        </CardDescription>
                                    </div>
                                </div>
                                {canManage && (
                                    <CardAction className="shrink-0">
                                        <CreateHierarchyDialog
                                            organizations={organizations}
                                            purposes={purposes}
                                        />
                                    </CardAction>
                                )}
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-8 p-6">
                            {hierarchies.length ? (
                                hierarchies.map((hierarchy) => {
                                    const version = hierarchy.versions[0];

                                    if (!version) {
                                        return null;
                                    }

                                    return (
                                        <div
                                            key={hierarchy.id}
                                            className="space-y-4 border-b border-border pb-8 last:border-b-0 last:pb-0"
                                        >
                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                <div>
                                                    <h3 className="text-base font-bold text-foreground">
                                                        {hierarchy.name}
                                                    </h3>
                                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                                        Tujuan:{' '}
                                                        {hierarchy.purposes
                                                            .map(
                                                                (purpose) =>
                                                                    purpose.name,
                                                            )
                                                            .join(' · ') || '—'}
                                                    </p>
                                                </div>
                                                <Badge variant="outline">
                                                    {version.status === 'draft'
                                                        ? 'Draft'
                                                        : `Published v${version.version_number}`}
                                                </Badge>
                                            </div>

                                            <HierarchyCanvas
                                                version={version}
                                                operatingUnitTypes={
                                                    operatingUnitTypes
                                                }
                                            />

                                            <div className="space-y-3 pt-2">
                                                <div className="flex items-center justify-between">
                                                    <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                        Daftar Penempatan Unit
                                                        Organisasi
                                                    </p>
                                                    <span className="text-xs text-muted-foreground">
                                                        {version.nodes.length}{' '}
                                                        Unit Terpasang
                                                    </span>
                                                </div>
                                                <div className="grid gap-2.5 md:grid-cols-2 lg:grid-cols-3">
                                                    {version.nodes.map(
                                                        (node) => (
                                                            <div
                                                                key={node.id}
                                                                className="flex items-center justify-between gap-2 rounded-lg border border-border/80 bg-card px-3.5 py-2.5 text-xs shadow-2xs"
                                                            >
                                                                <div className="min-w-0 flex-1">
                                                                    <span className="block truncate font-bold text-foreground">
                                                                        {
                                                                            node
                                                                                .organization
                                                                                .name
                                                                        }
                                                                        {node
                                                                            .organization
                                                                            .operating_unit
                                                                            ?.number && (
                                                                            <span className="ml-1.5 font-mono font-normal text-muted-foreground">
                                                                                {
                                                                                    node
                                                                                        .organization
                                                                                        .operating_unit
                                                                                        .number
                                                                                }
                                                                            </span>
                                                                        )}
                                                                    </span>
                                                                    <span className="mt-0.5 block truncate text-[11px] text-muted-foreground">
                                                                        {node.parent_node
                                                                            ? `Di bawah ${node.parent_node.organization.name}`
                                                                            : 'Root (Organisasi Puncak)'}
                                                                    </span>
                                                                </div>
                                                                {canManage &&
                                                                    version.status ===
                                                                        'draft' &&
                                                                    node.parent_node && (
                                                                        <RemovePlacementAction
                                                                            version={
                                                                                version
                                                                            }
                                                                            node={
                                                                                node
                                                                            }
                                                                        />
                                                                    )}
                                                            </div>
                                                        ),
                                                    )}
                                                </div>
                                            </div>

                                            {canManage &&
                                                version.status === 'draft' && (
                                                    <DraftActions
                                                        version={version}
                                                        organizations={
                                                            organizations
                                                        }
                                                    />
                                                )}
                                            {canManage &&
                                                version.status ===
                                                    'published' && (
                                                    <div className="flex justify-end pt-2">
                                                        <CreateVersionDraftAction
                                                            version={version}
                                                        />
                                                    </div>
                                                )}
                                        </div>
                                    );
                                })
                            ) : (
                                <Empty className="py-16">
                                    <EmptyHeader>
                                        <EmptyMedia variant="icon">
                                            <Network className="size-8 text-muted-foreground" />
                                        </EmptyMedia>
                                        <EmptyTitle>
                                            Belum Ada Hierarchy Organisasi
                                        </EmptyTitle>
                                        <EmptyDescription className="max-w-sm text-xs text-muted-foreground">
                                            Buat hierarchy baru untuk menyusun
                                            bagan organigram dan struktur
                                            hubungan antar unit bisnis.
                                        </EmptyDescription>
                                    </EmptyHeader>
                                </Empty>
                            )}
                        </CardContent>
                    </Card>
                )}
            </main>
        </>
    );
}

OrganizationPage.layout = {
    breadcrumbs: [
        { title: 'Settings', href: '/settings/organization' },
        { title: 'Organization', href: '/settings/organization' },
    ],
};
