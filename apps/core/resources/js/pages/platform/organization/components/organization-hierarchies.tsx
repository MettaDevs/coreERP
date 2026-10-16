import '@xyflow/react/dist/style.css';

import { ActionButton } from '@apperp/ui/action-button';
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
import { DataTable, DataTableText } from '@apperp/ui/data-table';
import { DialogCancel } from '@apperp/ui/dialog';

import {
    Empty,
    EmptyDescription,
    EmptyHeader,
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
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import { MasterDetailLayout } from '@apperp/ui/master-detail-layout';
import {
    PageDialog as Dialog,
    PageDialogAction as DialogAction,
    PageDialogBody as DialogBody,
    PageDialogContent as DialogContent,
    PageDialogDescription as DialogDescription,
    PageDialogFooter as DialogFooter,
    PageDialogHeader as DialogHeader,
    PageDialogTitle as DialogTitle,
    PageDialogTrigger as DialogTrigger,
} from '@apperp/ui/page-dialog';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';

import { ToggleGroup, ToggleGroupItem } from '@apperp/ui/toggle-group';
import { useIncrementalList } from '@apperp/ui/use-incremental-list';
import type { ListLoader } from '@apperp/ui/use-incremental-list';

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
import { useCallback, useEffect, useRef, useState } from 'react';
import { useToday } from '@/hooks/use-work-date';
import { apiJson, errorText } from '@/lib/core-api';
import { OrganizationLookup } from './organization-lookup';

import { needsNumber, MissingNumberBadge } from './organization-number-status';
import type {
    Props,
    Organization,
    Hierarchy,
    HierarchyNode,
    Version,
} from './organization-types';
function CreateHierarchyDialog({ purposes }: Pick<Props, 'purposes'>) {
    const [open, setOpen] = useState(false);
    const contentRef = useRef<HTMLDivElement>(null);
    const today = useToday();
    const form = useForm({
        name: '',
        purpose_codes: [] as string[],
        root_organization_id: '',
        effective_from: today,
    });

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!form.processing) {
                    setOpen(next);
                }
            }}
        >
            <DialogTrigger asChild>
                <ActionButton action="create">Tambah hierarki</ActionButton>
            </DialogTrigger>
            <DialogContent ref={contentRef}>
                <DialogHeader>
                    <DialogTitle>Hierarki organisasi baru</DialogTitle>
                    <DialogDescription>
                        Susun organisasi berdasarkan tujuan dan tanggal
                        berlakunya.
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="flex min-h-0 flex-1 flex-col"
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
                    <DialogBody className="space-y-4">
                        <FieldGroup>
                            <Field data-invalid={Boolean(form.errors.name)}>
                                <Input
                                    label="Nama hierarki"
                                    required
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
                                    Tujuan hierarki
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
                                            className="border-border/80 font-medium"
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
                                    <OrganizationLookup
                                        label="Organisasi paling atas"
                                        value={form.data.root_organization_id}
                                        onChange={(value) =>
                                            form.setData(
                                                'root_organization_id',
                                                value,
                                            )
                                        }
                                        portalContainer={contentRef}
                                    />
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
                                        label="Berlaku mulai"
                                        required
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
                            Buat draft hierarki
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
}: {
    version: Version;
    rowVersion: number;
}) {
    const form = useForm({
        organization_id: '',
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
                    <OrganizationLookup
                        label="Organisasi yang ditambahkan"
                        value={form.data.organization_id}
                        onChange={(value) =>
                            form.setData('organization_id', value)
                        }
                        excludeVersionId={version.id}
                    />
                    <FieldError>{form.errors.organization_id}</FieldError>
                </Field>
                <Field
                    data-invalid={Boolean(form.errors.parent_organization_id)}
                >
                    <Select
                        label="Berada di bawah"
                        required
                        value={form.data.parent_organization_id}
                        onValueChange={(value) =>
                            value &&
                            form.setData('parent_organization_id', value)
                        }
                        items={version.nodes.map((node) => ({
                            value: node.organization.id,
                            label: node.organization.name,
                        }))}
                    />
                    <FieldError>
                        {form.errors.parent_organization_id}
                    </FieldError>
                </Field>
            </div>
            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border/40 pt-2">
                <p className="text-muted-foreground">
                    Hubungan ini berlaku pada draft hierarki ini untuk menyusun
                    struktur pohon.
                </p>
                <div className="flex gap-2">
                    <Button
                        variant="outline"
                        type="submit"
                        size="sm"
                        className="font-medium"
                        disabled={!form.data.organization_id || form.processing}
                    >
                        <Plus className="mr-1.5 size-3.5" /> Tambah Penempatan
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        className="font-medium shadow-xs"
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
                <span className="flex size-5 items-center justify-center rounded bg-primary/10 font-bold text-primary">
                    {data.classification === 'legal_entity' ? 'LE' : 'OU'}
                </span>
                <p className="truncate font-bold text-foreground">
                    {data.label}
                </p>
            </div>
            <p className="mt-1 text-muted-foreground">
                {data.classification === 'legal_entity'
                    ? 'Perusahaan (Badan Hukum)'
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
            <div className="flex items-center justify-between border-b border-border bg-card/80 px-4 py-2.5 font-semibold text-foreground">
                <span className="flex items-center gap-2">
                    <Network className="size-4 text-primary" /> Visualisasi
                    Organigram Struktur Perusahaan
                </span>
                <Badge
                    variant="outline"
                    className="border-primary/20 bg-primary/5 font-mono text-primary"
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
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!form.processing) {
                    setOpen(next);
                }
            }}
        >
            <DialogTrigger asChild>
                <Button variant="outline" size="sm" className="font-medium">
                    <Plus className="mr-1.5 size-3.5" /> Buat versi baru
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Buat draft versi baru</DialogTitle>
                    <DialogDescription>
                        Versi yang sudah dipublikasikan tetap menjadi riwayat
                        resmi.
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="flex min-h-0 flex-1 flex-col"
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
                    <DialogBody className="space-y-4">
                        <FieldGroup>
                            <Field
                                data-invalid={Boolean(
                                    form.errors.effective_from,
                                )}
                            >
                                <Input
                                    label="Berlaku mulai"
                                    required
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
    purposes,
    operatingUnitTypes,
    savedHierarchy,
}: Pick<
    Props,
    | 'canManage'
    | 'organizations'
    | 'hierarchies'
    | 'purposes'
    | 'operatingUnitTypes'
    | 'savedHierarchy'
