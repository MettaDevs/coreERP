import { useEffect, useState } from 'react';
import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
import { Empty, EmptyDescription } from '@apperp/ui/empty';
import { Field, FieldError, FieldLabel } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { Switch } from '@apperp/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@apperp/ui/table';
import { Textarea } from '@apperp/ui/textarea';
import { ApiError, api, errorMessage } from '../../api';

type Line = {
    id?: string; line_number: number; type: 'header' | 'text' | 'measurement' | 'variable' | 'template'; nama: string;
    instruksi: string | null; wajib: boolean; unit_id: string | null; unit: string | null; min_value: number | null; max_value: number | null;
    variable_id: string | null; nested_template_id: string | null;
};
type Option = { id: string; kode: string; nama: string };
const types = ['Header', 'Teks', 'Pengukuran', 'Variabel', 'Template'];
const typeCode = (value: string): Line['type'] => ({ Header: 'header', Teks: 'text', Pengukuran: 'measurement', Variabel: 'variable', Template: 'template' }[value] as Line['type']);
const typeLabel = (value: Line['type']): string => ({ header: 'Header', text: 'Teks', measurement: 'Pengukuran', variable: 'Variabel', template: 'Template' }[value]);
const label = (option: Option): string => `${option.kode} · ${option.nama}`;
const newLine = (line_number: number): Line => ({ line_number, type: 'text', nama: '', instruksi: null, wajib: false, unit_id: null, unit: null, min_value: null, max_value: null, variable_id: null, nested_template_id: null });
type LineErrors = Record<number, Record<string, string>>;

function lineErrorsFrom(caught: unknown): LineErrors {
    if (!(caught instanceof ApiError)) return {};

    return Object.entries(caught.validationErrors).reduce<LineErrors>((result, [key, messages]) => {
        const match = /^lines\.(\d+)\.([^.]+)$/.exec(key);
        if (!match) return result;

        const index = Number(match[1]);
        result[index] = { ...result[index], [match[2]]: messages[0] ?? 'Periksa nilai ini.' };
        return result;
    }, {});
}

function validationSummary(caught: unknown): string {
    if (!(caught instanceof ApiError)) return '';
    const first = Object.entries(caught.validationErrors)[0];
    if (!first) return '';

    const match = /^lines\.(\d+)\.([^.]+)$/.exec(first[0]);
    const fieldLabels: Record<string, string> = { nama: 'Nama', line_number: 'Nomor baris', type: 'Jenis', variable_id: 'Variabel checklist', nested_template_id: 'Template checklist' };
    if (!match) return first[1][0] ?? '';

    return `Baris ${Number(match[1]) + 1}, ${fieldLabels[match[2]] ?? match[2]}: ${first[1][0] ?? 'Periksa nilai ini.'}`;
}

