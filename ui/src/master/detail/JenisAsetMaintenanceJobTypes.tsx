import { useEffect, useState } from 'react';
import { Button } from '@apperp/ui/button';
import { Empty, EmptyDescription } from '@apperp/ui/empty';
import { TransferList, type TransferListItem } from '@apperp/ui/transfer-list';
import { api, errorMessage } from '../../api';

type Choice = { id: string; kode: string; nama: string };
const itemOf = (item: Choice): TransferListItem => ({ id: item.id, label: item.nama, description: item.kode });

export default function JenisAsetMaintenanceJobTypes({ jenisAsetId, canEdit }: { jenisAsetId: string; canEdit: boolean }) {
    const [remaining, setRemaining] = useState<TransferListItem[]>([]);
    const [selected, setSelected] = useState<TransferListItem[]>([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);

    async function load() {
        setLoading(true); setError('');
        try {
            const result = await api<{ data: { remaining: Choice[]; selected: Choice[] } }>(`/jenis-aset/${jenisAsetId}/maintenance-job-types`);
            setRemaining(result.data.remaining.map(itemOf)); setSelected(result.data.selected.map(itemOf));
        } catch (caught) { setError(errorMessage(caught, 'Daftar jenis pekerjaan maintenance belum dapat dimuat.')); }
        finally { setLoading(false); }
    }
    useEffect(() => { void load(); }, [jenisAsetId]);

    async function save() {
        setSaving(true); setError('');
        try { await api(`/jenis-aset/${jenisAsetId}/maintenance-job-types`, { method: 'PUT', body: JSON.stringify({ jenis_aset_ids: selected.map((item) => item.id) }) }); setSaved(true); }
        catch (caught) { setError(errorMessage(caught, 'Relasi maintenance belum dapat disimpan.')); }
        finally { setSaving(false); }
    }

    if (loading) return <p className="text-sm text-muted-foreground">Memuat jenis pekerjaan maintenance…</p>;
    if (error) return <p className="text-sm text-destructive">{error}</p>;
    if (!canEdit && selected.length === 0) return <Empty><EmptyDescription>Belum ada jenis pekerjaan maintenance yang dikaitkan.</EmptyDescription></Empty>;

    return <div className="space-y-4"><p className="text-sm text-muted-foreground">Pilih jenis pekerjaan yang dapat dipakai oleh jenis aset ini.</p><TransferList remaining={remaining} selected={selected} onChange={(next) => { setRemaining(next.remaining); setSelected(next.selected); setSaved(false); }} remainingTitle="Jenis pekerjaan tersisa" selectedTitle="Jenis pekerjaan terpilih" disabled={!canEdit || saving} remainingEmptyLabel="Semua pekerjaan sudah terpilih." selectedEmptyLabel="Belum ada pekerjaan yang dipilih." />{canEdit && <Button type="button" disabled={saving} onClick={() => void save()}>{saving ? 'Menyimpan…' : 'Simpan jenis pekerjaan'}</Button>}{saved && <p className="text-sm text-muted-foreground">Relasi jenis pekerjaan tersimpan.</p>}</div>;
}
