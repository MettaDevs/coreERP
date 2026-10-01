import { useEffect, useState } from 'react';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
import { Empty, EmptyDescription } from '@apperp/ui/empty';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { api, errorMessage } from '../../api';
import type { MasterOption } from '../useMasterOptions';
import { optionLabel, useMasterOptions } from '../useMasterOptions';

type Kind = 'aset' | 'jenis';

type Target = {
    id: string | null;
    kind: Kind;
    aset_id: string;
    jenis_aset_id: string;
    tanggal_mulai: string;
    aktif: boolean;
    label?: string;
};

type ServerTarget = {
    id: string;
    aset_id: string | null;
    jenis_aset_id: string | null;
    tanggal_mulai: string | null;
    aktif: boolean;
    aset_kode: string | null;
    aset_nama: string | null;
    jenis_aset_kode: string | null;
    jenis_aset_nama: string | null;
};

const KINDS = [
    { value: 'jenis', label: 'Semua aset satu jenis' },
    { value: 'aset', label: 'Satu aset' },
];

function fromServer(target: ServerTarget): Target {
    return {
        id: target.id,
        kind: target.aset_id ? 'aset' : 'jenis',
        aset_id: target.aset_id ?? '',
        jenis_aset_id: target.jenis_aset_id ?? '',
        tanggal_mulai: target.tanggal_mulai?.slice(0, 10) ?? '',
        aktif: target.aktif,
        label: target.aset_id
            ? `${target.aset_kode} — ${target.aset_nama}`
            : `${target.jenis_aset_kode} — ${target.jenis_aset_nama}`,
    };
}

const items = (options: MasterOption[]) =>
    options.map((option) => ({ value: option.id, label: optionLabel(option) }));

/**
 * Aset yang dikenai rencana pemeliharaan; padanan FastTab Assets dan Asset types pada rencana F&O.
 *
 * Objek jenis aset dibaca setiap kali jadwal dihitung, jadi aset yang sudah ada maupun yang
 * diterima kemudian ikut — berbeda dari F&O yang hanya memasangnya pada aset baru.
 */
