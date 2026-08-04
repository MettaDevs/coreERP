import { useEffect, useState } from 'react';
import { Button } from '@apperp/ui/button';
import { Card, CardAction, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@apperp/ui/empty';
import { Field, FieldError } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@apperp/ui/sheet';
import { api, errorMessage } from '../../api';

type Context = { legal_entity_id: string | null; org_unit_id: string | null; user_id: string | number | null };
type Asset = { id: string; kode: string; serial_number: string | null; acquisition_value: string; currency_code: string; lifecycle_state: string };
type AssetType = { id: string; kode: string; nama: string };
type Placement = { id: string; effective_on: string; reason: string | null; receiving_org_unit_id: string | null; usage_org_unit_id: string | null; received_by_user_id: string | null; custodian_user_id: string | null; asset_location_id: string | null };

export default function AssetPage({ context }: { context: Context }) {
    const [assets, setAssets] = useState<Asset[]>([]);
    const [types, setTypes] = useState<AssetType[]>([]);
    const [open, setOpen] = useState(false);
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const [history, setHistory] = useState<{ asset: Asset; placements: Placement[] } | null>(null);

    const load = () => Promise.all([
        api<{ data: Asset[] }>('/aset').then((result) => setAssets(result.data)),
        api<{ data: AssetType[] }>('/jenis-aset?per_page=100&aktif=true').then((result) => setTypes(result.data)),
    ]).catch((caught) => setError(errorMessage(caught, 'Register aset belum dapat dimuat.')));

    useEffect(() => { void load(); }, []);

    async function showHistory(asset: Asset) {
        try { setHistory((await api<{ data: { asset: Asset; placements: Placement[] } }>(`/aset/${asset.id}/history`)).data); }
        catch (caught) { setError(errorMessage(caught, 'Riwayat aset belum dapat dimuat.')); }
    }

    async function receive(form: HTMLFormElement) {
        if (!context.legal_entity_id) {
            setError('Pilih entitas legal aktif di CoreERP sebelum menerima aset.');
            return;
        }
        const values = new FormData(form);
        setSaving(true); setError('');
        try {
            await api('/aset', {
                method: 'POST', headers: { 'Idempotency-Key': crypto.randomUUID() },
                body: JSON.stringify({
                    legal_entity_id: context.legal_entity_id,
                    jenis_aset_id: values.get('jenis_aset_id'),
                    acquired_on: values.get('acquired_on'),
                    acquisition_value: values.get('acquisition_value'),
                    currency_code: values.get('currency_code'),
                    serial_number: values.get('serial_number') || null,
                    model_number: values.get('model_number') || null,
                    receiving_org_unit_id: values.get('receiving_org_unit_id') || context.org_unit_id,
                    usage_org_unit_id: values.get('usage_org_unit_id') || context.org_unit_id,
                    received_by_user_id: values.get('received_by_user_id') || (context.user_id === null ? null : String(context.user_id)),
                    custodian_user_id: values.get('custodian_user_id') || null,
                    asset_location_id: values.get('asset_location_id') || null,
                    depreciation_profile_id: values.get('depreciation_profile_id') || null,
                    book_code: values.get('book_code') || null,
                    residual_value: values.get('residual_value') || null,
                }),
            });
            setOpen(false); form.reset(); await load();
        } catch (caught) { setError(errorMessage(caught, 'Aset belum dapat diterima.')); }
        finally { setSaving(false); }
    }

    return <Card className="min-h-full rounded-none border-0 shadow-none">
        <CardHeader className="border-b px-5 py-3"><CardTitle>Register aset</CardTitle><CardAction><Button onClick={() => setOpen(true)}>Terima aset</Button></CardAction></CardHeader>
        <CardContent className="px-0">
            {error && <div className="px-5 py-3 text-sm text-destructive">{error}</div>}
            {!assets.length ? <Empty><EmptyHeader><EmptyTitle>Belum ada aset</EmptyTitle><EmptyDescription>Catat penerimaan aset pertama untuk mulai memantau lokasi, pengguna, dan penyusutannya.</EmptyDescription></EmptyHeader></Empty> :
                <div className="divide-y">{assets.map((asset) => <div key={asset.id} className="flex items-center justify-between px-5 py-3"><div><p className="font-medium">{asset.kode}</p><p className="text-sm text-muted-foreground">{asset.serial_number || 'Tanpa nomor seri'}</p></div><div className="flex items-center gap-3"><span className="text-sm">{asset.currency_code} {asset.acquisition_value}</span><Button variant="outline" size="sm" onClick={() => void showHistory(asset)}>Riwayat</Button></div></div>)}</div>}
        </CardContent>
        <Sheet open={open} onOpenChange={setOpen}><SheetContent side="right"><SheetHeader><SheetTitle>Terima aset</SheetTitle></SheetHeader><form className="space-y-4 p-4" onSubmit={(event) => { event.preventDefault(); void receive(event.currentTarget); }}>
            <Field><Input name="jenis_aset_id" label="Jenis aset" required list="asset-types" /><datalist id="asset-types">{types.map((type) => <option key={type.id} value={type.id}>{type.kode} — {type.nama}</option>)}</datalist><FieldError>Gunakan ID jenis aset yang tersedia.</FieldError></Field>
            <Field><Input name="acquired_on" label="Tanggal penerimaan" type="date" required /></Field>
            <Field><Input name="acquisition_value" label="Nilai perolehan" type="number" min="0" step="0.01" required /></Field>
            <Field><Input name="currency_code" label="Mata uang" defaultValue="IDR" maxLength={3} required /></Field>
            <Field><Input name="serial_number" label="Nomor seri" /></Field>
            <Field><Input name="model_number" label="Model" /></Field>
            <Field><Input name="receiving_org_unit_id" label="ID unit penerima" defaultValue={context.org_unit_id ?? ''} /></Field>
            <Field><Input name="usage_org_unit_id" label="ID unit pengguna" defaultValue={context.org_unit_id ?? ''} /></Field>
            <Field><Input name="received_by_user_id" label="ID penerima" defaultValue={context.user_id === null ? '' : String(context.user_id)} /></Field>
            <Field><Input name="custodian_user_id" label="ID PIC aset" /></Field>
            <Field><Input name="asset_location_id" label="ID lokasi aset" /></Field>
            <Field><Input name="depreciation_profile_id" label="ID profil penyusutan" /></Field>
            <Field><Input name="book_code" label="Kode buku aset" defaultValue="PRIMARY" /></Field>
            <Field><Input name="residual_value" label="Nilai residu" type="number" min="0" step="0.01" /></Field>
            <SheetFooter><Button type="button" variant="outline" onClick={() => setOpen(false)}>Batal</Button><Button type="submit" disabled={saving}>{saving ? 'Menyimpan…' : 'Simpan penerimaan'}</Button></SheetFooter>
        </form></SheetContent></Sheet>
        <Sheet open={history !== null} onOpenChange={(open) => { if (!open) setHistory(null); }}><SheetContent side="right"><SheetHeader><SheetTitle>Riwayat aset {history?.asset.kode}</SheetTitle></SheetHeader><div className="space-y-3 p-4">{history?.placements.map((placement) => <div className="rounded border p-3" key={placement.id}><p className="font-medium">{placement.effective_on}</p><p className="text-sm text-muted-foreground">{placement.reason || 'Penempatan aset'}</p><p className="text-sm">Unit pengguna: {placement.usage_org_unit_id || 'Belum dipilih'}</p><p className="text-sm">PIC: {placement.custodian_user_id || 'Belum dipilih'}</p><p className="text-sm">Lokasi: {placement.asset_location_id || 'Belum dipilih'}</p></div>)}</div></SheetContent></Sheet>
    </Card>;
}
