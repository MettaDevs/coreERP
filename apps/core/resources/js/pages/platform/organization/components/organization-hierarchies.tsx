import '@xyflow/react/dist/style.css';

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
    FieldError,
    FieldGroup,
    FieldLegend,
    FieldSet,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { NativeSelect } from '@apperp/ui/native-select';

import { ToggleGroup, ToggleGroupItem } from '@apperp/ui/toggle-group';

import { Head, useForm } from '@inertiajs/react';
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
import { Check, Network, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { useToday } from '@/hooks/use-work-date';

import { needsNumber, MissingNumberBadge } from './organization-number-status';
import type {
    Props,
    Organization,
    HierarchyNode,
    Version,
} from './organization-types';
function CreateHierarchyDialog({
    organizations,
    purposes,
}: Pick<Props, 'organizations' | 'purposes'>) {
    const [open, setOpen] = useState(false);
    const today = useToday();
    const form = useForm({
        name: '',
        purpose_codes: [] as string[],
        root_organization_id: organizations[0]?.id ?? '',
        effective_from: today,
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
    rowVersion,
    organizations,
}: {
    version: Version;
    rowVersion: number;
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
                form.transform((data) => ({ ...data, version: rowVersion }));
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
                        onClick={() => {
                            form.transform(() => ({ version: rowVersion }));
                            form.post(
                                `/settings/organization/hierarchy-versions/${version.id}/publish`,
                                { preserveScroll: true },
                            );
                        }}
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
                    <MissingNumberBadge />
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
            needsNumber: needsNumber(node.organization.operating_unit),
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
    rowVersion,
    node,
}: {
    version: Version;
    rowVersion: number;
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
                        onClick={() => {
                            form.transform(() => ({ version: rowVersion }));
                            form.delete(
                                `/settings/organization/hierarchy-versions/${version.id}/placements/${node.id}`,
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setOpen(false),
                                },
                            );
                        }}
                    >
                        Batalkan Penempatan
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

function CreateVersionDraftAction({
    version,
    rowVersion,
}: {
    version: Version;
    rowVersion: number;
}) {
    const today = useToday();
    const form = useForm({
        effective_from: today,
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
                        form.transform((data) => ({
                            ...data,
                            version: rowVersion,
                        }));
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

export default function OrganizationHierarchies({
    canManage,
    organizations,
    hierarchies,
    purposes,
    operatingUnitTypes,
}: Pick<
    Props,
    | 'canManage'
    | 'organizations'
    | 'hierarchies'
    | 'purposes'
    | 'operatingUnitTypes'
>) {
    return (
        <>
            <Head title="Hierarki organisasi" />
            <main className="flex min-w-0 flex-col gap-6 p-4 sm:p-6">
                <Heading title="Hierarki organisasi" />{' '}
                <Card>
                    <CardHeader>
                        <CardTitle>Hierarki organisasi</CardTitle>
                        <CardDescription>
                            Susun hubungan organisasi berdasarkan tujuan dan
                            tanggal berlakunya.
                        </CardDescription>
                        {canManage && (
                            <CardAction className="col-span-2 col-start-1 row-span-1 row-start-3 justify-self-start sm:col-span-1 sm:col-start-2 sm:row-span-2 sm:row-start-1 sm:justify-self-end">
                                <CreateHierarchyDialog
                                    organizations={organizations}
                                    purposes={purposes}
                                />
                            </CardAction>
                        )}
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
                                                    {version.nodes.length} Unit
                                                    Terpasang
                                                </span>
                                            </div>
                                            <div className="grid gap-2.5 md:grid-cols-2 lg:grid-cols-3">
                                                {version.nodes.map((node) => (
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
                                                                    rowVersion={
                                                                        hierarchy.version
                                                                    }
                                                                    node={node}
                                                                />
                                                            )}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>

                                        {canManage &&
                                            version.status === 'draft' && (
                                                <DraftActions
                                                    version={version}
                                                    rowVersion={
                                                        hierarchy.version
                                                    }
                                                    organizations={
                                                        organizations
                                                    }
                                                />
                                            )}
                                        {canManage &&
                                            version.status === 'published' && (
                                                <div className="flex justify-end pt-2">
                                                    <CreateVersionDraftAction
                                                        version={version}
                                                        rowVersion={
                                                            hierarchy.version
                                                        }
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
                                        Buat hierarchy baru untuk menyusun bagan
                                        organigram dan struktur hubungan antar
                                        unit bisnis.
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