export default function MaintenancePlanTargets({
    planId,
    canEdit,
    version,
    onVersionChange,
}: {
    planId: string;
    canEdit: boolean;
    /** Versi record pemilik; penyimpanan rincian ini mengklaimnya. */
    version: number;
    onVersionChange: (version: number) => void;
}) {
    const [targets, setTargets] = useState<Target[]>([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);
    const [assets, setAssets] = useState<MasterOption[]>([]);
    const [assetError, setAssetError] = useState('');
    const jenis = useMasterOptions(canEdit ? 'jenis-aset' : null);

    useEffect(() => {
        let dilepas = false;

        api<{ data: ServerTarget[] }>(`/rencana-pemeliharaan/${planId}/objek`)
            .then((result) => {
                if (!dilepas) {
                    setTargets(result.data.map(fromServer));
                }
            })
            .catch((caught) => {
                if (!dilepas) {
                    setError(
                        errorMessage(
                            caught,
                            'Objek rencana belum dapat dimuat.',
                        ),
                    );
                }
            })
            .finally(() => {
                if (!dilepas) {
                    setLoading(false);
                }
            });

        return () => {
            dilepas = true;
        };
    }, [planId]);

    // Register aset hanya dimuat saat menyunting. Pengguna yang boleh menyusun rencana belum
    // tentu boleh melihat register aset; yang tersisa baginya objek jenis aset.
    useEffect(() => {
        if (!canEdit) {
            return;
        }

        let dilepas = false;
        api<{ data: MasterOption[] }>('/aset')
            .then((result) => {
                if (!dilepas) {
                    setAssets(result.data);
                }
            })
            .catch(() => {
                if (!dilepas) {
                    setAssetError(
                        'Daftar aset belum dapat dimuat. Anda tetap dapat memilih jenis aset.',
                    );
                }
            });

        return () => {
            dilepas = true;
        };
    }, [canEdit]);

    function update(index: number, changes: Partial<Target>) {
        setSaved(false);
        setTargets((current) =>
            current.map((target, position) =>
                position === index ? { ...target, ...changes } : target,
            ),
        );
    }

    async function save() {
        setSaving(true);
        setError('');

        try {
            const result = await api<{ data: ServerTarget[]; version: number }>(
                `/rencana-pemeliharaan/${planId}/objek`,
                {
                    method: 'PUT',
                    body: JSON.stringify({
                        version,
                        targets: targets.map((target) => ({
                            id: target.id,
                            aset_id:
                                target.kind === 'aset'
                                    ? target.aset_id || null
                                    : null,
                            jenis_aset_id:
                                target.kind === 'jenis'
                                    ? target.jenis_aset_id || null
                                    : null,
                            tanggal_mulai: target.tanggal_mulai || null,
                            aktif: target.aktif,
                        })),
                    }),
                },
            );
            setTargets(result.data.map(fromServer));
            onVersionChange(result.version);
            setSaved(true);
        } catch (caught) {
            setError(
                errorMessage(caught, 'Objek rencana belum dapat disimpan.'),
            );
        } finally {
            setSaving(false);
        }
    }

    if (loading) {
        return (
            <p className="text-muted-foreground text-sm">
                Memuat objek rencana…
            </p>
        );
    }

    if (!canEdit) {
        return targets.length === 0 ? (
            <Empty>
                <EmptyDescription>
                    Rencana ini belum dikenakan pada aset atau jenis aset mana
                    pun.
                </EmptyDescription>
            </Empty>
        ) : (
            <ul className="divide-y rounded-md border">
                {targets.map((target, index) => (
                    <li
                        key={target.id ?? index}
                        className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-sm"
                    >
                        <Badge variant="outline">
                            {target.kind === 'aset' ? 'Aset' : 'Jenis aset'}
                        </Badge>
                        <span>{target.label}</span>
                        {target.tanggal_mulai && (
                            <span className="text-muted-foreground">
                                mulai {target.tanggal_mulai}
                            </span>
                        )}
                        {!target.aktif && (
                            <Badge variant="outline">Tidak aktif</Badge>
                        )}
                    </li>
                ))}
            </ul>
        );
    }

    return (
        <div className="space-y-3">
            {targets.length === 0 ? (
                <Empty>
                    <EmptyDescription>
                        Pilih aset tertentu, atau satu jenis aset supaya seluruh
                        aset jenis itu ikut dijadwalkan.
                    </EmptyDescription>
                </Empty>
            ) : (
                <div className="overflow-x-auto rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Berlaku untuk</TableHead>
                                <TableHead>Aset atau jenis aset</TableHead>
                                <TableHead>Tanggal mulai</TableHead>
                                <TableHead>Aktif</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {targets.map((target, index) => (
                                <TableRow key={target.id ?? `baru-${index}`}>
                                    <TableCell className="min-w-48">
                                        <Select
                                            items={KINDS}
                                            value={target.kind}
                                            ariaLabel={`Jenis objek baris ${index + 1}`}
                                            onValueChange={(value) =>
                                                value &&
                                                update(index, {
                                                    kind: value as Kind,
                                                    aset_id: '',
                                                    jenis_aset_id: '',
                                                })
                                            }
                                        />
                                    </TableCell>
                                    <TableCell className="min-w-64">
                                        {target.kind === 'aset' ? (
                                            <Select
                                                items={items(assets)}
                                                value={target.aset_id || null}
                                                placeholder="Pilih aset"
                                                ariaLabel={`Aset baris ${index + 1}`}
                                                onValueChange={(value) =>
                                                    update(index, {
                                                        aset_id: value ?? '',
                                                    })
                                                }
                                            />
                                        ) : (
                                            <Select
                                                items={items(jenis.options)}
                                                value={
                                                    target.jenis_aset_id || null
                                                }
                                                placeholder="Pilih jenis aset"
                                                ariaLabel={`Jenis aset baris ${index + 1}`}
                                                onValueChange={(value) =>
                                                    update(index, {
                                                        jenis_aset_id:
                                                            value ?? '',
                                                    })
                                                }
                                            />
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <Input
                                            type="date"
                                            aria-label={`Tanggal mulai baris ${index + 1}`}
                                            value={target.tanggal_mulai}
                                            onChange={(event) =>
                                                update(index, {
                                                    tanggal_mulai:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <Checkbox
                                            aria-label={`Aktif baris ${index + 1}`}
                                            checked={target.aktif}
                                            onCheckedChange={(checked) =>
                                                update(index, {
                                                    aktif: checked === true,
                                                })
                                            }
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => {
                                                setSaved(false);
                                                setTargets((current) =>
                                                    current.filter(
                                                        (_, position) =>
                                                            position !== index,
                                                    ),
                                                );
                                            }}
                                        >
                                            Keluarkan
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}
            <p className="text-muted-foreground text-sm">
                Tanggal mulai yang kosong memakai tanggal mulai rencana.
            </p>
            <div className="flex gap-2">
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => {
                        setSaved(false);
                        setTargets((current) => [
                            ...current,
                            {
                                id: null,
                                kind: 'jenis',
                                aset_id: '',
                                jenis_aset_id: '',
                                tanggal_mulai: '',
                                aktif: true,
                            },
                        ]);
                    }}
                >
                    Tambah
                </Button>
                <Button
                    type="button"
                    disabled={saving}
                    onClick={() => void save()}
                >
                    {saving ? 'Menyimpan…' : 'Simpan objek rencana'}
                </Button>
            </div>
            {assetError && (
                <p className="text-muted-foreground text-sm">{assetError}</p>
            )}
            {saved && (
                <p className="text-muted-foreground text-sm">
                    Objek rencana tersimpan.
                </p>
            )}
            {error && <p className="text-destructive text-sm">{error}</p>}
        </div>
    );
}
