import { useEffect, useState } from 'react';
import { Button } from '@apperp/ui/button';
import { Empty, EmptyDescription } from '@apperp/ui/empty';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { Switch } from '@apperp/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@apperp/ui/table';
import { api, errorMessage } from '../../api';

/**
 * `wajib` dan `instruksi` wajib ikut dimuat dan dikirim ulang. Penyimpanan mengganti
 * seluruh baris, jadi field yang tidak dibawa layar ini akan terhapus dari template.
 */
type Line = {
    id?: string;
    line_number: number;
    type: 'header' | 'text' | 'measurement' | 'variable' | 'template';
    nama: string;
    instruksi: string | null;
    wajib: boolean;
    unit: string | null;
    variable_id: string | null;
    nested_template_id: string | null;
};
type Option = { id: string; kode: string; nama: string };
const types = ['Header', 'Teks', 'Pengukuran', 'Variabel', 'Template'];
const typeCode = (value: string): Line['type'] => ({ Header: 'header', Teks: 'text', Pengukuran: 'measurement', Variabel: 'variable', Template: 'template' }[value] as Line['type']);
const typeLabel = (value: Line['type']): string => ({ header: 'Header', text: 'Teks', measurement: 'Pengukuran', variable: 'Variabel', template: 'Template' }[value]);

export default function MaintenanceChecklistTemplateLines({ templateId, canEdit }: { templateId: string; canEdit: boolean }) {
    const [lines, setLines] = useState<Line[]>([]);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);
    const [variables, setVariables] = useState<Option[]>([]);
    const [templates, setTemplates] = useState<Option[]>([]);

    useEffect(() => {
        Promise.all([
            api<{ data: Line[] }>(`/maintenance-checklist-templates/${templateId}/lines`),
            api<{ data: Option[] }>('/maintenance-checklist-variables?per_page=100&aktif=true'),
            api<{ data: Option[] }>('/maintenance-checklist-templates?per_page=100&aktif=true'),
        ])
            .then(([lineResult, variableResult, templateResult]) => {
                setLines(lineResult.data);
                setVariables(variableResult.data);
                setTemplates(templateResult.data.filter((item) => item.id !== templateId));
            })
            .catch((caught) => setError(errorMessage(caught, 'Baris checklist belum dapat dimuat.')));
    }, [templateId]);

    function update(index: number, changes: Partial<Line>) {
        setSaved(false);
        setLines((current) => current.map((item, currentIndex) => currentIndex === index ? { ...item, ...changes } : item));
    }

    async function save() {
        setSaving(true); setError('');
        try {
            await api(`/maintenance-checklist-templates/${templateId}/lines`, { method: 'PUT', body: JSON.stringify({ lines }) });
            setSaved(true);
        } catch (caught) {
            setError(errorMessage(caught, 'Baris checklist belum dapat disimpan.'));
        } finally { setSaving(false); }
    }

    if (error && lines.length === 0) return <p className="text-sm text-destructive">{error}</p>;

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between">
                <h3 className="font-semibold">Baris checklist maintenance</h3>
                {canEdit && <div className="flex gap-2">
                    <Button type="button" variant="outline" onClick={() => setLines((current) => [...current, { line_number: current.length + 1, type: 'text', nama: '', instruksi: null, wajib: false, unit: null, variable_id: null, nested_template_id: null }])}>Tambah</Button>
                    <Button type="button" disabled={saving} onClick={() => void save()}>{saving ? 'Menyimpan…' : 'Simpan'}</Button>
                </div>}
            </div>
            {lines.length === 0 ? <Empty><EmptyDescription>Belum ada baris checklist.</EmptyDescription></Empty> : (
                <div className="overflow-x-auto rounded-md border"><Table><TableHeader><TableRow><TableHead>Nomor</TableHead><TableHead>Jenis</TableHead><TableHead>Nama</TableHead><TableHead>Instruksi</TableHead><TableHead>Variabel/template</TableHead><TableHead>Wajib</TableHead><TableHead>Satuan</TableHead></TableRow></TableHeader><TableBody>
                    {lines.map((item, index) => <TableRow key={item.id ?? `${item.line_number}-${index}`}>
                        <TableCell>{canEdit ? <Input aria-label={`Nomor baris ${index + 1}`} type="number" min="1" value={item.line_number} onChange={(event) => update(index, { line_number: Number(event.target.value) })} /> : item.line_number}</TableCell>
                        <TableCell>{canEdit ? <Select items={types} value={typeLabel(item.type)} ariaLabel={`Jenis baris ${index + 1}`} onValueChange={(value) => { if (value) { const nextType = typeCode(value); update(index, { type: nextType, variable_id: null, nested_template_id: null, wajib: nextType === 'header' ? false : item.wajib }); } }} /> : typeLabel(item.type)}</TableCell>
                        <TableCell>{canEdit ? <Input aria-label={`Nama baris ${index + 1}`} value={item.nama} onChange={(event) => update(index, { nama: event.target.value })} /> : item.nama}</TableCell>
                        <TableCell>{canEdit ? <Input aria-label={`Instruksi baris ${index + 1}`} placeholder="Cara mengerjakan pemeriksaan ini" value={item.instruksi ?? ''} onChange={(event) => update(index, { instruksi: event.target.value || null })} /> : item.instruksi ?? '—'}</TableCell>
                        <TableCell>{item.type === 'variable' && canEdit ? <Select items={variables.map((option) => `${option.kode} · ${option.nama}`)} value={variables.find((option) => option.id === item.variable_id) ? `${variables.find((option) => option.id === item.variable_id)?.kode} · ${variables.find((option) => option.id === item.variable_id)?.nama}` : null} placeholder="Pilih variabel" ariaLabel={`Variabel baris ${index + 1}`} onValueChange={(value) => update(index, { variable_id: variables.find((option) => `${option.kode} · ${option.nama}` === value)?.id ?? null })} /> : item.type === 'template' && canEdit ? <Select items={templates.map((option) => `${option.kode} · ${option.nama}`)} value={templates.find((option) => option.id === item.nested_template_id) ? `${templates.find((option) => option.id === item.nested_template_id)?.kode} · ${templates.find((option) => option.id === item.nested_template_id)?.nama}` : null} placeholder="Pilih template" ariaLabel={`Template baris ${index + 1}`} onValueChange={(value) => update(index, { nested_template_id: templates.find((option) => `${option.kode} · ${option.nama}` === value)?.id ?? null })} /> : item.type === 'variable' ? variables.find((option) => option.id === item.variable_id)?.nama ?? 'Belum dipilih' : item.type === 'template' ? templates.find((option) => option.id === item.nested_template_id)?.nama ?? 'Belum dipilih' : '—'}</TableCell>
                        {/* Baris judul hanya memberi struktur dan tidak pernah diisi teknisi, jadi ia tidak dapat ditandai wajib. */}
                        <TableCell>{item.type === 'header' ? '—' : canEdit ? <Switch aria-label={`Wajib diisi baris ${index + 1}`} checked={item.wajib} onCheckedChange={(checked) => update(index, { wajib: checked })} /> : item.wajib ? 'Ya' : 'Tidak'}</TableCell>
                        <TableCell>{canEdit ? <Input aria-label={`Satuan baris ${index + 1}`} value={item.unit ?? ''} onChange={(event) => update(index, { unit: event.target.value || null })} /> : item.unit ?? '—'}</TableCell>
                    </TableRow>)}
                </TableBody></Table></div>
            )}
            {saved && <p className="text-sm text-muted-foreground">Baris checklist tersimpan.</p>}
            {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
    );
}