>) {
    const [showDetail, setShowDetail] = useState(false);
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState<Hierarchy | null>(null);
    const [error, setError] = useState('');
    const pending = useRef<AbortController | null>(null);
    useEffect(() => () => pending.current?.abort(), []);
    const loader = useCallback<
        ListLoader<Pick<Hierarchy, 'id' | 'name' | 'status'>>
    >(async ({ query, cursor, signal }) => {
        const params = new URLSearchParams({ incremental: '1', q: query });

        if (cursor) {
            params.set('cursor', cursor);
        }

        const result = await apiJson<{
            data: Pick<Hierarchy, 'id' | 'name' | 'status'>[];
            next_cursor: string | null;
        }>(`/settings/organization-hierarchies?${params}`, { signal });

        return { items: result.data, nextCursor: result.next_cursor };
    }, []);
    const list = useIncrementalList({
        loadItems: loader,
        query,
        getKey: (item) => item.id,
    });
    const refreshList = useRef(list.reload);
    useEffect(() => {
        if (savedHierarchy) {
            Promise.resolve().then(() => {
                pending.current?.abort();
                setSelected(savedHierarchy);
                setShowDetail(true);
                refreshList.current();
            });
        }
    }, [savedHierarchy]);
    const open = async (id: string) => {
        pending.current?.abort();
        const controller = new AbortController();
        pending.current = controller;
        setError('');

        try {
            const result = await apiJson<{ data: Hierarchy[] }>(
                `/settings/organization-hierarchies?incremental=1&record_id=${encodeURIComponent(id)}`,
                { signal: controller.signal },
            );

            if (!controller.signal.aborted) {
                setSelected(result.data[0]);
                setShowDetail(true);
            }
        } catch (caught) {
            if (!controller.signal.aborted) {
                setError(
                    errorText(
                        caught,
                        'Hierarki belum dapat dibuka. Pilih kembali untuk mencoba lagi.',
                    ),
                );
            }
        }
    };

    return (
        <>
            <Head title="Hierarki organisasi" />
            <ListPageLayout
                filters={
                    error && (
                        <p role="alert" className="px-4 py-2 text-destructive">
                            {error}
                        </p>
                    )
                }
                header={
                    <RecordActionBar title="Hierarki organisasi">
                        {canManage && (
                            <CreateHierarchyDialog purposes={purposes} />
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
                                    aria-label="Cari hierarki"
                                    placeholder="Cari hierarki..."
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                />
                            </div>
                            <DataTable
                                data={list.items}
                                getRowKey={(item) => item.id}
                                columns={[
                                    {
                                        id: 'name',
                                        header: 'Nama hierarki',
                                        cell: (item) => (
                                            <DataTableText>
                                                {item.name}
                                            </DataTableText>
                                        ),
                                    },
                                ]}
                                activeRowKey={selected?.id}
                                rowActivation="single-click"
                                onRowClick={(item) => {
                                    if (selected?.id === item.id) {
                                        setShowDetail(true);
                                    } else {
                                        void open(item.id);
                                    }
                                }}
                                incremental={list}
                                emptyMessage="Belum ada hierarki yang cocok."
                            />
                        </aside>
                    }
                >
                    <div
                        className="h-full min-h-0 min-w-0 flex-1 space-y-4 overflow-auto p-4 lg:p-6"
                        scroll-region=""
                    >
                        {selected ? (
                            (() => {
                                const hierarchy = selected;
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
                                                <p className="mt-0.5 text-muted-foreground">
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
                                                    : `Terbit v${version.version_number}`}
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
                                                <p className="font-semibold tracking-wider text-muted-foreground uppercase">
                                                    Daftar Penempatan Unit
                                                    Organisasi
                                                </p>
                                                <span className="text-muted-foreground">
                                                    {version.nodes.length} Unit
                                                    Terpasang
                                                </span>
                                            </div>
                                            <div className="grid gap-2.5 md:grid-cols-2 lg:grid-cols-3">
                                                {version.nodes.map((node) => (
                                                    <div
                                                        key={node.id}
                                                        className="flex items-center justify-between gap-2 rounded-lg border border-border/80 bg-card px-3.5 py-2.5 shadow-2xs"
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
                                                            <span className="mt-0.5 block truncate text-muted-foreground">
                                                                {node.parent_node
                                                                    ? `Di bawah ${node.parent_node.organization.name}`
                                                                    : 'Organisasi paling atas'}
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
                            })()
                        ) : (
                            <Empty>
                                <EmptyHeader>
                                    <EmptyTitle>
                                        Pilih hierarki organisasi
                                    </EmptyTitle>
                                    <EmptyDescription>
                                        Pilih hierarki dari daftar untuk melihat
                                        susunan dan versi yang berlaku.
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
