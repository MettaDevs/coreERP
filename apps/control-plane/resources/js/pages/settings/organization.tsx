import { Head, Link, useForm } from '@inertiajs/react';
import {
    Background,
    Controls,
    Handle,
    MiniMap,
    Position,
    ReactFlow,
} from '@xyflow/react';
import type { Edge, Node as FlowNode, NodeProps, NodeTypes } from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import { Building2, Network, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

import Heading from '@/components/heading';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
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
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@apperp/ui/accordion';
import {
    Dialog,
    DialogContent,
    DialogDescription,
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

type Organization = {
    id: string;
    name: string;
    classification: 'legal_entity' | 'operating_unit';
    status: string;
    legal_entity: { company_code: string; country_code: string } | null;
    operating_unit: { type: string } | null;
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
        operating_unit?: { type: string } | null;
    };
    parent_node: { id: string; organization: Pick<Organization, 'id' | 'name'> } | null;
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
    });
    const legalEntity = classification === 'legal_entity';

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <Plus />
                    {triggerLabel}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {legalEntity
                            ? 'Legal entity baru'
                            : 'Operating unit baru'}
                    </DialogTitle>
                    <DialogDescription>
                        {legalEntity
                            ? 'Simpan identitas badan hukum yang dipakai untuk transaksi resmi.'
                            : 'Simpan unit operasional yang akan ditempatkan pada hierarchy bila diperlukan.'}
                    </DialogDescription>
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
                    <FieldGroup>
                        <Field data-invalid={Boolean(form.errors.name)}>
                            <Input
                                label="Nama organisasi"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
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
                                        label="Kode perusahaan"
                                        value={form.data.company_code}
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
                                    <FieldDescription>
                                        2–16 karakter: huruf, angka, atau tanda
                                        hubung.
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
                                        label="Kode negara"
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
                                    />
                                    <FieldError>
                                        {form.errors.country_code}
                                    </FieldError>
                                </Field>
                            </>
                        ) : (
                            <Field
                                data-invalid={Boolean(
                                    form.errors.operating_unit_type,
                                )}
                            >
                                <NativeSelect
                                    label="Tipe operating unit"
                                    value={form.data.operating_unit_type}
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
                                    {Object.entries(operatingUnitTypes).map(
                                        ([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ),
                                    )}
                                </NativeSelect>
                                <FieldError>
                                    {form.errors.operating_unit_type}
                                </FieldError>
                            </Field>
                        )}
                        <Button type="submit" disabled={form.processing}>
                            Simpan organisasi
                        </Button>
                    </FieldGroup>
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
}: {
    section: OrganizationExtraSection;
}) {
    if (section.value === 'report-company-logo') {
        return (
            <div className="space-y-3 rounded-md border border-dashed bg-slate-50 p-4 dark:bg-white">
                <Field>
                    <Input
                        label="Logo perusahaan untuk laporan"
                        type="file"
                        disabled
                    />
                </Field>
                <p className="text-xs text-muted-foreground">
                    Wadah sudah tersedia. Upload logo belum aktif karena integrasi file belum tersedia.
                </p>
            </div>
        );
    }

    return (
        <div className="rounded-md border border-dashed bg-slate-50 p-4 dark:bg-white">
            <p className="text-sm text-muted-foreground">{section.description}</p>
            {section.action && (
                <Button type="button" variant="outline" className="mt-3" disabled>
                    {section.action}
                </Button>
            )}
            <p className="mt-2 text-xs text-muted-foreground">
                Wadah tersedia; fungsi ini akan dihubungkan oleh modul pemiliknya.
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
    });
    const unitType = operatingUnitTypes[organization.operating_unit?.type ?? ''] ?? organization.operating_unit?.type;
    const extraSections: OrganizationExtraSection[] = legalEntity
        ? [
              {
                  value: 'addresses',
                  title: 'Alamat',
                  description: 'Simpan alamat utama dan alamat tambahan legal entity.',
                  action: 'Tambah alamat',
              },
              {
                  value: 'contact-information',
                  title: 'Informasi kontak',
                  description: 'Simpan email, nomor telepon, dan kontak organisasi.',
                  action: 'Tambah kontak',
              },
              {
                  value: 'statutory-reporting',
                  title: 'Pelaporan wajib',
                  description: 'Tempat untuk konfigurasi pelaporan resmi dan periode pelaporan.',
                  action: 'Tambah pengaturan pelaporan',
              },
              {
                  value: 'registration-numbers',
                  title: 'Nomor registrasi',
                  description: 'Tempat untuk nomor registrasi badan hukum.',
                  action: 'Tambah nomor registrasi',
              },
              {
                  value: 'bank-account-information',
                  title: 'Informasi rekening bank',
                  description: 'Tempat untuk rekening bank yang terkait dengan legal entity.',
                  action: 'Tambah rekening bank',
              },
              {
                  value: 'foreign-trade-and-logistics',
                  title: 'Perdagangan luar negeri dan logistik',
                  description: 'Tempat untuk pengaturan perdagangan lintas negara dan logistik.',
                  action: 'Tambah pengaturan',
              },
              {
                  value: 'number-sequences',
                  title: 'Nomor urut',
                  description: 'Tempat untuk nomor otomatis yang dipakai dokumen organisasi.',
                  action: 'Buka nomor urut',
              },
              {
                  value: 'additional-registration',
                  title: 'Registrasi tambahan',
                  description: 'Tempat untuk data registrasi tambahan yang diperlukan organisasi.',
                  action: 'Tambah registrasi',
              },
              {
                  value: 'dashboard-image',
                  title: 'Gambar dashboard',
                  description: 'Tempat untuk gambar yang ditampilkan pada dashboard organisasi.',
                  action: 'Pilih gambar',
              },
              {
                  value: 'report-company-logo',
                  title: 'Logo perusahaan untuk laporan',
                  description: 'Logo untuk laporan yang menggunakan legal entity ini.',
              },
              {
                  value: 'print-destination-default',
                  title: 'Default tujuan cetak',
                  description: 'Tempat untuk tujuan cetak default organisasi.',
                  action: 'Atur tujuan cetak',
              },
              {
                  value: 'regulatory-establishments',
                  title: 'Instansi regulator',
                  description: 'Tempat untuk instansi regulator yang terkait organisasi.',
                  action: 'Tambah instansi',
              },
              {
                  value: 'tax-registration',
                  title: 'Registrasi pajak',
                  description: 'Tempat untuk data registrasi pajak legal entity.',
                  action: 'Tambah registrasi pajak',
              },
          ]
        : [
              {
                  value: 'addresses',
                  title: 'Alamat',
                  description: 'Simpan alamat unit operasional.',
                  action: 'Tambah alamat',
              },
              {
                  value: 'contact-information',
                  title: 'Informasi kontak',
                  description: 'Simpan email, nomor telepon, dan kontak unit operasional.',
                  action: 'Tambah kontak',
              },
              {
                  value: 'operating-unit-details',
                  title: 'Detail operating unit',
                  description: `Tempat untuk detail khusus tipe ${unitType ?? 'operating unit'}.`,
                  action: 'Buka detail tipe unit',
              },
          ];

    return (
        <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col bg-white text-slate-900 dark:bg-white dark:text-slate-900">
            <div className="sticky top-0 z-10 flex min-w-0 shrink-0 flex-wrap items-start justify-between gap-3 border-b bg-white px-4 py-4 dark:bg-white">
                <div className="min-w-0">
                    <CardTitle>{organization.name}</CardTitle>
                    <div className="mt-2 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                        <Badge variant="outline">
                            {legalEntity ? 'Legal entity' : 'Operating unit'}
                        </Badge>
                        <span>
                            {legalEntity
                                ? `${organization.legal_entity?.company_code ?? 'Belum ada kode'} · ${organization.legal_entity?.country_code ?? 'Belum ada negara'}`
                                : unitType}
                        </span>
                    </div>
                </div>
                {canManage && (
                    <div className="shrink-0">
                        {!editing ? (
                            <Button type="button" onClick={() => setEditing(true)}>
                                <Pencil />
                                Edit
                            </Button>
                        ) : (
                            <div className="flex gap-2">
                                <Button
                                    type="button"
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
                                <Button
                                    type="submit"
                                    form={`organization-form-${organization.id}`}
                                    disabled={form.processing}
                                >
                                    Simpan
                                </Button>
                            </div>
                        )}
                    </div>
                )}
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto p-4">
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
                    <Accordion type="multiple" defaultValue={['general']}>
                        <AccordionItem value="general">
                            <AccordionTrigger className="py-3 hover:no-underline">
                                Umum
                            </AccordionTrigger>
                            <AccordionContent className="pt-2">
                                <FieldGroup className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <Field data-invalid={Boolean(form.errors.name)}>
                            <Input
                                label="Nama organisasi"
                                value={form.data.name}
                                disabled={!editing}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
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
                                        label="Kode perusahaan"
                                        value={form.data.company_code}
                                        disabled={!editing}
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
                                        label="Kode negara"
                                        value={form.data.country_code}
                                        disabled={!editing}
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
                            </>
                        ) : (
                            <Field
                                data-invalid={Boolean(
                                    form.errors.operating_unit_type,
                                )}
                            >
                                <NativeSelect
                                    label="Tipe operating unit"
                                    value={form.data.operating_unit_type}
                                    disabled={!editing}
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
                                    {Object.entries(operatingUnitTypes).map(
                                        ([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ),
                                    )}
                                </NativeSelect>
                                <FieldError>
                                    {form.errors.operating_unit_type}
                                </FieldError>
                            </Field>
                        )}
                                </FieldGroup>
                            </AccordionContent>
                        </AccordionItem>
                        {extraSections.map((section) => (
                            <AccordionItem
                                key={section.value}
                                value={section.value}
                            >
                                <AccordionTrigger className="py-3 hover:no-underline">
                                    {section.title}
                                </AccordionTrigger>
                                <AccordionContent className="pt-2">
                                    <OrganizationExtraSectionContent section={section} />
                                </AccordionContent>
                            </AccordionItem>
                        ))}
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
                <Button variant="outline" disabled={!organizations.length}>
                    <Plus />
                    Buat hierarchy
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Draft hierarchy baru</DialogTitle>
                    <DialogDescription>
                        Satu hierarchy dapat dipakai beberapa tujuan bila
                        susunan organisasinya sama.
                    </DialogDescription>
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
                    <FieldGroup>
                        <Field data-invalid={Boolean(form.errors.name)}>
                            <Input
                                label="Nama hierarchy"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                aria-invalid={Boolean(form.errors.name)}
                            />
                            <FieldError>{form.errors.name}</FieldError>
                        </Field>
                        <FieldSet
                            data-invalid={Boolean(form.errors.purpose_codes)}
                        >
                            <FieldLegend hint="Pilih proses bisnis yang akan membaca susunan ini.">
                                Tujuan hierarchy
                            </FieldLegend>
                            <ToggleGroup
                                type="multiple"
                                variant="outline"
                                className="grid grid-cols-2"
                                value={form.data.purpose_codes}
                                onValueChange={(values) =>
                                    form.setData('purpose_codes', values)
                                }
                            >
                                {purposes.map((purpose) => (
                                    <ToggleGroupItem
                                        key={purpose.code}
                                        value={purpose.code}
                                    >
                                        {purpose.name}
                                    </ToggleGroupItem>
                                ))}
                            </ToggleGroup>
                            <FieldError>{form.errors.purpose_codes}</FieldError>
                        </FieldSet>
                        <Field
                            data-invalid={Boolean(
                                form.errors.root_organization_id,
                            )}
                        >
                            <NativeSelect
                                label="Organisasi paling atas"
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
                            data-invalid={Boolean(form.errors.effective_from)}
                        >
                            <Input
                                label="Berlaku mulai"
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
                        <Button type="submit" disabled={form.processing}>
                            Buat draft
                        </Button>
                    </FieldGroup>
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
            className="flex flex-col gap-3 rounded-lg border p-4"
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
                        label="Organisasi yang ditambahkan"
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
                        label="Berada di bawah"
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
            <FieldDescription>
                Hubungan ini hanya berlaku pada draft hierarchy ini, bukan
                mengubah identitas organisasi.
            </FieldDescription>
            <div className="flex justify-end gap-2">
                <Button
                    variant="outline"
                    type="submit"
                    disabled={!unplaced.length || form.processing}
                >
                    Tambah penempatan
                </Button>
                <Button
                    type="button"
                    disabled={form.processing}
                    onClick={() =>
                        form.post(
                            `/settings/organization/hierarchy-versions/${version.id}/publish`,
                            { preserveScroll: true },
                        )
                    }
                >
                    Publikasikan
                </Button>
            </div>
        </form>
    );
}

