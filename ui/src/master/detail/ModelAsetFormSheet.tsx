import { FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@apperp/ui/button';
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@apperp/ui/sheet';
import { Switch } from '@apperp/ui/switch';
import { Textarea } from '@apperp/ui/textarea';
import { api, errorMessage, newIdempotencyKey } from '../../api';
import { MasterRecord, ParentSummary } from '../masters';
import { PabrikanModelRecord } from './pabrikanAsetDetail';

function optionLabel(option: ParentSummary): string {
    return `${option.kode} — ${option.nama}`;
}

export default function ModelAsetFormSheet({
    manufacturer,
    value,
    canReadJenis,
    onClose,
    onSaved,
}: {
    manufacturer: MasterRecord;
    value: PabrikanModelRecord | null;
    canReadJenis: boolean;
    onClose: () => void;
    onSaved: () => void | Promise<void>;
}) {
    const [nama, setNama] = useState(value?.nama ?? '');
    const [keterangan, setKeterangan] = useState(value?.keterangan ?? '');
    const [aktif, setAktif] = useState(value?.aktif ?? true);
    const [jenisAsetId, setJenisAsetId] = useState(value?.jenis_aset_id ?? '');
    const [jenisOptions, setJenisOptions] = useState<ParentSummary[]>([]);
    const [jenisError, setJenisError] = useState('');
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const sheetContentRef = useRef<HTMLDivElement>(null);
    const creationKey = useRef(newIdempotencyKey());

    useEffect(() => {
        setNama(value?.nama ?? '');
        setKeterangan(value?.keterangan ?? '');
        setAktif(value?.aktif ?? true);
        setJenisAsetId(value?.jenis_aset_id ?? '');
        setError('');
        setJenisError('');
    }, [value?.id]);

    useEffect(() => {
        if (!canReadJenis) {
            setJenisOptions([]);
            return;
        }

        let cancelled = false;
        api<{ data: MasterRecord[] }>('/jenis-aset?per_page=100&aktif=true')
            .then((result) => {
                if (cancelled) return;
                const options = result.data.map(({ id, kode, nama }) => ({ id, kode, nama }));
                if (
                    value?.jenis_aset &&
                    !options.some((option) => option.id === value.jenis_aset_id)
                ) {
                    options.unshift(value.jenis_aset);
                }
                setJenisOptions(options);
                setJenisError('');
            })
            .catch((caught) => {
                if (!cancelled)
                    setJenisError(errorMessage(caught, 'Pilihan jenis aset belum dapat dimuat.'));
            });

        return () => {
            cancelled = true;
        };
    }, [canReadJenis, value?.id, value?.jenis_aset_id]);

    const jenisLabel = useMemo(() => {
        const selected = jenisOptions.find((option) => option.id === jenisAsetId);

        return selected ? optionLabel(selected) : 'Tidak ditentukan';
    }, [jenisAsetId, jenisOptions]);

    async function submit(event: FormEvent) {
        event.preventDefault();
        setSaving(true);
        setError('');

        try {
            await api(`/${'model-aset'}${value ? `/${value.id}` : ''}`, {
                method: value ? 'PATCH' : 'POST',
                headers: value ? undefined : { 'Idempotency-Key': creationKey.current },
                body: JSON.stringify({
                    nama,
                    keterangan,
                    aktif,
                    pabrikan_aset_id: manufacturer.id,
                    jenis_aset_id: jenisAsetId || null,
                }),
            });
            await onSaved();
        } catch (caught) {
            setError(errorMessage(caught, 'Model belum dapat disimpan.'));
        } finally {
            setSaving(false);
        }
    }

    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent
                ref={sheetContentRef}
                side="right"
                className="w-full gap-0 p-0 sm:max-w-xl"
            >
                <SheetHeader className="border-b px-6 py-5 pr-12">
                    <SheetTitle>{value ? 'Ubah model' : 'Tambah model'}</SheetTitle>
                </SheetHeader>
                <form className="flex min-h-0 flex-1 flex-col" onSubmit={submit}>
                    <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                        <FieldGroup>
                            <Field>
                                <Input
                                    label="Pabrikan"
                                    value={`${manufacturer.kode} — ${manufacturer.nama}`}
                                    disabled
                                />
                            </Field>
                            <Field>
                                <Input
                                    label="Model"
                                    autoFocus
                                    required
                                    maxLength={150}
                                    value={nama}
                                    onChange={(event) => setNama(event.target.value)}
                                />
                            </Field>
                            {canReadJenis ? (
                                <Field data-invalid={Boolean(jenisError)}>
                                    <Select
                                        label="Jenis aset"
                                        items={[
                                            'Tidak ditentukan',
                                            ...jenisOptions.map(optionLabel),
                                        ]}
                                        value={jenisLabel}
                                        placeholder="Pilih jenis aset"
                                        searchPlaceholder="Cari jenis aset"
                                        emptyMessage="Jenis aset tidak ditemukan."
                                        ariaLabel="Pilih jenis aset"
                                        portalContainer={sheetContentRef}
                                        onValueChange={(item) =>
                                            setJenisAsetId(
                                                item === 'Tidak ditentukan'
                                                    ? ''
                                                    : (jenisOptions.find(
                                                          (option) => optionLabel(option) === item,
                                                      )?.id ?? ''),
                                            )
                                        }
                                    />
                                    {jenisError && (
                                        <FieldDescription>{jenisError}</FieldDescription>
                                    )}
                                </Field>
                            ) : (
                                <Field>
                                    <FieldLabel>Jenis aset</FieldLabel>
                                    <FieldDescription>
                                        Jenis aset tidak ditampilkan karena Anda belum memiliki
                                        akses untuk membacanya.
                                    </FieldDescription>
                                </Field>
                            )}
                            <Field>
                                <FieldLabel htmlFor="model-description">Keterangan</FieldLabel>
                                <Textarea
                                    id="model-description"
                                    rows={4}
                                    maxLength={2000}
                                    value={keterangan}
                                    onChange={(event) => setKeterangan(event.target.value)}
                                />
                            </Field>
                            <Field orientation="horizontal">
                                <Switch
                                    id="model-active"
                                    checked={aktif}
                                    onCheckedChange={setAktif}
                                />
                                <FieldLabel htmlFor="model-active">
                                    Data aktif dan dapat dipilih
                                </FieldLabel>
                            </Field>
                            {error && <FieldError>{error}</FieldError>}
                        </FieldGroup>
                    </div>
                    <SheetFooter className="border-t px-6 py-4 sm:flex-row sm:justify-end">
                        <Button variant="outline" type="button" onClick={onClose}>
                            Batal
                        </Button>
                        <Button disabled={saving}>{saving ? 'Menyimpan…' : 'Simpan'}</Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
