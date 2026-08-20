import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
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
import type { Connection, Edge, Node, NodeProps, NodeTypes } from '@xyflow/react';
import '@xyflow/react/dist/style.css';

import {
    ArrowLeft,
    Check,
    CheckCircle2,
    CircleAlert,
    GitBranch,
    GitCommit,
    GitFork,
    GitPullRequest,
    HelpCircle,
    Info,
    Layers,
    Lock,
    Play,
    Plus,
    Save,
    ShieldCheck,
    UserCheck,
    Users,
    Workflow as WorkflowIcon,
} from 'lucide-react';

import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import { Tooltip, TooltipContent, TooltipTrigger } from '@apperp/ui/tooltip';
import { Checkbox } from '@apperp/ui/checkbox';
import { Field, FieldDescription, FieldError } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { NativeSelect } from '@apperp/ui/native-select';
import Heading from '@/components/heading';

type Config = Record<string, unknown>;
type WorkflowNodeData = { label: string; config: Config };
type WorkflowNode = Node<WorkflowNodeData>;
type WorkflowEdge = Edge<{ outcome?: string | null; condition?: Config | null }>;
type Role = { id: string; name: string };
type Member = { id: string; name: string; email: string };
type Props = {
    canManage: boolean;
    workflow: {
        id: string;
        name: string;
        enabled: boolean;
        legal_entity_id: string | null;
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
    graph: { nodes: WorkflowNode[]; edges: WorkflowEdge[] };
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
            className={`min-w-48 rounded-xl border bg-card p-3 shadow-xs transition-all ${selected
                    ? 'border-primary ring-2 ring-primary/20 shadow-sm'
                    : 'border-border hover:border-border/80'
                }`}
        >
            {!isStart && (
                <Handle
                    type="target"
                    position={Position.Left}
                    className="!size-3 !bg-muted-foreground !border-2 !border-background"
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
                    {isStart ? 'A' : isEnd ? 'Z' : (nodeTitles[type ?? '']?.[0] ?? 'L')}
                </span>
                <p className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                    {nodeTitles[type ?? ''] ?? 'Langkah'}
                </p>
            </div>
            <p className="mt-1.5 text-xs font-bold text-foreground truncate">
                {data.label}
            </p>
            {isApproval && (
                <p className="mt-1 text-[11px] text-muted-foreground italic">
                    Atur pemeriksa di panel kanan
                </p>
            )}
            {isCondition && (
                <p className="mt-1 text-[10px] text-muted-foreground font-mono">
                    Cabang: True / False
                </p>
            )}

            {isCondition ? (
                <>
                    <Handle
                        id="true"
                        type="source"
                        position={Position.Right}
                        style={{ top: '35%' }}
                        className="!size-3 !bg-muted-foreground !border-2 !border-background"
                    />
                    <Handle
                        id="false"
                        type="source"
                        position={Position.Right}
                        style={{ top: '70%' }}
                        className="!size-3 !bg-muted-foreground !border-2 !border-background"
                    />
                </>
            ) : isApproval ? (
                <>
                    <Handle
                        id="approve"
                        type="source"
                        position={Position.Right}
                        style={{ top: '35%' }}
                        className="!size-3 !bg-muted-foreground !border-2 !border-background"
                    />
                    <Handle
                        id="reject"
                        type="source"
                        position={Position.Right}
                        style={{ top: '70%' }}
                        className="!size-3 !bg-muted-foreground !border-2 !border-background"
                    />
                </>
            ) : (
                !isEnd && (
                    <Handle
                        type="source"
                        position={Position.Right}
                        className="!size-3 !bg-muted-foreground !border-2 !border-background"
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

function contextFields(
    schema: Props['workflowType']['decision_context_schema'],
): string[] {
    try {
        const parsed = typeof schema === 'string' ? JSON.parse(schema) : schema;
        const properties = (parsed as { properties?: Record<string, unknown> })
            .properties;
        const required =
            (parsed as { required?: string[] }).required ?? [];

        return [...new Set([...required, ...Object.keys(properties ?? {})])];
    } catch {
        return [];
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
        | { type?: string; id?: string }
        | undefined;

    return assignee?.type === 'member' && assignee.id ? [assignee.id] : [];
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
        graph.edges,
    );
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const readOnly = !canManage || version.status !== 'draft';
    const selected = nodes.find((node) => node.id === selectedId) ?? null;
    const fields = useMemo(
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
        if (!selected) return;

        setNodes((current) =>
            current.map((node) =>
                node.id === selected.id
                    ? { ...node, data: { ...node.data, ...change } }
                    : node,
            ),
        );
    };

    const updateConfig = (change: Config) => {
        if (!selected) return;

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
        | { type?: string; id?: string }
        | undefined;
    const recipientMode =
        selectedAssignee?.type === 'role' ? 'role' : 'members';
    const selectedMemberIds = configuredMemberIds(selectedConfig);

    const setRecipientMode = (mode: string) => {
        if (mode === 'role') {
            updateConfig({
                assignees: null,
                assignee:
                    selectedAssignee?.type === 'role'
                        ? selectedAssignee
                        : null,
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
            | { type?: string; id?: string }
            | undefined;

        if (
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
    }, [members, roles, selected]);

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
        if (readOnly || !connection.source || !connection.target) return;

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

    const save = () => {
        const payload = {
            nodes: nodes.map((node) => ({
                id: node.id,
                type: node.type,
                data: node.data,
                position: node.position,
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
        router.put(
            `/settings/workflows/${workflow.id}/graph`,
            payload as never,
            { preserveScroll: true },
        );
    };

    const publish = () =>
        router.post(
            `/settings/workflows/${workflow.id}/publish`,
            {},
            { preserveScroll: true },
        );
    const createDraft = () =>
        router.post(
            `/settings/workflows/${workflow.id}/draft`,
            {},
            { preserveScroll: true },
        );

    return (
        <>
            <Head title={`Workflow — ${workflow.name}`} />
            <main className="mx-auto flex w-full max-w-[1500px] min-w-0 flex-col gap-6 p-6">
                {/* Top Bar Header */}
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            className="shadow-2xs text-xs font-medium"
                        >
                            <Link href="/settings/workflows">
                                <ArrowLeft className="mr-1.5 size-3.5" /> Kembali
                            </Link>
                        </Button>
                        <div>
                            <div className="flex items-center gap-2">
                                <h1 className="text-lg font-bold tracking-tight text-foreground">
                                    {workflow.name}
                                </h1>
                                <Badge variant="outline">
                                    {displayStatus}
                                </Badge>
                            </div>
                            <p className="text-xs text-muted-foreground mt-0.5">
                                {workflowType.app_name} — {workflowType.name}
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        {version.status === 'published' && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={createDraft}
                                disabled={!canManage}
                                className="text-xs font-medium"
                            >
                                <Plus className="mr-1.5 size-3.5" /> Buat Draf Baru
                            </Button>
                        )}
                        {version.status === 'draft' && (
                            <>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={save}
                                    disabled={readOnly}
                                    className="text-xs font-medium"
                                >
                                    <Save className="mr-1.5 size-3.5" /> Simpan Alur
                                </Button>
                                <Button
                                    size="sm"
                                    onClick={publish}
                                    disabled={readOnly}
                                    className="text-xs font-medium shadow-xs"
                                >
                                    <CheckCircle2 className="mr-1.5 size-3.5" /> Aktifkan Workflow
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                {/* Canvas Container */}
                <Card className="overflow-hidden shadow-xs border border-border">
                    <CardHeader className="border-b border-border bg-card/50 px-6 py-4">
                        <div className="flex items-center justify-between">
                            <div className="flex flex-row items-center gap-2">
                                <CardTitle className="text-base font-bold text-foreground">
                                    Canvas Editor Workflow
                                </CardTitle>
                                <Tooltip clickToPin>
                                    <TooltipTrigger asChild>
                                        <button
                                            type="button"
                                            aria-label="Lihat petunjuk canvas"
                                            className="shrink-0 text-muted-foreground hover:text-foreground p-0.5 rounded-full hover:bg-muted transition-colors cursor-pointer"
                                        >
                                            <CircleAlert className="size-4 text-muted-foreground hover:text-primary transition-colors" />
                                        </button>
                                    </TooltipTrigger>
                                    <TooltipContent side="right" className="max-w-xs text-xs">
                                        Tambah langkah dari panel kiri, seret garis antar-node untuk menghubungkan, dan klik elemen untuk mengedit detailnya.
                                    </TooltipContent>
                                </Tooltip>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="p-4 bg-card">
                        <div className="grid min-h-[660px] grid-cols-[240px_minmax(0,1fr)_320px] gap-4 items-stretch">
                            {/* Panel Kiri: Palette Tambah Langkah (Menyatu dalam Card Utama) */}
                            <aside className="space-y-4 p-2">
                                <div>
                                    <h3 className="text-xs font-bold uppercase tracking-wider text-muted-foreground">
                                        TAMBAH LANGKAH
                                    </h3>
                                    <p className="mt-1 text-[11px] text-muted-foreground leading-relaxed">
                                        Langkah Mulai dan Selesai sudah tersedia secara default.
                                    </p>
                                </div>
                                <div className="space-y-2">
                                    {palette.map(([type, label, IconComponent]) => (
                                        <Button
                                            key={type}
                                            type="button"
                                            variant="outline"
                                            disabled={readOnly}
                                            className="w-full justify-start text-xs font-medium bg-background hover:bg-accent cursor-pointer shadow-2xs"
                                            onClick={() => addNode(type)}
                                        >
                                            <IconComponent className="mr-2 size-3.5 text-primary shrink-0" />
                                            {label}
                                        </Button>
                                    ))}
                                </div>
                            </aside>

                            {/* Canvas Tengah: Satu-satunya Area Ber-Corner Pembatas Membulat (rounded-xl) */}
                            <div className="relative min-h-[660px] rounded-xl border border-border bg-background overflow-hidden shadow-2xs">
                                <ReactFlow
                                    nodes={nodes}
                                    edges={edges}
                                    nodeTypes={nodeTypes}
                                    onNodesChange={
                                        readOnly ? undefined : onNodesChange
                                    }
                                    onEdgesChange={
                                        readOnly ? undefined : onEdgesChange
                                    }
                                    onConnect={connect}
                                    onNodeClick={(_, node) =>
                                        setSelectedId(node.id)
                                    }
                                    onPaneClick={() => setSelectedId(null)}
                                    fitView
                                    deleteKeyCode={
                                        readOnly ? null : ['Backspace', 'Delete']
                                    }
                                    proOptions={{ hideAttribution: true }}
                                >
                                    <Background gap={20} size={1} />
                                    <Controls />
                                    <MiniMap pannable zoomable className="!bottom-3 !right-3 !rounded-lg overflow-hidden border border-border/60" />
                                    <Panel
                                        position="top-left"
                                        className="flex items-center gap-1.5 rounded-lg border border-border/80 bg-background/95 px-3 py-1.5 text-[11px] font-medium text-muted-foreground shadow-2xs backdrop-blur-xs"
                                    >
                                        {readOnly ? (
                                            <>
                                                <Lock className="size-3.5 text-muted-foreground shrink-0" />
                                                <span>Versi aktif hanya dapat dilihat.</span>
                                            </>
                                        ) : (
                                            <>
                                                <Info className="size-3.5 text-primary shrink-0" />
                                                <span>Draf aktif: Bebas ubah alur sebelum diaktifkan.</span>
                                            </>
                                        )}
                                    </Panel>
                                </ReactFlow>
                            </div>

                            {/* Panel Kanan: Properti Node Terpilih (Menyatu dalam Card Utama) */}
                            <aside className="p-2 space-y-4">
                                {!selected ? (
                                    <div className="flex flex-col items-center justify-center h-full py-12 text-center">
                                        <div className="flex size-10 items-center justify-center rounded-xl bg-muted/60 text-muted-foreground mb-2">
                                            <HelpCircle className="size-5" />
                                        </div>
                                        <h4 className="text-xs font-bold text-foreground">
                                            Properti Langkah
                                        </h4>
                                        <p className="mt-1 text-[11px] text-muted-foreground max-w-44 leading-relaxed">
                                            Pilih salah satu elemen node di canvas untuk mengubah nama, penerima tugas, atau syarat kondisi.
                                        </p>
                                    </div>
                                ) : (
                                    <div className="space-y-4">
                                        <div className="border-b border-border/60 pb-3">
                                            <h3 className="text-xs font-bold uppercase tracking-wider text-muted-foreground">
                                                PENGATURAN LANGKAH
                                            </h3>
                                            <p className="text-sm font-bold text-foreground mt-1">
                                                {nodeTitles[selected.type ?? '']}
                                            </p>
                                        </div>

                                        <Field>
                                            <Input
                                                label="Nama langkah"
                                                value={selected.data.label}
                                                disabled={
                                                    readOnly ||
                                                    selected.type === 'start' ||
                                                    selected.type === 'end'
                                                }
                                                onChange={(event) =>
                                                    updateSelected({
                                                        label: event.target.value,
                                                    })
                                                }
                                            />
                                        </Field>

                                        {(selected.type === 'approval' ||
                                            selected.type === 'manual_task') && (
                                                <>
                                                    <Field>
                                                        <NativeSelect
                                                            label="Jenis penerima"
                                                            value={recipientMode}
                                                            disabled={readOnly}
                                                            onChange={(event) =>
                                                                setRecipientMode(
                                                                    event.target.value,
                                                                )
                                                            }
                                                        >
                                                            <option value="role">
                                                                Role
                                                            </option>
                                                            <option value="members">
                                                                Anggota tertentu
                                                            </option>
                                                        </NativeSelect>
                                                    </Field>

                                                    {recipientMode === 'role' ? (
                                                        <Field>
                                                            <NativeSelect
                                                                label="Role penerima"
                                                                value={String(
                                                                    selectedAssignee?.id ?? '',
                                                                )}
                                                                disabled={readOnly}
                                                                onChange={(event) =>
                                                                    updateConfig({
                                                                        assignee: event.target.value
                                                                            ? {
                                                                                type: 'role',
                                                                                id: event.target.value,
                                                                            }
                                                                            : null,
                                                                        assignees: null,
                                                                    })
                                                                }
                                                            >
                                                                <option value="">
                                                                    Pilih role
                                                                </option>
                                                                {roles.map((role) => (
                                                                    <option
                                                                        key={role.id}
                                                                        value={role.id}
                                                                    >
                                                                        {role.name}
                                                                    </option>
                                                                ))}
                                                            </NativeSelect>
                                                        </Field>
                                                    ) : (
                                                        <Field>
                                                            <div className="space-y-2 rounded-lg border border-border/60 bg-background p-3">
                                                                <p className="text-xs font-semibold text-foreground">
                                                                    Pilih anggota penerima
                                                                </p>
                                                                {members.length === 0 ? (
                                                                    <p className="text-xs text-muted-foreground italic">
                                                                        Belum ada anggota aktif.
                                                                    </p>
                                                                ) : (
                                                                    <div className="space-y-2 max-h-48 overflow-y-auto pr-1">
                                                                        {members.map((member) => (
                                                                            <label
                                                                                key={member.id}
                                                                                className="flex items-start gap-2.5 text-xs cursor-pointer hover:bg-accent/40 p-1.5 rounded-md transition-colors"
                                                                            >
                                                                                <Checkbox
                                                                                    checked={selectedMemberIds.includes(
                                                                                        member.id,
                                                                                    )}
                                                                                    disabled={readOnly}
                                                                                    onCheckedChange={(
                                                                                        checked,
                                                                                    ) =>
                                                                                        toggleMember(
                                                                                            member.id,
                                                                                            checked === true,
                                                                                        )
                                                                                    }
                                                                                />
                                                                                <span className="leading-tight font-medium text-foreground">
                                                                                    {member.name}
                                                                                    <span className="block text-[11px] font-normal text-muted-foreground">
                                                                                        {member.email}
                                                                                    </span>
                                                                                </span>
                                                                            </label>
                                                                        ))}
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </Field>
                                                    )}

                                                    <Field>
                                                        <NativeSelect
                                                            label="Syarat penyelesaian"
                                                            value={String(
                                                                selectedConfig.completion_policy ??
                                                                'single',
                                                            )}
                                                            disabled={readOnly}
                                                            onChange={(event) =>
                                                                updateConfig({
                                                                    completion_policy:
                                                                        event.target.value,
                                                                })
                                                            }
                                                        >
                                                            <option value="single">
                                                                Salah satu penerima menyetujui
                                                            </option>
                                                            <option value="majority">
                                                                Mayoritas penerima menyetujui
                                                            </option>
                                                            <option value="percentage">
                                                                Persentase penerima menyetujui
                                                            </option>
                                                            <option value="all">
                                                                Semua penerima menyetujui
                                                            </option>
                                                        </NativeSelect>
                                                    </Field>

                                                    {selectedConfig.completion_policy ===
                                                        'percentage' && (
                                                            <Field>
                                                                <Input
                                                                    label="Persentase minimal (%)"
                                                                    type="number"
                                                                    min={1}
                                                                    max={100}
                                                                    value={String(
                                                                        selectedConfig.completion_percentage ??
                                                                        50,
                                                                    )}
                                                                    disabled={readOnly}
                                                                    onChange={(event) =>
                                                                        updateConfig({
                                                                            completion_percentage:
                                                                                event.target.value,
                                                                        })
                                                                    }
                                                                />
                                                            </Field>
                                                        )}
                                                </>
                                            )}

                                        {selected.type === 'condition' && (
                                            <>
                                                <Field>
                                                    <NativeSelect
                                                        label="Field yang diperiksa"
                                                        value={String(
                                                            selected.data.config.field ?? '',
                                                        )}
                                                        disabled={readOnly}
                                                        onChange={(event) =>
                                                            updateConfig({
                                                                field: event.target.value,
                                                            })
                                                        }
                                                    >
                                                        <option value="">
                                                            Pilih field
                                                        </option>
                                                        {fields.map((field) => (
                                                            <option
                                                                key={field}
                                                                value={field}
                                                            >
                                                                {field}
                                                            </option>
                                                        ))}
                                                    </NativeSelect>
                                                </Field>
                                                <Field>
                                                    <NativeSelect
                                                        label="Operator"
                                                        value={String(
                                                            selected.data.config.operator ??
                                                            'equals',
                                                        )}
                                                        disabled={readOnly}
                                                        onChange={(event) =>
                                                            updateConfig({
                                                                operator: event.target.value,
                                                            })
                                                        }
                                                    >
                                                        <option value="equals">
                                                            Sama dengan
                                                        </option>
                                                        <option value="not_equals">
                                                            Tidak sama dengan
                                                        </option>
                                                        <option value="greater_than">
                                                            Lebih besar dari
                                                        </option>
                                                        <option value="greater_or_equal">
                                                            Lebih besar atau sama
                                                        </option>
                                                        <option value="less_than">
                                                            Lebih kecil dari
                                                        </option>
                                                        <option value="less_or_equal">
                                                            Lebih kecil atau sama
                                                        </option>
                                                        <option value="contains">
                                                            Mengandung teks
                                                        </option>
                                                        <option value="is_true">
                                                            Bernilai benar
                                                        </option>
                                                        <option value="is_false">
                                                            Bernilai salah
                                                        </option>
                                                    </NativeSelect>
                                                </Field>
                                                <Field>
                                                    <Input
                                                        label="Nilai pembanding"
                                                        value={String(
                                                            selected.data.config.value ?? '',
                                                        )}
                                                        disabled={readOnly}
                                                        onChange={(event) =>
                                                            updateConfig({
                                                                value: event.target.value,
                                                            })
                                                        }
                                                    />
                                                </Field>
                                            </>
                                        )}
                                    </div>
                                )}
                            </aside>
                        </div>
                    </CardContent>
                </Card>
            </main>
        </>
    );
}