type OrganizationHierarchyFlowData = {
    label: string;
    classification: Organization['classification'];
    operatingUnitType: string | null;
};
type OrganizationHierarchyFlowNode = FlowNode<
    OrganizationHierarchyFlowData,
    'organization'
>;

function OrganizationHierarchyFlowNode({
    data,
}: NodeProps<OrganizationHierarchyFlowNode>) {
    return (
        <div className="min-w-52 rounded-lg border bg-white px-4 py-3 text-slate-900 shadow-sm dark:bg-white dark:text-slate-900">
            <Handle type="target" position={Position.Top} />
            <p className="text-sm font-semibold">{data.label}</p>
            <p className="mt-1 text-xs text-muted-foreground">
                {data.classification === 'legal_entity'
                    ? 'Legal entity'
                    : data.operatingUnitType ?? 'Operating unit'}
            </p>
            <Handle type="source" position={Position.Bottom} />
        </div>
    );
}

const organizationHierarchyNodeTypes: NodeTypes = {
    organization: OrganizationHierarchyFlowNode,
};

function buildHierarchyGraph(version: Version): {
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

    // ponytail: deterministic tree layout; use a graph layout engine only if hierarchies become DAGs.
    const nodeWidth = 208;
    const horizontalGap = 56;
    const verticalGap = 170;
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
                total + measure(child, nextPath) + (index > 0 ? horizontalGap : 0),
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
        const childWidths = children.map((child) => subtreeWidths.get(child.id) ?? measure(child));
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
            operatingUnitType: node.organization.operating_unit?.type ?? null,
        },
    }));
    const edges = version.nodes.flatMap((node) =>
        node.parent_node
            ? [{
                  id: `${node.parent_node.id}-${node.id}`,
                  source: node.parent_node.id,
                  target: node.id,
                  type: 'smoothstep',
              }]
            : [],
    );

    return { nodes, edges };
}

