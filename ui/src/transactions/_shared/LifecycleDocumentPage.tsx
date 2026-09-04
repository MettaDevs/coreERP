import { useEffect, useState } from 'react';
import { Button } from '@apperp/ui/button';
import { Card, CardAction, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@apperp/ui/empty';
import { Field } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@apperp/ui/sheet';
import { api, errorMessage, newIdempotencyKey } from '../../api';

type Context = { legal_entity_id: string | null; org_unit_id: string | null };
type Record = {
    id: string;
    kode: string;
    tanggal: string;
    status: string;
    keterangan: string | null;
};
export type LifecycleConfig = {
    resource: string;
    title: string;
    action: string;
    needsAsset: boolean;
    financial: boolean;
};

export default function LifecycleDocumentPage({
    context,
    config,
}: {
    context: Context;
    config: LifecycleConfig;
}) {
    const [records, setRecords] = useState<Record[]>([]);
    const [open, setOpen] = useState(false);
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const load = () =>
        api<{ data: Record[] }>('/' + config.resource)
            .then((result) => setRecords(result.data))
            .catch((caught) => setError(errorMessage(caught, 'Data belum dapat dimuat.')));
    useEffect(() => {
        void load();
    }, [config.resource]);
    async function save(form: HTMLFormElement) {
        if (!context.legal_entity_id || !context.org_unit_id) {
            setError(
                'Pilih entitas legal dan unit kerja aktif di CoreERP sebelum membuat dokumen.',
            );
            return;
        }
        const values = new FormData(form);
        setSaving(true);
        setError('');
        try {
            await api('/' + config.resource, {
                method: 'POST',
                headers: { 'Idempotency-Key': newIdempotencyKey() },
                body: JSON.stringify({
                    legal_entity_id: context.legal_entity_id,
                    responsible_org_unit_id: context.org_unit_id,
                    tanggal: values.get('tanggal'),
                    asset_id: values.get('asset_id') || null,
                    nilai: values.get('nilai') || null,
                    keterangan: values.get('keterangan') || null,
                }),
            });
            setOpen(false);
            form.reset();
            await load();
        } catch (caught) {
            setError(errorMessage(caught, 'Dokumen belum dapat disimpan.'));
        } finally {
            setSaving(false);
        }
    }
    return (
        <Card className="min-h-full rounded-none border-0 shadow-none">
            <CardHeader className="border-b px-5 py-3">
                <CardTitle>{config.title}</CardTitle>
                <CardAction>
                    <Button onClick={() => setOpen(true)}>{config.action}</Button>
                </CardAction>
            </CardHeader>
            <CardContent className="px-0">
                {error && <p className="px-5 py-3 text-sm text-destructive">{error}</p>}
                {!records.length ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>Belum ada dokumen</EmptyTitle>
                            <EmptyDescription>
                                Dokumen ini tidak mengharuskan aset sudah diterima, kecuali
                                transaksi yang memang memilih aset.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <div className="divide-y">
                        {records.map((record) => (
                            <div
                                key={record.id}
                                className="flex items-center justify-between px-5 py-3"
                            >
                                <div>
                                    <p className="font-medium">{record.kode}</p>
                                    <p className="text-sm text-muted-foreground">
                                        {record.tanggal}
                                        {record.keterangan ? ` — ${record.keterangan}` : ''}
                                    </p>
                                </div>
                                <span className="text-sm">{record.status}</span>
                            </div>
                        ))}
                    </div>
                )}
            </CardContent>
            <Sheet open={open} onOpenChange={setOpen}>
                <SheetContent side="right">
                    <SheetHeader>
                        <SheetTitle>{config.action}</SheetTitle>
                    </SheetHeader>
                    <form
                        className="space-y-4 p-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void save(event.currentTarget);
                        }}
                    >
                        <Field>
                            <Input name="tanggal" label="Tanggal" type="date" required />
                        </Field>
                        {config.needsAsset && (
                            <Field>
                                <Input name="asset_id" label="ID aset" required />
                            </Field>
                        )}
                        {config.financial && (
                            <Field>
                                <Input
                                    name="nilai"
                                    label="Nilai usulan"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                />
                            </Field>
                        )}
                        <Field>
                            <Input name="keterangan" label="Keterangan" />
                        </Field>
                        <SheetFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Batal
                            </Button>
                            <Button type="submit" disabled={saving}>
                                {saving ? 'Menyimpan…' : 'Simpan'}
                            </Button>
                        </SheetFooter>
                    </form>
                </SheetContent>
            </Sheet>
        </Card>
    );
}