export default function MaintenanceChecklistTemplateLines({ templateId, canEdit }: { templateId: string; canEdit: boolean }) {
    const [lines, setLines] = useState<Line[]>([]); const [selectedIndex, setSelectedIndex] = useState<number | null>(null); const [checkedIndexes, setCheckedIndexes] = useState<number[]>([]);
    const [saving, setSaving] = useState(false); const [error, setError] = useState(''); const [saved, setSaved] = useState(false); const [lineErrors, setLineErrors] = useState<LineErrors>({});
    const [variables, setVariables] = useState<Option[]>([]); const [templates, setTemplates] = useState<Option[]>([]); const [units, setUnits] = useState<Option[]>([]);

    useEffect(() => {
        Promise.all([
            api<{ data: Line[] }>(`/maintenance-checklist-templates/${templateId}/lines`), api<{ data: Option[] }>('/maintenance-checklist-variables?per_page=100&aktif=true'),
            api<{ data: Option[] }>('/maintenance-checklist-templates?per_page=100&aktif=true'), api<{ data: Option[] }>('/reference-data/units-of-measure'),
        ]).then(([lineResult, variableResult, templateResult, unitResult]) => {
            setLines(lineResult.data); setSelectedIndex(null); setCheckedIndexes([]); setVariables(variableResult.data);
            setTemplates(templateResult.data.filter((item) => item.id !== templateId)); setUnits(unitResult.data);
        }).catch((caught) => setError(errorMessage(caught, 'Baris checklist belum dapat dimuat.')));
    }, [templateId]);

    const update = (index: number, changes: Partial<Line>) => {
        setSaved(false); setError('');
        setLineErrors((current) => {
            const row = current[index]; if (!row) return current;
            const remaining = { ...row }; Object.keys(changes).forEach((field) => delete remaining[field]);
            const next = { ...current }; if (Object.keys(remaining).length === 0) delete next[index]; else next[index] = remaining;
            return next;
        });
        setLines((current) => current.map((item, currentIndex) => currentIndex === index ? { ...item, ...changes } : item));
    };
    const changeType = (index: number, type: Line['type']) => update(index, {
        type, instruksi: type === 'template' || type === 'header' ? null : lines[index].instruksi, wajib: type === 'header' || type === 'template' ? false : lines[index].wajib,
        unit_id: type === 'measurement' ? lines[index].unit_id : null, unit: type === 'measurement' ? lines[index].unit : null, min_value: type === 'measurement' ? lines[index].min_value : null, max_value: type === 'measurement' ? lines[index].max_value : null,
        variable_id: type === 'variable' ? lines[index].variable_id : null, nested_template_id: type === 'template' ? lines[index].nested_template_id : null,
    });
    const optionFor = (items: Option[], id: string | null) => items.find((item) => item.id === id);
    const selected = selectedIndex === null ? null : lines[selectedIndex] ?? null;
    const lineId = (line: Line) => line.type === 'variable' ? optionFor(variables, line.variable_id)?.kode ?? '—' : line.type === 'template' ? optionFor(templates, line.nested_template_id)?.kode ?? '—' : line.type === 'measurement' ? line.unit ?? '—' : '—';
    async function save() {
        setSaving(true); setError(''); setLineErrors({});
        try {
            const result = await api<{ data: Line[] }>(`/maintenance-checklist-templates/${templateId}/lines`, { method: 'PUT', body: JSON.stringify({ lines }) });
            setLines(result.data); setSaved(true);
        } catch (caught) {
            const nextErrors = lineErrorsFrom(caught); setLineErrors(nextErrors);
            const firstErrorIndex = Object.keys(nextErrors).map(Number).sort((a, b) => a - b)[0];
            if (firstErrorIndex !== undefined) { setCheckedIndexes([firstErrorIndex]); setSelectedIndex(firstErrorIndex); }
            setError(validationSummary(caught) || (firstErrorIndex !== undefined ? 'Periksa kolom yang ditandai merah.' : errorMessage(caught, 'Baris checklist belum dapat disimpan.')));
        } finally { setSaving(false); }
    }
    function check(index: number, checked: boolean) {
        setCheckedIndexes((current) => {
            const next = checked ? [...new Set([...current, index])] : current.filter((item) => item !== index);
            setSelectedIndex((active) => checked ? index : active === index ? next.at(-1) ?? null : active);
            return next;
        });
    }
    function checkAll(checked: boolean) { const indexes = lines.map((_, index) => index); setCheckedIndexes(checked ? indexes : []); setSelectedIndex(checked ? indexes.at(-1) ?? null : null); }
    function select(index: number) { setCheckedIndexes([index]); setSelectedIndex(index); }
    if (error && lines.length === 0) return <p className="text-sm text-destructive">{error}</p>;

    const allChecked = lines.length > 0 && checkedIndexes.length === lines.length;
    const someChecked = checkedIndexes.length > 0 && !allChecked;

    return <div className="space-y-3"><div className="flex items-center justify-between"><h3 className="font-semibold">Baris checklist maintenance</h3>{canEdit && <div className="flex gap-2">
        <Button type="button" variant="outline" onClick={() => { setLines((current) => [...current, newLine(current.length + 1)]); check(lines.length, true); }}>Tambah</Button>
        <Button type="button" variant="outline" disabled={checkedIndexes.length === 0} onClick={() => { setLines((current) => current.filter((_, index) => !checkedIndexes.includes(index))); setCheckedIndexes([]); setSelectedIndex(null); }}>Hapus</Button>
        <Button type="button" disabled={saving} onClick={() => void save()}>{saving ? 'Menyimpan…' : 'Simpan'}</Button>
    </div>}</div>
        {lines.length === 0 ? <Empty><EmptyDescription>Tambahkan baris untuk menyusun prosedur pemeriksaan.</EmptyDescription></Empty> : <div className="overflow-x-auto rounded-md border"><Table><TableHeader><TableRow><TableHead className="w-12 cursor-pointer" onClick={() => checkAll(!allChecked)}><Checkbox aria-label="Pilih semua baris" checked={allChecked ? true : someChecked ? 'indeterminate' : false} onClick={(event) => event.stopPropagation()} onCheckedChange={(value) => checkAll(value === true)} /></TableHead><TableHead>Nomor baris</TableHead><TableHead>Jenis</TableHead><TableHead>ID</TableHead><TableHead>Nama</TableHead></TableRow></TableHeader><TableBody>{lines.map((item, index) => {
            const checked = checkedIndexes.includes(index); const active = selectedIndex === index; const rowError = lineErrors[index] ?? {}; const nameError = rowError.nama; const referenceError = rowError.variable_id ?? rowError.nested_template_id;
            return <TableRow key={item.id ?? `${item.line_number}-${index}`} className={`cursor-pointer${active ? ' bg-primary/20' : checked ? ' bg-primary/10' : ''}${Object.keys(rowError).length > 0 ? ' bg-destructive/5' : ''}`} aria-selected={active} aria-invalid={Object.keys(rowError).length > 0 || undefined} tabIndex={0} onClick={() => select(index)} onKeyDown={(event) => {
                // Spasi pada input, textarea, select, atau checkbox di dalam baris
                // adalah input pengguna, bukan perintah untuk memilih baris. Handler
                // baris hanya bekerja saat fokus keyboard benar-benar berada di baris.
                if (event.target !== event.currentTarget) return;
                if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); select(index); }
            }}>
            <TableCell className="cursor-pointer" onClick={(event) => { event.stopPropagation(); check(index, !checked); }}><Checkbox aria-label={`Pilih baris ${index + 1}`} checked={checked} onClick={(event) => event.stopPropagation()} onCheckedChange={(value) => check(index, value === true)} /></TableCell>
            <TableCell>{canEdit ? <Input aria-label={`Nomor baris ${index + 1}`} type="number" min="1" value={item.line_number} onChange={(event) => update(index, { line_number: Number(event.target.value) })} /> : item.line_number}</TableCell>
            <TableCell>{canEdit ? <Select items={types} value={typeLabel(item.type)} ariaLabel={`Jenis baris ${index + 1}`} onValueChange={(value) => { if (value) changeType(index, typeCode(value)); }} /> : typeLabel(item.type)}</TableCell><TableCell className={referenceError ? 'text-destructive' : undefined}>{lineId(item)}{referenceError && <FieldError>{referenceError}</FieldError>}</TableCell>
            <TableCell>{canEdit ? <div className="space-y-1"><Input aria-label={`Nama baris ${index + 1}`} aria-invalid={nameError ? true : undefined} aria-describedby={nameError ? `line-${index}-nama-error` : undefined} value={item.nama} onChange={(event) => update(index, { nama: event.target.value })} />{nameError && <FieldError id={`line-${index}-nama-error`}>{nameError}</FieldError>}</div> : item.nama}</TableCell>
        </TableRow>; })}</TableBody></Table></div>}
        {selected && selectedIndex !== null && <LineDetails line={selected} canEdit={canEdit} variables={variables} templates={templates} units={units} onChange={(changes) => update(selectedIndex, changes)} />}
        {saved && <p className="text-sm text-muted-foreground">Baris checklist tersimpan.</p>}{error && <p className="text-sm text-destructive">{error}</p>}
    </div>;
}