function HierarchyCanvas({ version }: { version: Version }) {
    const graph = buildHierarchyGraph(version);

    return (
        <div className="overflow-hidden rounded-lg border bg-white dark:bg-white">
            <p className="border-b px-4 py-2 text-xs text-slate-600">
                Tampilan susunan organisasi
            </p>
            <div className="h-[520px]">
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
                    <MiniMap pannable zoomable />
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
                        Batalkan penempatan
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
                <Button variant="outline">Buat versi baru</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Buat draft versi baru</DialogTitle>
                    <DialogDescription>
                        Versi yang sudah dipublikasikan tetap menjadi riwayat.
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
                    <FieldGroup>
                        <Field
                            data-invalid={Boolean(form.errors.effective_from)}
                        >
                            <Input
                                label="Berlaku mulai"
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
                        <Button type="submit" disabled={form.processing}>
                            Buat draft
                        </Button>
                    </FieldGroup>
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
    const [selectedOrganizationId, setSelectedOrganizationId] = useState<string | null>(null);
    const [organizationSearch, setOrganizationSearch] = useState('');
    const isHierarchySection = section === 'hierarchies';
    const classification = section === 'operating-units' ? 'operating_unit' : 'legal_entity';
    const visibleOrganizations = organizations.filter(
        (organization) => organization.classification === classification,
    );
    const organizationSearchTerm = organizationSearch.trim().toLocaleLowerCase();
    const filteredOrganizations = visibleOrganizations.filter((organization) => {
        if (!organizationSearchTerm) {
            return true;
        }

        return [
            organization.name,
            organization.legal_entity?.company_code,
            organization.legal_entity?.country_code,
            organization.operating_unit?.type,
            organization.operating_unit?.type
                ? operatingUnitTypes[organization.operating_unit.type]
                : undefined,
        ].some((value) =>
            value?.toLocaleLowerCase().includes(organizationSearchTerm),
        );
    });
    const selectedOrganization =
        visibleOrganizations.find(
            (organization) => organization.id === selectedOrganizationId,
        ) ?? filteredOrganizations[0] ?? null;
    return (
        <>
            <Head title="Organisasi" />
            <main className="mx-auto flex min-h-screen w-full max-w-7xl min-w-0 flex-col gap-6 p-6">
                <Heading
                    title="Organisasi"
                    description={`Kelola identitas organisasi dan hierarchy ${tenant.name}.`}
                />
                <nav
                    aria-label="Bagian organisasi"
                    className="flex flex-wrap gap-2 rounded-lg border bg-white p-2 dark:bg-white"
                >
                    {[
                        ['legal-entities', 'Legal entities'],
                        ['operating-units', 'Operating units'],
                        ['hierarchies', 'Hierarchy'],
                    ].map(([value, label]) => (
                        <Link
                            key={value}
                            href={`/settings/organization?section=${value}`}
                            aria-current={section === value ? 'page' : undefined}
                            className={`rounded-md px-4 py-2 text-sm font-medium transition-colors ${
                                section === value
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                            }`}
                        >
                            {label}
                        </Link>
                    ))}
                </nav>
                {!isHierarchySection && <Card className="w-full min-w-0 bg-white text-slate-900 dark:bg-white dark:text-slate-900">
                    <CardHeader className="min-w-0">
                        <CardTitle>
                            {classification === 'legal_entity'
                                ? 'Legal entities'
                                : 'Operating units'}
                        </CardTitle>
                        <CardDescription>
                            {classification === 'legal_entity'
                                ? 'Badan hukum untuk transaksi resmi, pajak, dan laporan.'
                                : 'Unit operasional untuk proses, akses, dan hierarchy organisasi.'}
                        </CardDescription>
                        {canManage && (
                            <CardAction>
                                <CreateOrganizationDialog
                                    classification={classification}
                                    operatingUnitTypes={operatingUnitTypes}
                                    triggerLabel="New"
                                />
                            </CardAction>
                        )}
                    </CardHeader>
                    <CardContent className="w-full min-w-0">
                        {visibleOrganizations.length ? (
                            <div className="grid min-w-0 gap-4 lg:h-[calc(100vh-18rem)] lg:min-h-[32rem] lg:max-h-[42.5rem] lg:grid-cols-[18rem_minmax(0,1fr)]">
                                <aside className="flex min-h-[20rem] min-w-0 flex-col overflow-hidden rounded-lg border bg-white dark:bg-white lg:min-h-0">
                                    <div className="shrink-0 space-y-3 border-b px-4 py-3">
                                        <p className="font-medium">Daftar organisasi</p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Pilih satu organisasi untuk melihat detailnya.
                                        </p>
                                        <Input
                                            label="Cari"
                                            placeholder="Cari organisasi..."
                                            value={organizationSearch}
                                            onChange={(event) =>
                                                setOrganizationSearch(event.target.value)
                                            }
                                        />
                                    </div>
                                    <div className="min-h-0 flex-1 overflow-y-auto">
                                        {filteredOrganizations.length ? filteredOrganizations.map((organization) => {
                                            const selected = organization.id === selectedOrganization?.id;
                                            const subtitle = organization.legal_entity
                                                ? `${organization.legal_entity.company_code} · ${organization.legal_entity.country_code}`
                                                : (operatingUnitTypes[
                                                      organization.operating_unit?.type ?? ''
                                                  ] ?? organization.operating_unit?.type);

                                            return (
                                                <button
                                                    key={organization.id}
                                                    type="button"
                                                    aria-pressed={selected}
                                                    onClick={() => setSelectedOrganizationId(organization.id)}
                                                    className={`w-full border-b px-4 py-3 text-left transition-colors last:border-b-0 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-inset dark:hover:bg-slate-100 ${
                                                        selected
                                                            ? 'border-l-2 border-l-primary bg-primary/10'
                                                            : 'border-l-2 border-l-transparent'
                                                    }`}
                                                >
                                                    <span className="block truncate font-medium">
                                                        {organization.name}
                                                    </span>
                                                    <span className="mt-1 block truncate text-xs text-muted-foreground">
                                                        {subtitle}
                                                    </span>
                                                </button>
                                            );
                                        }) : (
                                            <p className="p-4 text-sm text-muted-foreground">
                                                Tidak ada organisasi yang cocok.
                                            </p>
                                        )}
                                    </div>
                                </aside>
                                {selectedOrganization && (
                                    <div className="flex min-h-[32rem] min-w-0 overflow-hidden rounded-lg border bg-white dark:bg-white lg:min-h-0">
                                        <OrganizationDetailPage
                                            key={selectedOrganization.id}
                                            organization={selectedOrganization}
                                            canManage={canManage}
                                            operatingUnitTypes={operatingUnitTypes}
                                        />
                                    </div>
                                )}
                            </div>
                        ) : (
                            <Empty>
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">
                                        <Building2 />
                                    </EmptyMedia>
                                    <EmptyTitle>
                                        Belum ada {classification === 'legal_entity' ? 'legal entity' : 'operating unit'}
                                    </EmptyTitle>
                                    <EmptyDescription>
                                        {classification === 'legal_entity'
                                            ? 'Buat legal entity pertama agar transaksi resmi memiliki badan hukum.'
                                            : 'Buat operating unit bila proses bisnis membutuhkan unit operasional.'}
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        )}
                    </CardContent>
                </Card>}
                {isHierarchySection && <Card className="bg-white text-slate-900 dark:bg-white dark:text-slate-900">
                    <CardHeader>
                        <CardTitle>Hierarchy organisasi</CardTitle>
                        <CardDescription>
                            Susun hubungan parent-child hanya untuk proses bisnis yang membutuhkannya.
                        </CardDescription>
                        {canManage && (
                            <CardAction>
                                <CreateHierarchyDialog
                                    organizations={organizations}
                                    purposes={purposes}
                                />
                            </CardAction>
                        )}
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {hierarchies.length ? (
                            hierarchies.map((hierarchy) => {
                                const version = hierarchy.versions[0];

                                if (!version) {
                                    return null;
                                }

                                return (
                                    <div
                                        key={hierarchy.id}
                                            className="space-y-4 rounded-lg border bg-white p-4 dark:bg-white"
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <h3 className="font-medium">
                                                    {hierarchy.name}
                                                </h3>
                                                <p className="text-sm text-muted-foreground">
                                                    {hierarchy.purposes
                                                        .map(
                                                            (purpose) =>
                                                                purpose.name,
                                                        )
                                                        .join(' · ')}
                                                </p>
                                            </div>
                                            <Badge>
                                                {version.status === 'draft'
                                                    ? 'Draft'
                                                    : `Published v${version.version_number}`}
                                            </Badge>
                                        </div>
                                        <HierarchyCanvas version={version} />
                                        <p className="text-xs text-muted-foreground">
                                            Tipe organisasi yang diizinkan akan diatur per tujuan hierarchy. Saat ini belum ada batasan tipe yang diaktifkan.
                                        </p>
                                        <div className="grid gap-2 md:grid-cols-2 lg:grid-cols-3">
                                            {version.nodes.map((node) => (
                                                <div
                                                    key={node.id}
                                                    className="flex items-start justify-between gap-2 rounded-md bg-muted p-3 text-sm"
                                                >
                                                    <div>
                                                        <span className="font-medium">
                                                            {
                                                                node
                                                                    .organization
                                                                    .name
                                                            }
                                                        </span>
                                                        <span className="block text-muted-foreground">
                                                            {node.parent_node
                                                                ? `Di bawah ${node.parent_node.organization.name}`
                                                                : 'Paling atas'}
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
                                                                node={node}
                                                            />
                                                        )}
                                                </div>
                                            ))}
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
                                            version.status === 'published' && (
                                                <div className="flex justify-end">
                                                    <CreateVersionDraftAction
                                                        version={version}
                                                    />
                                                </div>
                                            )}
                                    </div>
                                );
                            })
                        ) : (
                            <Empty>
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">
                                        <Network />
                                    </EmptyMedia>
                                    <EmptyTitle>Belum ada hierarchy</EmptyTitle>
                                    <EmptyDescription>
                                        Tenant dengan satu legal entity dapat
                                        bekerja tanpa hierarchy.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        )}
                    </CardContent>
                </Card>}
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
