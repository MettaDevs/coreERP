import { useEffect, useState } from 'react';
import { Button } from '@apperp/ui/button';
import { Empty, EmptyDescription } from '@apperp/ui/empty';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@apperp/ui/table';
import { api, errorMessage } from '../../api';

type Value = { id?: string; line_number: number; value: string; result_code: 'pass' | 'fail' };

export default function MaintenanceChecklistVariableValues({ variableId, canEdit }: { variableId: string; canEdit: boolean }) {
    const [values, setValues] = useState<Value[]>([]); const [saving, setSaving] = useState(false); const [error, setError] = useState(''); const [saved, setSaved] = useState(false);
    useEffect(() => { api<{ data: Value[] }>(`/maintenance-checklist-variables/${variableId}/values`).then((result) => setValues(result.data)).catch((caught) => setError(errorMessage(caught, 'Nilai checklist belum dapat dimuat.'))); }, [variableId]);
    function update(index: number, changes: Partial<Value>) { setSaved(false); setValues((current) => current.map((item, currentIndex) => currentIndex === index ? { ...item, ...changes } : item)); }
    async function save() { setSaving(true); setError(''); try { await api(`/maintenance-checklist-variables/${variableId}/values`, { method: 'PUT', body: JSON.stringify({ values }) }); setSaved(true); } catch (caught) { setError(errorMessage(caught, 'Nilai checklist belum dapat disimpan.')); } finally { setSaving(false); } }
    if (error && values.length === 0) return <p className="text-sm text-destructive">{error}</p>;
    return <div className="space-y-3"><div className="flex items-center justify-between"><h3 className="font-semibold">Nilai checklist</h3>{canEdit && <div className="flex gap-2"><Button type="button" variant="outline" onClick={() => setValues((current) => [...current, { line_number: current.length + 1, value: '', result_code: 'pass' }])}>Tambah</Button><Button type="button" disabled={saving} onClick={() => void save()}>{saving ? 'Menyimpan…' : 'Simpan'}</Button></div>}</div>{values.length === 0 ? <Empty><EmptyDescription>Belum ada pilihan nilai checklist.</EmptyDescription></Empty> : <div className="overflow-x-auto rounded-md border"><Table><TableHeader><TableRow><TableHead>Nomor baris</TableHead><TableHead>Nilai</TableHead><TableHead>Hasil</TableHead></TableRow></TableHeader><TableBody>{values.map((item, index) => <TableRow key={item.id ?? `${item.line_number}-${index}`}><TableCell>{canEdit ? <Input aria-label={`Nomor baris ${index + 1}`} type="number" min="1" value={item.line_number} onChange={(event) => update(index, { line_number: Number(event.target.value) })} /> : item.line_number}</TableCell><TableCell>{canEdit ? <Input aria-label={`Nilai baris ${index + 1}`} value={item.value} onChange={(event) => update(index, { value: event.target.value })} /> : item.value}</TableCell><TableCell>{canEdit ? <Select items={['Lulus', 'Gagal']} value={item.result_code === 'pass' ? 'Lulus' : 'Gagal'} ariaLabel={`Hasil baris ${index + 1}`} onValueChange={(value) => update(index, { result_code: value === 'Gagal' ? 'fail' : 'pass' })} /> : item.result_code === 'pass' ? 'Lulus' : 'Gagal'}</TableCell></TableRow>)}</TableBody></Table></div>}{saved && <p className="text-sm text-muted-foreground">Nilai checklist tersimpan.</p>}{error && <p className="text-sm text-destructive">{error}</p>}</div>;
}
