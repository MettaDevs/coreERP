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
    useNodesState
} from '@xyflow/react';
import type {Connection, Edge, Node, NodeProps, NodeTypes} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import { useEffect, useMemo, useState } from 'react';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@apperp/ui/card';
import { Checkbox } from '@apperp/ui/checkbox';
import { Field } from '@apperp/ui/field';
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
    workflow: { id: string; name: string; enabled: boolean; legal_entity_id: string | null };
    workflowType: { id: string; name: string; code: string; scope: string; app_name: string; decision_context_schema: string | Record<string, unknown> };
    version: { id: string | null; status: 'draft' | 'published' | null; version: number | null };
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
    ['approval', 'Persetujuan'],
    ['manual_task', 'Tugas manual'],
    ['condition', 'Keputusan kondisi'],
    ['parallel', 'Cabang paralel'],
] as const;

function FlowNode({ data, selected, type }: NodeProps<WorkflowNode>) {
    return (
        <div className={`min-w-44 rounded-lg border bg-background px-3 py-2 shadow-sm ${selected ? 'ring-2 ring-primary' : ''}`}>
            {type !== 'start' && <Handle type="target" position={Position.Left} />}
            <p className="text-xs font-medium text-muted-foreground">{nodeTitles[type ?? ''] ?? 'Langkah'}</p>
            <p className="mt-1 text-sm font-semibold">{data.label}</p>
            {type === 'approval' && <p className="mt-1 text-xs text-muted-foreground">Pilih pemeriksa di panel kanan</p>}
            {type === 'condition' && <p className="mt-1 text-xs text-muted-foreground">true / false</p>}
            {type === 'condition' ? <><Handle id="true" type="source" position={Position.Right} style={{ top: '35%' }} /><Handle id="false" type="source" position={Position.Right} style={{ top: '70%' }} /></> : type === 'approval' ? <><Handle id="approve" type="source" position={Position.Right} style={{ top: '35%' }} /><Handle id="reject" type="source" position={Position.Right} style={{ top: '70%' }} /></> : type !== 'end' && <Handle type="source" position={Position.Right} />}
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

function contextFields(schema: Props['workflowType']['decision_context_schema']): string[] {
    const parsed = typeof schema === 'string' ? JSON.parse(schema) : schema;
    const properties = (parsed as { properties?: Record<string, unknown> }).properties;
    const required = (parsed as { required?: string[] }).required ?? [];

    return [...new Set([...required, ...Object.keys(properties ?? {})])];
}

function configuredMemberIds(config: Config): string[] {
    const assignees = config.assignees;

    if (Array.isArray(assignees)) {
        return assignees
            .filter((assignee): assignee is { type?: string; id?: string } => typeof assignee === 'object' && assignee !== null)
            .filter((assignee) => assignee.type === 'member' && Boolean(assignee.id))
            .map((assignee) => String(assignee.id));
    }

    const assignee = config.assignee as { type?: string; id?: string } | undefined;

    return assignee?.type === 'member' && assignee.id ? [assignee.id] : [];
}

export default function WorkflowEditor({ canManage, workflow, workflowType, version, graph, roles, members }: Props) {
    const [nodes, setNodes, onNodesChange] = useNodesState<WorkflowNode>(graph.nodes);
    const [edges, setEdges, onEdgesChange] = useEdgesState<WorkflowEdge>(graph.edges);
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const readOnly = !canManage || version.status !== 'draft';
    const selected = nodes.find((node) => node.id === selectedId) ?? null;
    const fields = useMemo(() => contextFields(workflowType.decision_context_schema), [workflowType.decision_context_schema]);
    const displayStatus = version.status === 'published' ? workflow.enabled ? 'Aktif' : 'Nonaktif' : 'Draf';
    const updateSelected = (change: Partial<WorkflowNodeData>) => {
        if (!selected) {
return;
}

        setNodes((current) => current.map((node) => node.id === selected.id ? { ...node, data: { ...node.data, ...change } } : node));
    };
    const updateConfig = (change: Config) => {
        if (!selected) {
return;
}

        setNodes((current) => current.map((node) => node.id === selected.id ? { ...node, data: { ...node.data, config: { ...node.data.config, ...change } } } : node));
    };
    const selectedConfig = selected?.data.config ?? {};
    const selectedAssignee = selectedConfig.assignee as { type?: string; id?: string } | undefined;
    const recipientMode = selectedAssignee?.type === 'role' ? 'role' : 'members';
    const selectedMemberIds = configuredMemberIds(selectedConfig);
    const setRecipientMode = (mode: string) => {
        if (mode === 'role') {
            updateConfig({ assignees: null, assignee: selectedAssignee?.type === 'role' ? selectedAssignee : null });

            return;
        }

        updateConfig({ assignee: null, assignees: selectedMemberIds.map((id) => ({ type: 'member', id })) });
    };
    const toggleMember = (memberId: string, checked: boolean) => {
        const nextIds = new Set(selectedMemberIds);

        if (checked) {
            nextIds.add(memberId);
        } else {
            nextIds.delete(memberId);
        }

        updateConfig({ assignee: null, assignees: [...nextIds].map((id) => ({ type: 'member', id })) });
    };
    useEffect(() => {
        const assignee = selected?.data.config.assignee as { type?: string; id?: string } | undefined;

        if (!selected || !assignee?.id || assignee.type !== 'role' || roles.some((role) => role.id === assignee.id) || !members.some((member) => member.id === assignee.id)) {
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
        setNodes((current) => [...current, { id, type, position: { x: lastX + 260, y: 180 }, data: { label: nodeTitles[type], config: type === 'approval' || type === 'manual_task' ? { completion_policy: 'single' } : {} } }]);
        setSelectedId(id);
    };
    const connect = (connection: Connection) => {
        if (readOnly || !connection.source || !connection.target) {
return;
}

        const source = nodes.find((node) => node.id === connection.source);
        const outcome = connection.sourceHandle ?? (source?.type === 'condition' ? 'true' : source?.type === 'approval' ? 'approve' : null);
        setEdges((current) => addEdge({ ...connection, id: `${connection.source}-${connection.target}-${current.length + 1}`, label: outcome ?? undefined, data: { outcome } }, current));
    };
    const save = () => {
        const payload = {
            nodes: nodes.map((node) => ({ id: node.id, type: node.type, data: node.data, position: node.position })),
            edges: edges.map((edge) => ({ source: edge.source, target: edge.target, outcome: edge.data?.outcome ?? (typeof edge.label === 'string' ? edge.label : null), condition: edge.data?.condition ?? null })),
        };
        router.put(`/settings/workflows/${workflow.id}/graph`, payload as never, { preserveScroll: true });
    };
    const publish = () => router.post(`/settings/workflows/${workflow.id}/publish`, {}, { preserveScroll: true });
    const createDraft = () => router.post(`/settings/workflows/${workflow.id}/draft`, {}, { preserveScroll: true });

    return (
        <>
            <Head title={`Workflow — ${workflow.name}`} />
            <main className="mx-auto flex w-full max-w-[1500px] min-w-0 flex-col gap-5 p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading title={workflow.name} description={`${workflowType.app_name} — ${workflowType.name}`} />
                    <div className="flex items-center gap-2">
                        <Badge variant={displayStatus === 'Aktif' ? 'default' : 'secondary'}>{displayStatus}</Badge>
                        <Button asChild variant="outline"><Link href="/settings/workflows">Kembali</Link></Button>
                        {version.status === 'published' && <Button onClick={createDraft} disabled={!canManage}>Buat draf baru</Button>}
                        {version.status === 'draft' && <><Button variant="outline" onClick={save} disabled={readOnly}>Simpan alur</Button><Button onClick={publish} disabled={readOnly}>Aktifkan workflow</Button></>}
                    </div>
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>Editor workflow</CardTitle>
                        <CardDescription>Tambah langkah dari daftar, lalu hubungkan titik di kanan dan kiri setiap elemen. Klik elemen untuk mengatur detailnya.</CardDescription>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="grid min-h-[620px] grid-cols-[220px_minmax(0,1fr)_300px] overflow-hidden rounded-b-lg border-t">
                            <aside className="space-y-3 border-r bg-muted/20 p-4">
                                <div><p className="text-sm font-semibold">Tambah langkah</p><p className="mt-1 text-xs text-muted-foreground">Mulai dan Selesai sudah tersedia. Tambahkan langkah lain sesuai proses.</p></div>
                                <div className="space-y-2">{palette.map(([type, label]) => <Button key={type} className="w-full justify-start" variant="outline" disabled={readOnly} onClick={() => addNode(type)}>{label}</Button>)}</div>
                            </aside>
                            <div className="relative min-h-[620px] bg-background">
                                <ReactFlow nodes={nodes} edges={edges} nodeTypes={nodeTypes} onNodesChange={readOnly ? undefined : onNodesChange} onEdgesChange={readOnly ? undefined : onEdgesChange} onConnect={connect} onNodeClick={(_, node) => setSelectedId(node.id)} onPaneClick={() => setSelectedId(null)} fitView deleteKeyCode={readOnly ? null : ['Backspace', 'Delete']} proOptions={{ hideAttribution: true }}>
                                    <Background gap={18} size={1} />
                                    <Controls />
                                    <MiniMap pannable zoomable />
                                    <Panel position="top-left" className="rounded-md border bg-background/95 px-3 py-2 text-xs text-muted-foreground shadow-sm">{readOnly ? 'Versi aktif hanya dapat dilihat.' : 'Draf dapat diubah sebelum diaktifkan.'}</Panel>
                                </ReactFlow>
                            </div>
                            <aside className="border-l bg-muted/10 p-4">
                                {!selected ? (
                                    <div>
                                        <p className="text-sm font-semibold">Properti langkah</p>
                                        <p className="mt-2 text-xs text-muted-foreground">Pilih elemen di canvas untuk mengubah nama, penerima tugas, atau kondisi.</p>
                                    </div>
                                ) : (
                                    <div className="space-y-4">
                                        <div>
                                            <p className="text-sm font-semibold">{nodeTitles[selected.type ?? '']}</p>
                                            <p className="text-xs text-muted-foreground">Pengaturan langkah</p>
                                        </div>
                                        <Field>
                                            <Input label="Nama langkah" value={selected.data.label} disabled={readOnly || selected.type === 'start' || selected.type === 'end'} onChange={(event) => updateSelected({ label: event.target.value })} />
                                        </Field>
                                        {(selected.type === 'approval' || selected.type === 'manual_task') && (
                                            <>
                                                <Field>
                                                    <NativeSelect label="Jenis penerima" value={recipientMode} disabled={readOnly} onChange={(event) => setRecipientMode(event.target.value)}>
                                                        <option value="role">Role</option>
                                                        <option value="members">Anggota tertentu</option>
                                                    </NativeSelect>
                                                </Field>
                                                {recipientMode === 'role' ? (
                                                    <Field>
                                                        <NativeSelect label="Role penerima" value={String(selectedAssignee?.id ?? '')} disabled={readOnly} onChange={(event) => updateConfig({ assignee: event.target.value ? { type: 'role', id: event.target.value } : null, assignees: null })}>
                                                            <option value="">Pilih role</option>
                                                            {roles.map((role) => <option key={role.id} value={role.id}>{role.name}</option>)}
                                                        </NativeSelect>
                                                    </Field>
                                                ) : (
                                                    <Field>
                                                        <div className="space-y-2 rounded-md border bg-background p-3">
                                                            <p className="text-xs font-medium">Pilih satu atau beberapa anggota</p>
                                                            {members.length === 0 ? (
                                                                <p className="text-xs text-muted-foreground">Belum ada anggota aktif.</p>
                                                            ) : (
                                                                members.map((member) => (
                                                                    <label key={member.id} className="flex items-start gap-2 text-sm">
                                                                        <Checkbox checked={selectedMemberIds.includes(member.id)} disabled={readOnly} onCheckedChange={(checked) => toggleMember(member.id, checked === true)} />
                                                                        <span className="leading-tight">{member.name}<span className="block text-xs text-muted-foreground">{member.email}</span></span>
                                                                    </label>
                                                                ))
                                                            )}
                                                        </div>
                                                    </Field>
                                                )}
                                                <Field>
                                                    <NativeSelect label="Syarat langkah selesai" value={String(selectedConfig.completion_policy ?? 'single')} disabled={readOnly} onChange={(event) => updateConfig({ completion_policy: event.target.value })}>
                                                        <option value="single">Salah satu penerima menyetujui</option>
                                                        <option value="majority">Mayoritas penerima menyetujui</option>
                                                        <option value="percentage">Persentase penerima menyetujui</option>
                                                        <option value="all">Semua penerima menyetujui</option>
                                                    </NativeSelect>
                                                </Field>
                                                {selectedConfig.completion_policy === 'percentage' && (
                                                    <Field>
                                                        <Input label="Persentase minimal" type="number" min={1} max={100} value={String(selectedConfig.completion_percentage ?? 50)} disabled={readOnly} onChange={(event) => updateConfig({ completion_percentage: event.target.value })} />
                                                    </Field>
                                                )}
                                                <p className="text-xs text-muted-foreground">Role mengirim tugas ke semua anggota role aktif. Untuk penerima tertentu, pilih anggota satu per satu.</p>
                                            </>
                                        )}
                                        {selected.type === 'condition' && (
                                            <>
                                                <Field>
                                                    <NativeSelect label="Field yang diperiksa" value={String(selected.data.config.field ?? '')} disabled={readOnly} onChange={(event) => updateConfig({ field: event.target.value })}>
                                                        <option value="">Pilih field</option>
                                                        {fields.map((field) => <option key={field} value={field}>{field}</option>)}
                                                    </NativeSelect>
                                                </Field>
                                                <Field>
                                                    <NativeSelect label="Operator" value={String(selected.data.config.operator ?? 'equals')} disabled={readOnly} onChange={(event) => updateConfig({ operator: event.target.value })}>
                                                        <option value="equals">Sama dengan</option>
                                                        <option value="not_equals">Tidak sama dengan</option>
                                                        <option value="greater_than">Lebih besar dari</option>
                                                        <option value="greater_or_equal">Lebih besar atau sama</option>
                                                        <option value="less_than">Lebih kecil dari</option>
                                                        <option value="less_or_equal">Lebih kecil atau sama</option>
                                                        <option value="contains">Mengandung teks</option>
                                                        <option value="is_true">Bernilai benar</option>
                                                        <option value="is_false">Bernilai salah</option>
                                                    </NativeSelect>
                                                </Field>
                                                <Field>
                                                    <Input label="Nilai pembanding" value={String(selected.data.config.value ?? '')} disabled={readOnly} onChange={(event) => updateConfig({ value: event.target.value })} />
                                                </Field>
                                                <p className="text-xs text-muted-foreground">Hubungkan dua garis keluar dari node ini. Garis pertama diberi label true dan garis kedua false.</p>
                                            </>
                                        )}
                                        {selected.type === 'parallel' && <p className="text-xs text-muted-foreground">Hubungkan minimal dua langkah berikutnya. Workflow menunggu seluruh cabang selesai.</p>}
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
