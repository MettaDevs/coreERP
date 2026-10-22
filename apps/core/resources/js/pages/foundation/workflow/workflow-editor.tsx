import { ActionButton } from '@apperp/ui/action-button';
import '@xyflow/react/dist/style.css';

import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { InlineLookup } from '@apperp/ui/inline-lookup';
import { Input } from '@apperp/ui/input';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';

import { useRecordTransition } from '@apperp/ui/use-record-transition';
import { Head, router } from '@inertiajs/react';
import {
    addEdge,
    Background,
    Controls,
    Handle,
    MiniMap,
    Panel,
    Position,
    ReactFlow,
    useEdgesState,
    useNodesState,
} from '@xyflow/react';
import type {
    Connection,
    Edge,
    Node,
    NodeProps,
    NodeTypes,
} from '@xyflow/react';
import {
    ArrowLeft,
    CheckCircle2,
    GitCommit,
    GitFork,
    GitPullRequest,
    HelpCircle,
    Info,
    Lock,
    Plus,
    X,
    UserCheck,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { FormErrorHeader } from '@/components/form-error-header';
import { withInertiaErrors } from '@/hooks/use-inertia-feedback';

type Config = Record<string, unknown>;
type WorkflowNodeData = { label: string; config: Config };
type WorkflowNode = Node<WorkflowNodeData>;
type WorkflowEdge = Edge<{
    outcome?: string | null;
    condition?: Config | null;
}>;
type StoredWorkflowEdge = Pick<WorkflowEdge, 'id' | 'source' | 'target'> & {
    outcome: string | null;
    condition: Config | null;
};
type Role = { id: string; name: string };
type Member = { id: string; name: string; email: string };
type Props = {
    canManage: boolean;
    workflow: {
        id: string;
        name: string;
        enabled: boolean;
        legal_entity_id: string | null;
        version: number;
    };
    workflowType: {
        id: string;
        name: string;
        code: string;
        scope: string;
        app_name: string;
        decision_context_schema: string | Record<string, unknown>;
    };
    version: {
        id: string | null;
        status: 'draft' | 'published' | null;
        version: number | null;
    };
    graph: { nodes: WorkflowNode[]; edges: StoredWorkflowEdge[] };
    roles: Role[];
    members: Member[];
};

const nodeTitles: Record<string, string> = {
    start: 'Mulai',
    end: 'Selesai',
    approval: 'Persetujuan',
    manual_task: 'Tugas manual',
    condition: 'Keputusan kondisi',
    parallel: 'Cabang paralel',
};

const palette = [
    ['approval', 'Persetujuan', UserCheck],
    ['manual_task', 'Tugas manual', GitCommit],
    ['condition', 'Keputusan kondisi', GitFork],
    ['parallel', 'Cabang paralel', GitPullRequest],
] as const;

function FlowNode({ data, selected, type }: NodeProps<WorkflowNode>) {
    const isStart = type === 'start';
    const isEnd = type === 'end';
    const isApproval = type === 'approval';
    const isCondition = type === 'condition';

    return (
        <div
            className={`min-w-48 rounded-xl border bg-card p-3 shadow-xs transition-all ${
                selected
                    ? 'border-primary shadow-sm ring-2 ring-primary/20'
                    : 'border-border hover:border-border/80'
            }`}
        >
            {!isStart && (
                <Handle
                    type="target"
                    position={Position.Left}
                    className="!size-3 !border-2 !border-background !bg-muted-foreground"
                />
            )}
            <div className="flex items-center gap-2">
                <span
                    className={`flex size-6 items-center justify-center rounded-md text-[10px] font-bold ${
                        isApproval || isStart
                            ? 'bg-primary/10 text-primary'
                            : 'bg-muted text-muted-foreground'
                    }`}
                >
                    {isStart
                        ? 'A'
                        : isEnd
                          ? 'Z'
                          : (nodeTitles[type ?? '']?.[0] ?? 'L')}
                </span>
                <p className="text-[11px] font-bold tracking-wider text-muted-foreground uppercase">
                    {nodeTitles[type ?? ''] ?? 'Langkah'}
                </p>
            </div>
            <p className="mt-1.5 truncate text-xs font-bold text-foreground">
                {data.label}
            </p>
            {isApproval && (
                <p className="mt-1 text-[11px] text-muted-foreground italic">
                    Pilih langkah untuk mengatur pemeriksa
                </p>
            )}
            {isCondition && (
                <p className="mt-1 font-mono text-[10px] text-muted-foreground">
                    Cabang: Benar / Salah
                </p>
            )}

            {isCondition ? (
                <>
                    <Handle
                        id="true"
                        type="source"
                        position={Position.Right}
                        style={{ top: '35%' }}
                        className="!size-3 !border-2 !border-background !bg-muted-foreground"
                    />
                    <Handle
                        id="false"
                        type="source"
                        position={Position.Right}
                        style={{ top: '70%' }}
                        className="!size-3 !border-2 !border-background !bg-muted-foreground"
                    />
                </>
            ) : isApproval ? (
                <>
                    <Handle
                        id="approve"
                        type="source"
                        position={Position.Right}
                        style={{ top: '35%' }}
                        className="!size-3 !border-2 !border-background !bg-muted-foreground"
                    />
                    <Handle
                        id="reject"
                        type="source"
                        position={Position.Right}
                        style={{ top: '70%' }}
                        className="!size-3 !border-2 !border-background !bg-muted-foreground"
                    />
                </>
            ) : (
                !isEnd && (
                    <Handle
                        type="source"
                        position={Position.Right}
                        className="!size-3 !border-2 !border-background !bg-muted-foreground"
                    />
                )
            )}
        </div>
    );
}

const nodeTypes: NodeTypes = {
    start: FlowNode,
    end: FlowNode,
    approval: FlowNode,
    manual_task: FlowNode,
    condition: FlowNode,
    parallel: FlowNode,
};

/**
 * Daftar field konteks, beserta keterangan apakah skemanya benar-benar terbaca.
 *
 * Dulu kegagalan membaca skema dipulangkan sebagai larik kosong, dan itu berakhir di tempat
 * yang sama dengan skema yang memang tidak mendeklarasikan field apa pun: dropdown berisi
 * "Pilih field" saja. Orang yang menyusun workflow lalu menyimpulkan jenis ini tidak punya
 * konteks yang bisa diperiksa — kesimpulan yang tidak pernah dibaca dari skema mana pun.
 */
type ContextFields =
    { readable: true; fields: string[] } | { readable: false; fields: [] };

function contextFields(
    schema: Props['workflowType']['decision_context_schema'],
): ContextFields {
    try {
        const parsed = typeof schema === 'string' ? JSON.parse(schema) : schema;
        const properties = (parsed as { properties?: Record<string, unknown> })
            .properties;
        const required = (parsed as { required?: string[] }).required ?? [];

        return {
            readable: true,
            fields: [
                ...new Set([...required, ...Object.keys(properties ?? {})]),
            ],
        };
    } catch {
        return { readable: false, fields: [] };
    }
}

function configuredMemberIds(config: Config): string[] {
    const assignees = config.assignees;

    if (Array.isArray(assignees)) {
        return assignees
            .filter(
                (assignee): assignee is { type?: string; id?: string } =>
                    typeof assignee === 'object' && assignee !== null,
            )
            .filter(
                (assignee) =>
                    assignee.type === 'member' && Boolean(assignee.id),
            )
            .map((assignee) => String(assignee.id));
    }

    const assignee = config.assignee as
        { type?: string; id?: string } | undefined;

    return assignee?.type === 'member' && assignee.id ? [assignee.id] : [];
}

function normalizeWorkflowEdges(
    edges: StoredWorkflowEdge[],
    nodes: WorkflowNode[],
): WorkflowEdge[] {
    const nodeKinds = new Map(nodes.map((node) => [node.id, node.type]));

    return edges.map((edge) => ({
        ...edge,
        sourceHandle:
            nodeKinds.get(edge.source) === 'condition' ||
            nodeKinds.get(edge.source) === 'approval'
                ? edge.outcome
                : null,
        label: edge.outcome ?? undefined,
        data: { outcome: edge.outcome, condition: edge.condition },
    }));
}

function serializeWorkflowGraph(nodes: WorkflowNode[], edges: WorkflowEdge[]) {
    return {
        nodes: nodes.map((node) => ({
            id: node.id,
            type: node.type,
            data: node.data,
            position: {
                x: Math.max(0, Math.round(node.position.x)),
                y: Math.max(0, Math.round(node.position.y)),
            },
        })),
        edges: edges.map((edge) => ({
            source: edge.source,
            target: edge.target,
            outcome:
                edge.data?.outcome ??
                (typeof edge.label === 'string' ? edge.label : null),
            condition: edge.data?.condition ?? null,
        })),
    };
}

function WorkflowChoice({
    label,
    value,
    items,
    disabled,
    onValueChange,
}: {
    label: string;
    value: string;
    items: { value: string; label: string }[];
    disabled: boolean;
    onValueChange: (value: string) => void;
}) {
    if (disabled) {
        return (
            <Input
                label={label}
                value={
                    items.find((item) => item.value === value)?.label ?? value
                }
                disabled
            />
        );
    }

    return (
        <Select
            label={label}
            value={value || null}
            items={items}
            placeholder="Pilih"
            searchPlaceholder="Cari pilihan…"
            emptyMessage="Pilihan tidak ditemukan."
            onValueChange={(next) => {
                if (next !== null) {
                    onValueChange(next);
                }
            }}
        />
    );
}

export default function WorkflowEditor({
    canManage,
    workflow,
    workflowType,
    version,
    graph,
    roles,
    members,
}: Props) {
    const [nodes, setNodes, onNodesChange] = useNodesState<WorkflowNode>(
        graph.nodes,
    );
    const [edges, setEdges, onEdgesChange] = useEdgesState<WorkflowEdge>(
        normalizeWorkflowEdges(graph.edges, graph.nodes),
    );
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [pending, setPending] = useState<'save' | 'publish' | 'draft' | null>(
        null,
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [recipientChoice, setRecipientChoice] = useState<{
        nodeId: string;
        mode: string;
    } | null>(null);
    const readOnly = !canManage || version.status !== 'draft';
    const selected = nodes.find((node) => node.id === selectedId) ?? null;
    const context = useMemo(
        () => contextFields(workflowType.decision_context_schema),
        [workflowType.decision_context_schema],
    );
    const displayStatus =
        version.status === 'published'
            ? workflow.enabled
                ? 'Aktif'
                : 'Nonaktif'
            : 'Draf';

    const updateSelected = (change: Partial<WorkflowNodeData>) => {
        if (!selected) {
            return;
        }

        setNodes((current) =>
            current.map((node) =>
                node.id === selected.id
                    ? { ...node, data: { ...node.data, ...change } }
                    : node,
            ),
        );
    };

    const updateConfig = (change: Config) => {
        if (!selected) {
            return;
        }

        setNodes((current) =>
            current.map((node) =>
                node.id === selected.id
                    ? {
                          ...node,
                          data: {
                              ...node.data,
                              config: { ...node.data.config, ...change },
                          },
                      }
                    : node,
            ),
        );
    };

    const selectedConfig = selected?.data.config ?? {};
    const selectedAssignee = selectedConfig.assignee as
        { type?: string; id?: string } | undefined;
    const recipientMode =
        recipientChoice?.nodeId === selectedId
            ? recipientChoice.mode
            : selectedAssignee?.type === 'role'
              ? 'role'
              : 'members';
    const selectedMemberIds = configuredMemberIds(selectedConfig);

    const setRecipientMode = (mode: string) => {
        if (selectedId) {
            setRecipientChoice({ nodeId: selectedId, mode });
        }

        if (mode === 'role') {
            updateConfig({
                assignees: null,
                assignee:
                    selectedAssignee?.type === 'role' ? selectedAssignee : null,
            });

            return;
        }

        updateConfig({
            assignee: null,
            assignees: selectedMemberIds.map((id) => ({
                type: 'member',
                id,
            })),
        });
    };

    const toggleMember = (memberId: string, checked: boolean) => {
        const nextIds = new Set(selectedMemberIds);

        if (checked) {
            nextIds.add(memberId);
        } else {
            nextIds.delete(memberId);
        }

        updateConfig({
            assignee: null,
            assignees: [...nextIds].map((id) => ({ type: 'member', id })),
        });
    };

    useEffect(() => {
        const assignee = selected?.data.config.assignee as
            { type?: string; id?: string } | undefined;

        if (
            readOnly ||
            !selected ||
            !assignee?.id ||
            assignee.type !== 'role' ||
            roles.some((role) => role.id === assignee.id) ||
            !members.some((member) => member.id === assignee.id)
        ) {
            return;
        }

        updateConfig({ assignee: { type: 'member', id: assignee.id } });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [members, roles, selected, readOnly]);

    const addNode = (type: string) => {
        let suffix = nodes.length + 1;

        while (nodes.some((node) => node.id === `${type}-${suffix}`)) {
            suffix += 1;
        }

        const id = `${type}-${suffix}`;
        const lastX = Math.max(80, ...nodes.map((node) => node.position.x));
        setNodes((current) => [
            ...current,
            {
                id,
                type,
                position: { x: lastX + 260, y: 180 },
                data: {
                    label: nodeTitles[type],
                    config:
                        type === 'approval' || type === 'manual_task'
                            ? { completion_policy: 'single' }
                            : {},
                },
            },
        ]);
        setSelectedId(id);
    };

    const connect = (connection: Connection) => {
        if (readOnly || !connection.source || !connection.target) {
            return;
        }

        const source = nodes.find((node) => node.id === connection.source);
        const outcome =
            connection.sourceHandle ??
            (source?.type === 'condition'
                ? 'true'
                : source?.type === 'approval'
                  ? 'approve'
                  : null);
        setEdges((current) =>
            addEdge(
                {
                    ...connection,
                    id: `${connection.source}-${connection.target}-${current.length + 1}`,
                    label: outcome ?? undefined,
                    data: { outcome },
                },
                current,
            ),
        );
    };

    const payload = serializeWorkflowGraph(nodes, edges);
    const originalPayload = serializeWorkflowGraph(
        graph.nodes,
        normalizeWorkflowEdges(graph.edges, graph.nodes),
    );
    const dirty = JSON.stringify(payload) !== JSON.stringify(originalPayload);
    const transition = useRecordTransition(
        !readOnly && dirty,
        pending !== null,
    );
    const permittedNavigation = useRef(false);
    const ownMutation = useRef<{ method: string; path: string } | null>(null);
    useEffect(
        () =>
            router.on('before', (event) => {
                const visit = event.detail.visit;

                if (
                    ownMutation.current?.method === visit.method &&
                    ownMutation.current.path === visit.url.pathname
                ) {
                    ownMutation.current = null;

                    return;
                }

                if (permittedNavigation.current) {
                    permittedNavigation.current = false;

                    return;
                }

                if (pending !== null) {
                    event.preventDefault();

                    return;
                }

                if (!readOnly && dirty) {
                    event.preventDefault();
                    transition.request(() => {
                        permittedNavigation.current = true;
                        router.visit(visit.url, visit);
                    });
                }
            }),
        [dirty, pending, readOnly, transition],
    );
    const goBack = () =>
        transition.request(() => {
            permittedNavigation.current = true;
            router.get('/settings/workflows');
        });
    const blocked = readOnly || pending !== null;
    const visitOptions = {
        preserveScroll: true,
        onError: (messages: Record<string, string>) => setErrors(messages),
        onFinish: () => setPending(null),
    };
    const save = () => {
        if (blocked) {
            return;
        }

        setErrors({});
        setPending('save');
        ownMutation.current = {
            method: 'put',
            path: `/settings/workflows/${workflow.id}/graph`,
        };
        router.put(
            `/settings/workflows/${workflow.id}/graph`,
            { ...payload, version: workflow.version } as never,
            withInertiaErrors(visitOptions),
        );
    };
    const publish = () => {
        if (blocked || dirty) {
            return;
        }

        setErrors({});
        setPending('publish');
        ownMutation.current = {
            method: 'post',
            path: `/settings/workflows/${workflow.id}/publish`,
        };
        router.post(
            `/settings/workflows/${workflow.id}/publish`,
            { version: workflow.version },
            withInertiaErrors(visitOptions),
        );
    };
    const createDraft = () => {
        if (!canManage || pending) {
            return;
        }

        setErrors({});
        setPending('draft');
        ownMutation.current = {
            method: 'post',
            path: `/settings/workflows/${workflow.id}/draft`,
        };
        router.post(
            `/settings/workflows/${workflow.id}/draft`,
            { version: workflow.version },
            withInertiaErrors(visitOptions),
        );
    };

    return (
        <>
            <Head title={`Workflow · ${workflow.name}`} />
            <main className="flex min-w-0 flex-col">
                <RecordActionBar
                    feedback={
                        <FormErrorHeader
                            message={Object.values(errors).join(' ')}
                            operation="Alur belum dapat disimpan"
                        />
                    }
                    title="Workflow"
                    trailing={<Badge variant="outline">{displayStatus}</Badge>}
                >
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        disabled={pending !== null}
                        onClick={goBack}
                    >
                        <ArrowLeft /> Kembali
                    </Button>
                    {version.status === 'draft' && (
                        <>
                            <ActionButton
                                action="save"
                                type="button"
                                onClick={save}
                                disabled={blocked || !dirty}
                                pending={pending === 'save'}
                            />
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={publish}
                                disabled={blocked || dirty}
                            >
                                <CheckCircle2 />{' '}
                                {pending === 'publish'
                                    ? 'Mengaktifkan…'
                                    : 'Aktifkan'}
                            </Button>
                        </>
                    )}
                    {version.status === 'published' && canManage && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={createDraft}
                            disabled={pending !== null}
                        >
                            <Plus />{' '}
                            {pending === 'draft'
                                ? 'Membuat draf…'
                                : 'Buat draf baru'}
                        </Button>
                    )}
                </RecordActionBar>
                <div className="space-y-4 p-4">
                    <div>
                        <h1 className="text-xl font-semibold">
                            {workflow.name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {workflowType.app_name} · {workflowType.name}
                        </p>
                        <p
                            className="mt-1 text-sm text-muted-foreground"
                            role="status"
                        >
                            {version.status === 'published'
                                ? 'Versi terbit hanya dapat dilihat. Buat draf baru untuk mengubah alur.'
                                : !canManage
                                  ? 'Anda hanya dapat melihat alur ini.'
                                  : dirty
                                    ? 'Ada perubahan yang belum disimpan. Simpan sebelum mengaktifkan alur.'
                                    : 'Draf tersimpan. Aktifkan setelah semua langkah dan penerimanya sesuai.'}
                        </p>
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle>Alur persetujuan</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-sm text-muted-foreground">
                                    Tambah langkah
                                </span>
                                {palette.map(([type, label, Icon]) => (
                                    <Button
                                        key={type}
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        disabled={blocked}
                                        onClick={() => addNode(type)}
                                    >
                                        <Icon />
                                        {label}
                                    </Button>
                                ))}
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Hubungkan titik pada langkah untuk menentukan
                                urutannya. Pilih langkah untuk mengatur penerima
                                atau kondisi. Pilih garis lalu tekan Delete
                                untuk melepas hubungan.
                            </p>
                            <div className="grid min-w-0 gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
                                <div className="relative h-[min(65vh,760px)] min-h-96 min-w-0 overflow-hidden rounded-lg border bg-background">
                                    <ReactFlow
                                        nodes={nodes}
                                        edges={edges}
                                        nodeTypes={nodeTypes}
                                        onNodesChange={
                                            blocked ? undefined : onNodesChange
                                        }
                                        onEdgesChange={
                                            blocked ? undefined : onEdgesChange
                                        }
                                        nodesDraggable={!blocked}
                                        nodesConnectable={!blocked}
                                        onConnect={connect}
                                        onNodeClick={(_, node) =>
                                            setSelectedId(node.id)
                                        }
                                        onPaneClick={() => setSelectedId(null)}
                                        fitView
                                        deleteKeyCode={
                                            blocked
                                                ? null
                                                : ['Backspace', 'Delete']
                                        }
                                        proOptions={{ hideAttribution: true }}
                                    >
                                        <Background gap={20} size={1} />
                                        <Controls />
                                        <MiniMap pannable zoomable />
                                        <Panel
                                            position="top-left"
                                            className="flex items-center gap-2 rounded border bg-background px-3 py-2 text-xs text-muted-foreground"
                                        >
                                            {readOnly ? (
                                                <>
                                                    <Lock className="size-4" />
                                                    Hanya lihat
                                                </>
                                            ) : (
                                                <>
                                                    <Info className="size-4" />
                                                    Draf
                                                    {dirty
                                                        ? ' · belum disimpan'
                                                        : ''}
                                                </>
                                            )}
                                        </Panel>
                                    </ReactFlow>
                                </div>
                                <aside
                                    className="min-w-0 space-y-4 rounded-lg border p-4"
                                    aria-label="Pengaturan langkah"
                                >
                                    <h2 className="text-sm font-semibold">
                                        Pengaturan langkah
                                    </h2>
                                    {!selected ? (
                                        <div className="flex flex-col items-center gap-2 py-8 text-center text-sm text-muted-foreground">
                                            <HelpCircle className="size-6" />
                                            <p>
                                                Pilih langkah pada alur untuk
                                                melihat nama, penerima tugas,
                                                atau kondisi.
                                            </p>
                                        </div>
                                    ) : (
                                        <>
                                            <Badge variant="outline">
                                                {
                                                    nodeTitles[
                                                        selected.type ?? ''
                                                    ]
                                                }
                                            </Badge>
                                            <FormGrid columns={1}>
                                                <FormControl>
                                                    <Input
                                                        label="Nama langkah"
                                                        value={
                                                            selected.data.label
                                                        }
                                                        disabled={
                                                            blocked ||
                                                            selected.type ===
                                                                'start' ||
                                                            selected.type ===
                                                                'end'
                                                        }
                                                        onChange={(event) =>
                                                            updateSelected({
                                                                label: event
                                                                    .target
                                                                    .value,
                                                            })
                                                        }
                                                    />
                                                </FormControl>
                                                {(selected.type ===
                                                    'approval' ||
                                                    selected.type ===
                                                        'manual_task') && (
                                                    <>
                                                        <FormControl>
                                                            <WorkflowChoice
                                                                label="Jenis penerima"
                                                                value={
                                                                    recipientMode
                                                                }
                                                                disabled={
                                                                    blocked
                                                                }
                                                                onValueChange={
                                                                    setRecipientMode
                                                                }
                                                                items={[
                                                                    {
                                                                        value: 'role',
                                                                        label: 'Role',
                                                                    },
                                                                    {
                                                                        value: 'members',
                                                                        label: 'Anggota tertentu',
                                                                    },
                                                                ]}
                                                            />
                                                        </FormControl>
                                                        {recipientMode ===
                                                        'role' ? (
                                                            <FormControl hint="Tugas diterima oleh anggota yang memiliki role ini.">
                                                                <InlineLookup
                                                                    label="Role penerima"
                                                                    items={
                                                                        roles
                                                                    }
                                                                    value={
                                                                        roles.find(
                                                                            (
                                                                                role,
                                                                            ) =>
                                                                                role.id ===
                                                                                selectedAssignee?.id,
                                                                        ) ??
                                                                        null
                                                                    }
                                                                    columns={[
                                                                        {
                                                                            id: 'name',
                                                                            header: 'Nama role',
                                                                            cell: (
                                                                                role,
                                                                            ) =>
                                                                                role.name,
                                                                        },
                                                                    ]}
                                                                    getItemValue={(
                                                                        role,
                                                                    ) =>
                                                                        role.id
                                                                    }
                                                                    getItemLabel={(
                                                                        role,
                                                                    ) =>
                                                                        role.name
                                                                    }
                                                                    disabled={
                                                                        blocked
                                                                    }
                                                                    showDetails={
                                                                        false
                                                                    }
                                                                    onValueChange={(
                                                                        role,
                                                                    ) =>
                                                                        updateConfig(
                                                                            {
                                                                                assignee:
                                                                                    {
                                                                                        type: 'role',
                                                                                        id: role.id,
                                                                                    },
                                                                                assignees:
                                                                                    null,
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                            </FormControl>
                                                        ) : (
                                                            <FormControl>
                                                                <div className="space-y-3">
                                                                    {!readOnly && (
                                                                        <InlineLookup<Member>
                                                                            label="Tambah anggota penerima"
                                                                            items={members.filter(
                                                                                (
                                                                                    member,
                                                                                ) =>
                                                                                    !selectedMemberIds.includes(
                                                                                        member.id,
                                                                                    ),
                                                                            )}
                                                                            value={
                                                                                null
                                                                            }
                                                                            columns={[
                                                                                {
                                                                                    id: 'name',
                                                                                    header: 'Nama',
                                                                                    cell: (
                                                                                        member,
                                                                                    ) =>
                                                                                        member.name,
                                                                                },
                                                                                {
                                                                                    id: 'email',
                                                                                    header: 'Email',
                                                                                    cell: (
                                                                                        member,
                                                                                    ) =>
                                                                                        member.email,
                                                                                },
                                                                            ]}
                                                                            getItemValue={(
                                                                                member,
                                                                            ) =>
                                                                                member.id
                                                                            }
                                                                            getItemLabel={(
                                                                                member,
                                                                            ) =>
                                                                                member.name
                                                                            }
                                                                            getSearchText={(
                                                                                member,
                                                                            ) =>
                                                                                `${member.name} ${member.email}`
                                                                            }
                                                                            disabled={
                                                                                blocked
                                                                            }
                                                                            showDetails={
                                                                                false
                                                                            }
                                                                            onValueChange={(
                                                                                member,
                                                                            ) =>
                                                                                toggleMember(
                                                                                    member.id,
                                                                                    true,
                                                                                )
                                                                            }
                                                                            emptyMessage="Tidak ada anggota lain yang dapat dipilih."
                                                                        />
                                                                    )}
                                                                    {selectedMemberIds.length ===
                                                                    0 ? (
                                                                        <p className="text-sm text-muted-foreground">
                                                                            Belum
                                                                            ada
                                                                            anggota
                                                                            penerima.
                                                                        </p>
                                                                    ) : (
                                                                        <ul className="divide-y rounded border">
                                                                            {selectedMemberIds.map(
                                                                                (
                                                                                    id,
                                                                                ) => {
                                                                                    const member =
                                                                                        members.find(
                                                                                            (
                                                                                                item,
                                                                                            ) =>
                                                                                                item.id ===
                                                                                                id,
                                                                                        );

                                                                                    return (
                                                                                        <li
                                                                                            key={
                                                                                                id
                                                                                            }
                                                                                            className="flex items-center gap-2 p-2"
                                                                                        >
                                                                                            <div className="min-w-0 flex-1 text-sm">
                                                                                                <p className="break-words">
                                                                                                    {member?.name ??
                                                                                                        'Anggota tidak tersedia'}
                                                                                                </p>
                                                                                                <p className="text-xs break-words text-muted-foreground">
                                                                                                    {
                                                                                                        member?.email
                                                                                                    }
                                                                                                </p>
                                                                                            </div>
                                                                                            {!readOnly && (
                                                                                                <Button
                                                                                                    type="button"
                                                                                                    variant="ghost"
                                                                                                    size="icon-sm"
                                                                                                    disabled={
                                                                                                        blocked
                                                                                                    }
                                                                                                    aria-label={`Keluarkan ${member?.name ?? 'anggota'}`}
                                                                                                    onClick={() =>
                                                                                                        toggleMember(
                                                                                                            id,
                                                                                                            false,
                                                                                                        )
                                                                                                    }
                                                                                                >
                                                                                                    <X />
                                                                                                </Button>
                                                                                            )}
                                                                                        </li>
                                                                                    );
                                                                                },
                                                                            )}
                                                                        </ul>
                                                                    )}
                                                                </div>
                                                            </FormControl>
                                                        )}
                                                        <FormControl>
                                                            <WorkflowChoice
                                                                label="Syarat penyelesaian"
                                                                value={String(
                                                                    selectedConfig.completion_policy ??
                                                                        'single',
                                                                )}
                                                                disabled={
                                                                    blocked
                                                                }
                                                                onValueChange={(
                                                                    value,
                                                                ) =>
                                                                    updateConfig(
                                                                        {
                                                                            completion_policy:
                                                                                value,
                                                                        },
                                                                    )
                                                                }
                                                                items={[
                                                                    {
                                                                        value: 'single',
                                                                        label: 'Salah satu penerima menyetujui',
                                                                    },
                                                                    {
                                                                        value: 'majority',
                                                                        label: 'Mayoritas penerima menyetujui',
                                                                    },
                                                                    {
                                                                        value: 'percentage',
                                                                        label: 'Persentase penerima menyetujui',
                                                                    },
                                                                    {
                                                                        value: 'all',
                                                                        label: 'Semua penerima menyetujui',
                                                                    },
                                                                ]}
                                                            />
                                                        </FormControl>
                                                        {selectedConfig.completion_policy ===
                                                            'percentage' && (
                                                            <FormControl>
                                                                <Input
                                                                    label="Persentase minimal (%)"
                                                                    type="number"
                                                                    min={1}
                                                                    max={100}
                                                                    value={String(
                                                                        selectedConfig.completion_percentage ??
                                                                            50,
                                                                    )}
                                                                    disabled={
                                                                        blocked
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        updateConfig(
                                                                            {
                                                                                completion_percentage:
                                                                                    event
                                                                                        .target
                                                                                        .value,
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                            </FormControl>
                                                        )}
                                                    </>
                                                )}
                                                {selected.type ===
                                                    'condition' && (
                                                    <>
                                                        <FormControl
                                                            error={
                                                                !context.readable
                                                                    ? 'Daftar field tidak dapat dibaca. Hubungi pengelola aplikasi.'
                                                                    : undefined
                                                            }
                                                        >
                                                            <WorkflowChoice
                                                                label="Field yang diperiksa"
                                                                value={String(
                                                                    selectedConfig.field ??
                                                                        '',
                                                                )}
                                                                disabled={
                                                                    blocked ||
                                                                    !context.readable
                                                                }
                                                                onValueChange={(
                                                                    value,
                                                                ) =>
                                                                    updateConfig(
                                                                        {
                                                                            field: value,
                                                                        },
                                                                    )
                                                                }
                                                                items={context.fields.map(
                                                                    (
                                                                        field,
                                                                    ) => ({
                                                                        value: field,
                                                                        label: field,
                                                                    }),
                                                                )}
                                                            />
                                                        </FormControl>
                                                        <FormControl>
                                                            <WorkflowChoice
                                                                label="Operator"
                                                                value={String(
                                                                    selectedConfig.operator ??
                                                                        'equals',
                                                                )}
                                                                disabled={
                                                                    blocked
                                                                }
                                                                onValueChange={(
                                                                    value,
                                                                ) =>
                                                                    updateConfig(
                                                                        {
                                                                            operator:
                                                                                value,
                                                                        },
                                                                    )
                                                                }
                                                                items={[
                                                                    {
                                                                        value: 'equals',
                                                                        label: 'Sama dengan',
                                                                    },
                                                                    {
                                                                        value: 'not_equals',
                                                                        label: 'Tidak sama dengan',
                                                                    },
                                                                    {
                                                                        value: 'greater_than',
                                                                        label: 'Lebih besar dari',
                                                                    },
                                                                    {
                                                                        value: 'greater_or_equal',
                                                                        label: 'Lebih besar atau sama',
                                                                    },
                                                                    {
                                                                        value: 'less_than',
                                                                        label: 'Lebih kecil dari',
                                                                    },
                                                                    {
                                                                        value: 'less_or_equal',
                                                                        label: 'Lebih kecil atau sama',
                                                                    },
                                                                    {
                                                                        value: 'contains',
                                                                        label: 'Mengandung teks',
                                                                    },
                                                                    {
                                                                        value: 'is_true',
                                                                        label: 'Bernilai benar',
                                                                    },
                                                                    {
                                                                        value: 'is_false',
                                                                        label: 'Bernilai salah',
                                                                    },
                                                                ]}
                                                            />
                                                        </FormControl>
                                                        <FormControl>
                                                            <Input
                                                                label="Nilai pembanding"
                                                                value={String(
                                                                    selectedConfig.value ??
                                                                        '',
                                                                )}
                                                                disabled={
                                                                    blocked
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    updateConfig(
                                                                        {
                                                                            value: event
                                                                                .target
                                                                                .value,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        </FormControl>
                                                    </>
                                                )}
                                            </FormGrid>
                                        </>
                                    )}
                                </aside>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </main>
            {transition.confirmation}
        </>
    );
}
