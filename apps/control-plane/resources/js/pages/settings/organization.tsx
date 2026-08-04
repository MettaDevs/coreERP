import { Head, useForm } from '@inertiajs/react';
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
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
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
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@apperp/ui/sheet';
import { ToggleGroup, ToggleGroupItem } from '@apperp/ui/toggle-group';

type Organization = {
    id: string;
    name: string;
    classification: 'legal_entity' | 'operating_unit';
    status: string;
    legal_entity: { company_code: string; country_code: string } | null;
    operating_unit: { type: string } | null;
};
type Purpose = { code: string; name: string; description: string };
type Node = {
    id: string;
    organization: Pick<Organization, 'id' | 'name' | 'classification'>;
    parent_node: { organization: Pick<Organization, 'id' | 'name'> } | null;
};
type Version = {
    id: string;
    version_number: number;
    status: 'draft' | 'published';
    effective_from: string;
    nodes: Node[];
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
    tenant: { id: string; name: string };
    organizations: Organization[];
    hierarchies: Hierarchy[];
    purposes: Purpose[];
    operatingUnitTypes: Record<string, string>;
};

function CreateOrganizationDialog({
    operatingUnitTypes,
}: Pick<Props, 'operatingUnitTypes'>) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        classification: 'legal_entity',
        name: '',
        company_code: '',
        country_code: 'ID',
        operating_unit_type: 'department',
    });
    const legalEntity = form.data.classification === 'legal_entity';

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <Plus />
                    Tambah organisasi
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Organisasi baru</DialogTitle>
                    <DialogDescription>
                        Buat legal entity untuk badan hukum, atau operating unit
                        untuk bagian operasional.
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
                        <Field
                            data-invalid={Boolean(form.errors.classification)}
                        >
                            <NativeSelect
                                label="Jenis organisasi"
                                value={form.data.classification}
                                onChange={(event) =>
                                    form.setData(
                                        'classification',
                                        event.target.value,
                                    )
                                }
                                aria-invalid={Boolean(
                                    form.errors.classification,
                                )}
                            >
                                <option value="legal_entity">
                                    Legal entity
                                </option>
                                <option value="operating_unit">
                                    Operating unit
                                </option>
                            </NativeSelect>
                            <FieldError>
                                {form.errors.classification}
                            </FieldError>
                        </Field>
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

function EditOrganizationSheet({
    organization,
    operatingUnitTypes,
    onClose,
}: {
    organization: Organization;
    operatingUnitTypes: Props['operatingUnitTypes'];
    onClose: () => void;
}) {
    const legalEntity = organization.classification === 'legal_entity';
    const form = useForm({
        name: organization.name,
        company_code: organization.legal_entity?.company_code ?? '',
        country_code: organization.legal_entity?.country_code ?? 'ID',
        operating_unit_type: organization.operating_unit?.type ?? 'department',
    });

    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent
                side="right"
                className="flex w-full flex-col sm:max-w-lg"
            >
                <SheetHeader>
                    <SheetTitle>Ubah organisasi</SheetTitle>
                    <SheetDescription>
                        Klasifikasi organisasi tidak dapat diubah agar data dan
                        riwayat hierarchy tetap konsisten.
                    </SheetDescription>
                </SheetHeader>
                <form
                    className="flex min-h-0 flex-1 flex-col"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.patch(
                            `/settings/organization/organizations/${organization.id}`,
                            {
                                onSuccess: onClose,
                            },
                        );
                    }}
                >
                    <FieldGroup className="flex-1 overflow-y-auto px-4 py-6">
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
                    </FieldGroup>
                    <SheetFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={form.processing}
                        >
                            Batal
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Simpan perubahan
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
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

function RemovePlacementAction({
    version,
    node,
}: {
    version: Version;
    node: Node;
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
    tenant,
    organizations,
    hierarchies,
    purposes,
    operatingUnitTypes,
}: Props) {
    const [editingOrganization, setEditingOrganization] =
        useState<Organization | null>(null);
    const organizationColumns: DataTableColumn<Organization>[] = [
        {
            id: 'name',
            header: 'Organisasi',
            cell: (organization) => (
                <span className="font-medium">{organization.name}</span>
            ),
            sortValue: (organization) => organization.name,
        },
        {
            id: 'classification',
            header: 'Klasifikasi',
            cell: (organization) => (
                <Badge variant="outline">
                    {organization.classification === 'legal_entity'
                        ? 'Legal entity'
                        : 'Operating unit'}
                </Badge>
            ),
            sortValue: (organization) => organization.classification,
        },
        {
            id: 'detail',
            header: 'Detail',
            cell: (organization) =>
                organization.legal_entity
                    ? `${organization.legal_entity.company_code} · ${organization.legal_entity.country_code}`
                    : (operatingUnitTypes[
                          organization.operating_unit?.type ?? ''
                      ] ?? organization.operating_unit?.type),
            sortValue: (organization) =>
                organization.operating_unit?.type ??
                organization.legal_entity?.company_code ??
                '',
        },
        {
            id: 'actions',
            header: 'Aksi',
            align: 'right',
            cell: (organization) =>
                canManage ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setEditingOrganization(organization)}
                    >
                        <Pencil />
                        Ubah
                    </Button>
                ) : null,
        },
    ];

    return (
        <>
            <Head title="Organisasi" />
            <main className="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-6 p-6">
                <Heading
                    title="Organisasi"
                    description={`Kelola identitas organisasi dan hierarchy ${tenant.name}.`}
                />
                <Card>
                    <CardHeader>
                        <CardTitle>Direktori organisasi</CardTitle>
                        <CardDescription>
                            Legal entity dan operating unit dibuat sekali lalu
                            dapat dipakai pada beberapa hierarchy.
                        </CardDescription>
                        {canManage && (
                            <CardAction>
                                <CreateOrganizationDialog
                                    operatingUnitTypes={operatingUnitTypes}
                                />
                            </CardAction>
                        )}
                    </CardHeader>
                    <CardContent>
                        {organizations.length ? (
                            <DataTable
                                columns={organizationColumns}
                                data={organizations}
                                getRowKey={(organization) => organization.id}
                            />
                        ) : (
                            <Empty>
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">
                                        <Building2 />
                                    </EmptyMedia>
                                    <EmptyTitle>
                                        Belum ada organisasi
                                    </EmptyTitle>
                                    <EmptyDescription>
                                        Buat legal entity pertama agar bisnis
                                        dapat mencatat transaksi resmi.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        )}
                    </CardContent>
                </Card>
                {editingOrganization && (
                    <EditOrganizationSheet
                        key={editingOrganization.id}
                        organization={editingOrganization}
                        operatingUnitTypes={operatingUnitTypes}
                        onClose={() => setEditingOrganization(null)}
                    />
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Hierarchy organisasi</CardTitle>
                        <CardDescription>
                            Buat hierarchy hanya ketika proses bisnis
                            membutuhkan susunan parent-child.
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

                                return (
                                    <div
                                        key={hierarchy.id}
                                        className="space-y-4 rounded-lg border p-4"
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
                </Card>
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
