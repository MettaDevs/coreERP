import { Head, useForm } from '@inertiajs/react';
import type { ChangeEvent } from 'react';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@apperp/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@apperp/ui/empty';
import { Field, FieldLabel } from '@apperp/ui/field';
import { Textarea } from '@apperp/ui/textarea';
import Heading from '@/components/heading';

type WorkItem = { id: string; created_at: string; source_document_type: string; source_document_id: string; workflow_name: string; app_name: string };
type Props = { items: WorkItem[] };
export default function WorkflowInbox({ items }: Props) {
    const form = useForm({ decision: '', comment: '' });
    const decide = (id: string, decision: 'approve' | 'reject') => {
        form.transform((data) => ({ ...data, decision }));
        form.post(`/workflow-inbox/${id}/decision`, { preserveScroll: true });
    };

    return <>
        <Head title="Persetujuan saya" />
        <main className="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-6 p-6">
            <Heading title="Persetujuan saya" description="Tinjau permintaan yang menunggu keputusan Anda." />
            <Card>
                <CardHeader><CardTitle>Menunggu keputusan</CardTitle><CardDescription>Keputusan dicatat dan tidak dapat diubah dari layar ini.</CardDescription></CardHeader>
                <CardContent className="space-y-4">
                    {items.length === 0 ? <Empty><EmptyHeader><EmptyTitle>Tidak ada permintaan</EmptyTitle><EmptyDescription>Anda tidak memiliki permintaan yang perlu ditinjau.</EmptyDescription></EmptyHeader></Empty> : items.map((item) => <div key={item.id} className="rounded-md border p-4">
                        <div className="flex flex-wrap items-start justify-between gap-2"><div><p className="font-medium">{item.workflow_name}</p><p className="text-sm text-muted-foreground">{item.app_name} · Dokumen {item.source_document_type}</p><p className="mt-1 break-all text-sm text-muted-foreground">ID dokumen: {item.source_document_id}</p></div><Badge variant="secondary">Menunggu</Badge></div>
                        <Field className="mt-4"><FieldLabel>Catatan keputusan (opsional)</FieldLabel><Textarea value={form.data.comment} onChange={(event: ChangeEvent<HTMLTextAreaElement>) => form.setData('comment', event.target.value)} /></Field>
                        <div className="mt-3 flex gap-2"><Button type="button" disabled={form.processing} onClick={() => decide(item.id, 'approve')}>Setujui</Button><Button type="button" variant="outline" disabled={form.processing} onClick={() => decide(item.id, 'reject')}>Tolak</Button></div>
                    </div>)}
                </CardContent>
            </Card>
        </main>
    </>;
}
