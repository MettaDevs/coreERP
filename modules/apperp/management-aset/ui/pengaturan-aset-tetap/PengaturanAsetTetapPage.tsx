import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ActionButton } from '@apperp/ui/action-button';
import { Button } from '@apperp/ui/button';
import {
    Field,
    FieldDescription,
    FieldLegend,
    FieldSet,
} from '@apperp/ui/field';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import EditShield from '../_shared/EditShield';
import { api, errorMessage, toastSaveError } from '../api';
import { optionLabel, useMasterOptions } from '../master/useMasterOptions';

type Pengaturan = {
    version: number;
    buku_penyusutan_bawaan_id: string | null;
    buku_penyusutan_bawaan: { id: string; kode: string; nama: string } | null;
};

/**
 * Parameter aset tetap, padanan halaman Fixed Asset Setup Business Central: satu kartu untuk seluruh
 * tenant, terbuka dalam mode baca dan disunting lewat tombol Ubah.
 *
 * Hanya pengaturan yang benar-benar dibaca modul yang tampil di sini. Pengaturan BC lain yang belum
 * dipakai sengaja tidak dibuat; daftarnya ada di docs halaman ini.
 */
export default function PengaturanAsetTetapPage({
    permissions,
}: {
    permissions: string[];
}) {
    const canUpdate = permissions.includes(
        'management-aset.fixed-asset-parameters.update',
    );
    const [tersimpan, setTersimpan] = useState<Pengaturan | null>(null);
    const [buku, setBuku] = useState('');
    const [mengubah, setMengubah] = useState(false);
    const [menyimpan, setMenyimpan] = useState(false);
    const [galat, setGalat] = useState('');
    const daftarBuku = useMasterOptions('buku-penyusutan');
    const panelRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        let dibatalkan = false;
        api<{ data: Pengaturan }>('/pengaturan-aset-tetap')
            .then((result) => {
                if (!dibatalkan) {
                    setTersimpan(result.data);
                    setBuku(result.data.buku_penyusutan_bawaan_id ?? '');
                }
            })
            .catch((caught) => {
                if (!dibatalkan) {
                    setGalat(
                        errorMessage(
                            caught,
                            'Parameter aset tetap belum dapat dimuat.',
                        ),
                    );
                }
            });

        return () => {
            dibatalkan = true;
        };
    }, []);

    // Buku yang sedang dipakai tetap dapat dipilih meski sudah tidak aktif, supaya menyimpan
    // tidak diam-diam mengosongkannya.
    const pilihan =
        tersimpan?.buku_penyusutan_bawaan &&
        !daftarBuku.options.some(
            (option) => option.id === tersimpan.buku_penyusutan_bawaan?.id,
        )
            ? [tersimpan.buku_penyusutan_bawaan, ...daftarBuku.options]
            : daftarBuku.options;
    const dipilih = pilihan.find((option) => option.id === buku);

    function batal() {
        setBuku(tersimpan?.buku_penyusutan_bawaan_id ?? '');
        setMengubah(false);
    }

    async function simpan() {
        if (!tersimpan) {
            return;
        }

        setMenyimpan(true);

        try {
            const hasil = await api<{ data: Pengaturan }>(
                '/pengaturan-aset-tetap',
                {
                    method: 'PUT',
                    body: JSON.stringify({
                        version: tersimpan.version,
                        buku_penyusutan_bawaan_id: buku || null,
                    }),
                },
            );
            setTersimpan(hasil.data);
            setBuku(hasil.data.buku_penyusutan_bawaan_id ?? '');
            setMengubah(false);
            toast.success('Parameter aset tetap disimpan.');
        } catch (caught) {
            toastSaveError(
                caught,
                'Parameter aset tetap belum dapat disimpan.',
            );
        } finally {
            setMenyimpan(false);
        }
    }

    return (
        <div className="flex h-full min-h-0 flex-col overflow-hidden">
            <RecordActionBar title="Parameter aset tetap">
                {!mengubah && canUpdate && tersimpan && (
                    <ActionButton
                        action="edit"
                        type="button"
                        onClick={() => setMengubah(true)}
                    >
                        Ubah
                    </ActionButton>
                )}
                {mengubah && (
                    <>
                        <Button type="button" variant="outline" onClick={batal}>
                            Batal
                        </Button>
                        <Button
                            type="button"
                            onClick={() => void simpan()}
                            disabled={menyimpan}
                        >
                            {menyimpan ? 'Menyimpan…' : 'Simpan'}
                        </Button>
                    </>
                )}
            </RecordActionBar>

            <div ref={panelRef} className="min-h-0 flex-1 overflow-y-auto">
                <div className="max-w-2xl space-y-5 p-5">
                    {galat ? (
                        <FieldDescription>{galat}</FieldDescription>
                    ) : !tersimpan ? (
                        <p className="text-muted-foreground text-sm">
                            Memuat parameter aset tetap…
                        </p>
                    ) : (
                        <FieldSet>
                            <FieldLegend>Umum</FieldLegend>
                            <Field>
                                <EditShield
                                    active={!mengubah}
                                    label="buku penyusutan bawaan"
                                    onActivate={() =>
                                        canUpdate && setMengubah(true)
                                    }
                                >
                                    <Select
                                        label="Buku penyusutan bawaan"
                                        items={pilihan.map(optionLabel)}
                                        value={
                                            dipilih
                                                ? optionLabel(dipilih)
                                                : null
                                        }
                                        placeholder="Pilih buku penyusutan"
                                        searchPlaceholder="Cari buku penyusutan"
                                        emptyMessage="Buku penyusutan tidak ditemukan."
                                        ariaLabel="Buku penyusutan bawaan"
                                        portalContainer={panelRef}
                                        onValueChange={(item) =>
                                            setBuku(
                                                pilihan.find(
                                                    (option) =>
                                                        optionLabel(option) ===
                                                        item,
                                                )?.id ?? '',
                                            )
                                        }
                                    />
                                </EditShield>
                                {mengubah && buku !== '' && (
                                    <Button
                                        type="button"
                                        variant="link"
                                        size="sm"
                                        className="h-auto justify-start px-0"
                                        onClick={() => setBuku('')}
                                    >
                                        Kosongkan buku penyusutan bawaan
                                    </Button>
                                )}
                                <FieldDescription>
                                    {daftarBuku.error ||
                                        'Nilai buku dari buku ini yang dicatat saat monitoring aset. Bila kosong, yang dipakai buku komersial dengan kode paling awal.'}
                                </FieldDescription>
                            </Field>
                        </FieldSet>
                    )}
                </div>
            </div>
        </div>
    );
}