function LineDetails({ line, canEdit, variables, templates, units, onChange }: { line: Line; canEdit: boolean; variables: Option[]; templates: Option[]; units: Option[]; onChange: (changes: Partial<Line>) => void }) {
    const pick = (items: Option[], id: string | null) => items.find((item) => item.id === id);
    const picker = (items: Option[], id: string | null, fieldLabel: string, placeholder: string, change: (id: string | null) => void, required = false) => canEdit ? <Select label={fieldLabel} required={required} items={items.map(label)} value={pick(items, id) ? label(pick(items, id)!) : null} placeholder={placeholder} ariaLabel={fieldLabel} onValueChange={(value) => change(items.find((item) => label(item) === value)?.id ?? null)} /> : <p className="text-sm">{pick(items, id)?.nama ?? 'Belum dipilih'}</p>;
    return <section className="space-y-4 rounded-md border p-4"><h4 className="font-semibold">Rincian baris</h4>
        {line.type === 'header' && <p className="text-sm text-muted-foreground">Header hanya menjadi judul kelompok pemeriksaan dan tidak perlu diisi teknisi.</p>}
        {line.type === 'template' && <div className="max-w-xl">{picker(templates, line.nested_template_id, 'Template checklist', 'Pilih template checklist', (nested_template_id) => onChange({ nested_template_id }))}</div>}
        {line.type === 'measurement' && <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4"><DetailSwitch line={line} canEdit={canEdit} onChange={onChange} /><div>{picker(units, line.unit_id, 'Satuan', 'Pilih satuan bila diketahui', (unit_id) => onChange({ unit_id, unit: units.find((item) => item.id === unit_id)?.kode ?? null }))}</div><DetailNumber label="Nilai minimum" value={line.min_value} canEdit={canEdit} onChange={(min_value) => onChange({ min_value })} /><DetailNumber label="Nilai maksimum" value={line.max_value} canEdit={canEdit} onChange={(max_value) => onChange({ max_value })} /><DetailInstruction line={line} canEdit={canEdit} onChange={onChange} /></div>}
        {line.type === 'variable' && <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3"><DetailSwitch line={line} canEdit={canEdit} onChange={onChange} /><div>{picker(variables, line.variable_id, 'Variabel checklist', 'Pilih variabel checklist', (variable_id) => onChange({ variable_id }), true)}</div><DetailInstruction line={line} canEdit={canEdit} onChange={onChange} /></div>}
        {line.type === 'text' && <div className="grid gap-4 md:grid-cols-2"><DetailSwitch line={line} canEdit={canEdit} onChange={onChange} /><DetailInstruction line={line} canEdit={canEdit} onChange={onChange} /></div>}
    </section>;
}

function DetailSwitch({ line, canEdit, onChange }: { line: Line; canEdit: boolean; onChange: (changes: Partial<Line>) => void }) {
    const id = `line-wajib-${line.id ?? 'baru'}`;
    if (!canEdit) return <div className="flex items-center gap-2"><span className="text-sm font-medium">Wajib diisi</span><span className="text-sm">{line.wajib ? 'Ya' : 'Tidak'}</span></div>;
    return <Field orientation="horizontal"><Switch id={id} checked={line.wajib} onCheckedChange={(wajib) => onChange({ wajib })} /><FieldLabel htmlFor={id}>Wajib diisi</FieldLabel></Field>;
}
function DetailNumber({ label, value, canEdit, onChange }: { label: string; value: number | null; canEdit: boolean; onChange: (value: number | null) => void }) { return canEdit ? <Input label={label} type="number" step="any" value={value ?? ''} onChange={(event) => onChange(event.target.value === '' ? null : Number(event.target.value))} /> : <p className="text-sm">{value ?? 'Tidak dibatasi'}</p>; }
function DetailInstruction({ line, canEdit, onChange }: { line: Line; canEdit: boolean; onChange: (changes: Partial<Line>) => void }) { return canEdit ? <Textarea label="Instruksi" rows={3} placeholder="Cara melakukan pemeriksaan ini" value={line.instruksi ?? ''} onChange={(event) => onChange({ instruksi: event.target.value || null })} /> : <p className="text-sm">{line.instruksi ?? '—'}</p>; }
