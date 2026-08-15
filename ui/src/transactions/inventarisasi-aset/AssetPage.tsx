import { useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@apperp/ui/button';
import { Card, CardAction, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@apperp/ui/empty';
import { Field, FieldDescription } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@apperp/ui/sheet';
import { Textarea } from '@apperp/ui/textarea';
import { api, errorMessage, newIdempotencyKey } from '../../api';
import DynamicField from '../../master/DynamicField';
import { FieldConfig, FieldValue, emptyValue, payloadValue } from '../../master/fields';
import { AttributeDefinition, toFieldConfig } from './attributes';

type Context = { legal_entity_id: string | null; org_unit_id: string | null; user_id: string | number | null };
type Asset = { id: string; kode: string; serial_number: string | null; acquisition_value: string; currency_code: string; lifecycle_state: string };
type AssetDetail = Asset & Record<string, unknown> & {
    atribut: { tipe_atribut_id: string; nama: string; nilai: FieldValue }[];
};
type Placement = { id: string; effective_on: string; reason: string | null; receiving_org_unit_id: string | null; usage_org_unit_id: string | null; received_by_user_id: string | null; custodian_user_id: string | null; asset_location_id: string | null };

/**
 * Kolom penunjuk master pada penerimaan aset.
 *
 * Group dan jenis adalah dua sumbu yang sejajar dan sama-sama wajib: group membawa
 * perlakuan finansial, jenis membawa perlakuan teknis. Tidak ada yang menyaring yang
 * lain, jadi keduanya dirender berdampingan tanpa urutan pengisian.
 */
const CLASSIFICATION: FieldConfig[] = [
    {
        name: 'group_aset_id',
        label: 'Group aset',
        type: 'reference',
        resource: 'group-aset',
        required: true,
        help: 'Menentukan buku penyusutan mana yang dibuat untuk aset ini.',
    },
    {
        name: 'jenis_aset_id',
        label: 'Jenis aset',
        type: 'reference',
        resource: 'jenis-aset',
        required: true,
        help: 'Menentukan atribut tambahan yang harus diisi.',
    },
    { name: 'pabrikan_aset_id', label: 'Pabrikan', type: 'reference', resource: 'pabrikan-aset' },
    {
        name: 'model_aset_id',
        label: 'Model aset',
        type: 'reference',
        resource: 'model-aset',
        help: 'Katalog model per pabrikan. Kosongkan bila modelnya belum terdaftar.',
    },
    { name: 'kondisi_aset_id', label: 'Kondisi aset', type: 'reference', resource: 'kondisi-aset' },
];

const PLACEMENT: FieldConfig[] = [
    {
        name: 'asset_location_id',
        label: 'Lokasi aset',
        type: 'reference',
        resource: 'lokasi-aset',
        help: 'Lokasi yang dipetakan ke unit organisasi menentukan dimensi keuangan aset.',
    },
];

const REFERENCES = [...CLASSIFICATION, ...PLACEMENT];

/**
 * Yang boleh dikoreksi setelah aset diterima.
 *
 * Group aset tidak ada di sini: buku penyusutan sudah dibentuk dari matriksnya, jadi
 * menggantinya akan membuat buku yang berjalan tidak lagi cocok dengan groupnya. Nilai
 * perolehan dan residu ada di sini, tetapi server menolaknya begitu ada periode
 * penyusutan yang sudah berjalan.
 */
const EDITABLE = CLASSIFICATION.filter((field) => field.name !== 'group_aset_id');

export default function AssetPage({ context, canUpdate }: { context: Context; canUpdate: boolean }) {
    const [assets, setAssets] = useState<Asset[]>([]);
    const [open, setOpen] = useState(false);
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const [history, setHistory] = useState<{ asset: Asset; placements: Placement[] } | null>(null);
    const [editing, setEditing] = useState<AssetDetail | null>(null);
    const [references, setReferences] = useState<Record<string, FieldValue>>(() =>
        Object.fromEntries(REFERENCES.map((field) => [field.name, ''])),
    );
    const [parentAssetId, setParentAssetId] = useState('');
    // Atribut diwarisi dari jenis aset, jadi definisinya dibaca ulang tiap jenis berubah.
    const [attributes, setAttributes] = useState<AttributeDefinition[]>([]);
    const [attributeValues, setAttributeValues] = useState<Record<string, FieldValue>>({});
    const sheetContentRef = useRef<HTMLDivElement>(null);
    const typeId = String(references.jenis_aset_id ?? '');

    const load = () => api<{ data: Asset[] }>('/aset')
        .then((result) => setAssets(result.data))
        .catch((caught) => setError(errorMessage(caught, 'Register aset belum dapat dimuat.')));

    useEffect(() => { void load(); }, []);

    useEffect(() => {
        if (!typeId) {
            setAttributes([]);
            setAttributeValues({});
            return;
        }
        let cancelled = false;
        api<{ data: AttributeDefinition[] }>(`/jenis-aset/${typeId}/atribut-definisi`)
            .then((result) => {
                if (cancelled) return;
                setAttributes(result.data);
                // Nilai yang sudah ada dipertahankan, bukan ditimpa kosong. Saat mengoreksi
                // aset, definisinya baru selesai dimuat setelah nilainya dipasang; menimpa
                // di sini akan menghapus isian yang barusan dibaca dari server.
                setAttributeValues((current) => Object.fromEntries(
                    result.data.map((definition) => [
                        definition.tipe_atribut_id,
                        current[definition.tipe_atribut_id] ?? emptyValue(toFieldConfig(definition)),
                    ]),
                ));
            })
            .catch(() => { if (!cancelled) setAttributes([]); });
        return () => { cancelled = true; };
    }, [typeId]);

    const parentOptions = useMemo(
        () => assets.map((asset) => ({ id: asset.id, label: `${asset.kode}${asset.serial_number ? ` — ${asset.serial_number}` : ''}` })),
        [assets],
    );

    function resetForm() {
        setReferences(Object.fromEntries(REFERENCES.map((field) => [field.name, ''])));
        setParentAssetId('');
        setAttributeValues({});
    }

    /**
     * Membuka koreksi satu aset. Nilainya dibaca dari detail, bukan dari daftar: daftar
     * hanya membawa ringkasan, sedangkan yang perlu disunting termasuk atribut.
     */
    async function edit(asset: Asset) {
        try {
            const detail = (await api<{ data: AssetDetail }>(`/aset/${asset.id}`)).data;
            setReferences(Object.fromEntries(REFERENCES.map((field) => [field.name, String(detail[field.name] ?? '')])));
            setParentAssetId(String(detail.parent_asset_id ?? ''));
            setAttributeValues(Object.fromEntries(detail.atribut.map((row) => [row.tipe_atribut_id, row.nilai ?? ''])));
            setEditing(detail);
        } catch (caught) {
            setError(errorMessage(caught, 'Aset belum dapat dibuka.'));
        }
    }

    async function saveEdit(form: HTMLFormElement) {
        if (!editing) return;
        const values = new FormData(form);
        setSaving(true); setError('');
        try {
            await api(`/aset/${editing.id}`, {
                method: 'PATCH',
                body: JSON.stringify({
                    ...Object.fromEntries(EDITABLE.map((field) => [field.name, payloadValue(field, references[field.name])])),
                    parent_asset_id: parentAssetId || null,
                    serial_number: values.get('serial_number') || null,
                    model_number: values.get('model_number') || null,
                    placed_in_service_on: values.get('placed_in_service_on') || null,
                    keterangan: values.get('keterangan') || null,
                    atribut: attributes.map((definition) => ({
                        tipe_atribut_id: definition.tipe_atribut_id,
                        nilai: payloadValue(toFieldConfig(definition), attributeValues[definition.tipe_atribut_id]),
                    })),
                }),
            });
            setEditing(null); resetForm(); await load();
        } catch (caught) { setError(errorMessage(caught, 'Koreksi belum dapat disimpan.')); }
        finally { setSaving(false); }
    }

    async function showHistory(asset: Asset) {
        try { setHistory((await api<{ data: { asset: Asset; placements: Placement[] } }>(`/aset/${asset.id}/history`)).data); }
        catch (caught) { setError(errorMessage(caught, 'Riwayat aset belum dapat dimuat.')); }
    }

    async function receive(form: HTMLFormElement) {
        if (!context.legal_entity_id) {
            setError('Pilih entitas legal aktif di CoreERP sebelum menerima aset.');
            return;
        }
        const missing = REFERENCES.find((field) => field.required && !references[field.name]);
        if (missing) {
            setError(`Pilih ${missing.label.toLowerCase()} terlebih dahulu.`);
            return;
        }
        const values = new FormData(form);
        setSaving(true); setError('');
        try {
            await api('/aset', {
                method: 'POST', headers: { 'Idempotency-Key': newIdempotencyKey() },
                body: JSON.stringify({
                    legal_entity_id: context.legal_entity_id,
                    ...Object.fromEntries(REFERENCES.map((field) => [field.name, payloadValue(field, references[field.name])])),
                    parent_asset_id: parentAssetId || null,
                    acquired_on: values.get('acquired_on'),
                    // Penyusutan dihitung dari tanggal aset mulai digunakan, bukan tanggal
                    // perolehan. Dikosongkan berarti keduanya dianggap sama.
                    placed_in_service_on: values.get('placed_in_service_on') || null,
                    acquisition_value: values.get('acquisition_value'),
                    currency_code: values.get('currency_code'),
                    serial_number: values.get('serial_number') || null,
                    model_number: values.get('model_number') || null,
                    receiving_org_unit_id: values.get('receiving_org_unit_id') || context.org_unit_id,
                    usage_org_unit_id: values.get('usage_org_unit_id') || context.org_unit_id,
                    received_by_user_id: values.get('received_by_user_id') || (context.user_id === null ? null : String(context.user_id)),
                    custodian_user_id: values.get('custodian_user_id') || null,
                    residual_value: values.get('residual_value') || null,
                    keterangan: values.get('keterangan') || null,
                    atribut: attributes.map((definition) => ({
                        tipe_atribut_id: definition.tipe_atribut_id,
                        nilai: payloadValue(toFieldConfig(definition), attributeValues[definition.tipe_atribut_id]),
                    })),
                }),
            });
            setOpen(false); form.reset(); resetForm(); await load();
        } catch (caught) { setError(errorMessage(caught, 'Aset belum dapat diterima.')); }
        finally { setSaving(false); }
    }

    const referenceField = (field: FieldConfig) => (
        <DynamicField
            key={field.name}
            config={field}
            value={references[field.name]}
            onChange={(next) => setReferences((current) => ({ ...current, [field.name]: next }))}
            portalContainer={sheetContentRef}
        />
    );

    return <Card className="min-h-full rounded-none border-0 shadow-none">
        <CardHeader className="border-b px-5 py-3"><CardTitle>Register aset</CardTitle><CardAction><Button onClick={() => setOpen(true)}>Terima aset</Button></CardAction></CardHeader>
        <CardContent className="px-0">
            {error && <div className="px-5 py-3 text-sm text-destructive">{error}</div>}
            {!assets.length ? <Empty><EmptyHeader><EmptyTitle>Belum ada aset</EmptyTitle><EmptyDescription>Catat penerimaan aset pertama untuk mulai memantau lokasi, pengguna, dan penyusutannya.</EmptyDescription></EmptyHeader></Empty> :
                <div className="divide-y">{assets.map((asset) => <div key={asset.id} className="flex items-center justify-between px-5 py-3"><div><p className="font-medium">{asset.kode}</p><p className="text-sm text-muted-foreground">{asset.serial_number || 'Tanpa nomor seri'}</p></div><div className="flex items-center gap-3"><span className="text-sm">{asset.currency_code} {asset.acquisition_value}</span>{canUpdate && asset.lifecycle_state !== 'disposed' && <Button variant="outline" size="sm" onClick={() => void edit(asset)}>Ubah</Button>}<Button variant="outline" size="sm" onClick={() => void showHistory(asset)}>Riwayat</Button></div></div>)}</div>}
        </CardContent>
        <Sheet open={open} onOpenChange={(next) => { setOpen(next); if (!next) resetForm(); }}>
            <SheetContent ref={sheetContentRef} side="right" className="w-full sm:max-w-xl">
                <SheetHeader><SheetTitle>Terima aset</SheetTitle></SheetHeader>
                <form className="space-y-4 overflow-y-auto p-4" onSubmit={(event) => { event.preventDefault(); void receive(event.currentTarget); }}>
                    <p className="text-sm font-medium">Klasifikasi</p>
                    {CLASSIFICATION.map(referenceField)}
                    <Field>
                        <Select
                            label="Aset induk"
                            items={parentOptions.map((option) => option.label)}
                            value={parentOptions.find((option) => option.id === parentAssetId)?.label}
                            placeholder="Tanpa induk"
                            searchPlaceholder="Cari aset induk"
                            emptyMessage="Aset tidak ditemukan."
                            ariaLabel="Pilih aset induk"
                            portalContainer={sheetContentRef}
                            onValueChange={(item) => setParentAssetId(parentOptions.find((option) => option.label === item)?.id ?? '')}
                        />
                        <FieldDescription>Isi bila aset ini bagian dari aset lain, misalnya mesin yang terpasang pada satu gedung.</FieldDescription>
                    </Field>

                    {attributes.length > 0 && <p className="pt-2 text-sm font-medium">Atribut jenis aset</p>}
                    {attributes.map((definition) => (
                        <DynamicField
                            key={definition.tipe_atribut_id}
                            config={toFieldConfig(definition)}
                            value={attributeValues[definition.tipe_atribut_id]}
                            onChange={(next) => setAttributeValues((current) => ({ ...current, [definition.tipe_atribut_id]: next }))}
                            portalContainer={sheetContentRef}
                        />
                    ))}

                    <p className="pt-2 text-sm font-medium">Identitas dan nilai</p>
                    <Field><Input name="serial_number" label="Nomor seri" /></Field>
                    <Field><Input name="model_number" label="Nomor model" /></Field>
                    <Field><Input name="acquired_on" label="Tanggal perolehan" type="date" required /></Field>
                    <Field>
                        <Input name="placed_in_service_on" label="Tanggal mulai digunakan" type="date" />
                        <FieldDescription>Dasar perhitungan awal penyusutan. Kosong berarti sama dengan tanggal perolehan.</FieldDescription>
                    </Field>
                    <Field><Input name="acquisition_value" label="Nilai perolehan" type="number" min="0" step="0.01" required /></Field>
                    <Field><Input name="residual_value" label="Nilai residu" type="number" min="0" step="0.01" /></Field>
                    <Field><Input name="currency_code" label="Mata uang" defaultValue="IDR" maxLength={3} required /></Field>

                    <p className="pt-2 text-sm font-medium">Penempatan</p>
                    {PLACEMENT.map(referenceField)}
                    <Field><Input name="receiving_org_unit_id" label="ID unit penerima" defaultValue={context.org_unit_id ?? ''} /></Field>
                    <Field><Input name="usage_org_unit_id" label="ID unit pengguna" defaultValue={context.org_unit_id ?? ''} /></Field>
                    <Field><Input name="received_by_user_id" label="ID penerima" defaultValue={context.user_id === null ? '' : String(context.user_id)} /></Field>
                    <Field><Input name="custodian_user_id" label="ID PIC aset" /></Field>

                    <Field>
                        <p className="pt-2 text-sm font-medium">Konfigurasi penyusutan</p>
                        <FieldDescription>Buku penyusutan dan profil utama diambil dari matriks group x book. Aset dapat diterima lebih dahulu, tetapi belum dapat ditempatkan sampai matriks memiliki konfigurasi yang lengkap dan berlaku.</FieldDescription>
                    </Field>

                    <Field><Textarea name="keterangan" rows={3} maxLength={2000} placeholder="Keterangan" /></Field>

                    <SheetFooter><Button type="button" variant="outline" onClick={() => setOpen(false)}>Batal</Button><Button type="submit" disabled={saving}>{saving ? 'Menyimpan…' : 'Simpan penerimaan'}</Button></SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
        <Sheet open={editing !== null} onOpenChange={(next) => { if (!next) { setEditing(null); resetForm(); } }}>
            <SheetContent ref={sheetContentRef} side="right" className="w-full sm:max-w-xl">
                <SheetHeader><SheetTitle>Koreksi aset {editing?.kode}</SheetTitle></SheetHeader>
                <form className="space-y-4 overflow-y-auto p-4" onSubmit={(event) => { event.preventDefault(); void saveEdit(event.currentTarget); }}>
                    <p className="text-sm text-muted-foreground">
                        Group aset tidak dapat diganti di sini karena buku penyusutannya sudah terbentuk dari matriks group.
                    </p>
                    {EDITABLE.map(referenceField)}
                    {attributes.length > 0 && <p className="pt-2 text-sm font-medium">Atribut jenis aset</p>}
                    {attributes.map((definition) => (
                        <DynamicField
                            key={definition.tipe_atribut_id}
                            config={toFieldConfig(definition)}
                            value={attributeValues[definition.tipe_atribut_id]}
                            onChange={(next) => setAttributeValues((current) => ({ ...current, [definition.tipe_atribut_id]: next }))}
                            portalContainer={sheetContentRef}
                        />
                    ))}
                    <Field><Input name="serial_number" label="Nomor seri" defaultValue={String(editing?.serial_number ?? '')} /></Field>
                    <Field><Input name="model_number" label="Nomor model" defaultValue={String(editing?.model_number ?? '')} /></Field>
                    <Field>
                        <Input name="placed_in_service_on" label="Tanggal mulai digunakan" type="date" defaultValue={String(editing?.placed_in_service_on ?? '').slice(0, 10)} />
                        <FieldDescription>Menggeser awal penyusutan selama buku aset belum punya periode berjalan.</FieldDescription>
                    </Field>
                    <Field><Textarea name="keterangan" rows={3} maxLength={2000} placeholder="Keterangan" defaultValue={String(editing?.keterangan ?? '')} /></Field>
                    <SheetFooter>
                        <Button type="button" variant="outline" onClick={() => { setEditing(null); resetForm(); }}>Batal</Button>
                        <Button type="submit" disabled={saving}>{saving ? 'Menyimpan…' : 'Simpan koreksi'}</Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
        <Sheet open={history !== null} onOpenChange={(open) => { if (!open) setHistory(null); }}><SheetContent side="right"><SheetHeader><SheetTitle>Riwayat aset {history?.asset.kode}</SheetTitle></SheetHeader><div className="space-y-3 p-4">{history?.placements.map((placement) => <div className="rounded border p-3" key={placement.id}><p className="font-medium">{placement.effective_on}</p><p className="text-sm text-muted-foreground">{placement.reason || 'Penempatan aset'}</p><p className="text-sm">Unit pengguna: {placement.usage_org_unit_id || 'Belum dipilih'}</p><p className="text-sm">PIC: {placement.custodian_user_id || 'Belum dipilih'}</p><p className="text-sm">Lokasi: {placement.asset_location_id || 'Belum dipilih'}</p></div>)}</div></SheetContent></Sheet>
    </Card>;
}
