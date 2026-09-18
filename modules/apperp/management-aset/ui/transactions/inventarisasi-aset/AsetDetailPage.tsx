import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import { Field, FieldDescription } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import { Textarea } from '@apperp/ui/textarea';
import EditShield from '../../_shared/EditShield';
import { api, errorMessage } from '../../api';
import DynamicField from '../../master/DynamicField';
import type { FieldValue } from '../../master/fields';
import { emptyValue, payloadValue } from '../../master/fields';
import { useMasterOptions } from '../../master/useMasterOptions';
import type { Aset, RincianAset, Context, Placement } from './aset';
import {
    CLASSIFICATION,
    DepreciationPreview,
    EDITABLE,
    LIFECYCLE,
    MANUFACTURER,
    PENERIMAAN_REFERENCES,
    ReferenceFields,
    TANPA_ATRIBUT,
    bukaAset,
    bukaAsetUbah,
    bukaDaftar,
    money,
    referenceDefaults,
    textDefaults,
} from './aset';
import type { AttributeDefinition } from './attributes';
import { toFieldConfig } from './attributes';

type Mode = 'view' | 'edit';

/**
 * Rincian satu aset, pada halaman penuh dengan FastTab.
 *
 * Bentuknya mengikuti halaman aset di Dynamics 365: satu halaman dengan seksi terlipat,
 * bukan panel geser. Sampai 18 September 2026 form ini terkurung di dalam `Sheet`, dan
 * itu yang menahannya: sebuah sheet tidak boleh membuka sheet lain, sehingga seksi di
 * dalamnya tidak pernah bisa punya pemilih sendiri.
 */
export default function AsetDetailPage({
    context,
    canUpdate,
    asetId,
    mode,
}: {
    context: Context;
    canUpdate: boolean;
    asetId?: string;
    mode: Mode;
}) {
    const [detail, setDetail] = useState<RincianAset | null>(null);
    const [memuat, setMemuat] = useState(true);
    const [menyimpan, setMenyimpan] = useState(false);
    const [references, setReferences] = useState<Record<string, FieldValue>>(
        () => referenceDefaults(context),
    );
    // Seluruh isian dikendalikan state, bukan dibaca dari FormData saat submit. Bagian
    // yang terlipat dilepas dari DOM oleh accordion, sehingga isian tak terkendali akan
    // hilang begitu penggunanya menutup bagiannya.
    const [values, setValues] = useState<Record<string, string>>(() =>
        textDefaults(),
    );
    const [parentAsetId, setParentAsetId] = useState('');
    // Atribut diwarisi dari jenis aset, jadi definisinya dibaca ulang tiap jenis berubah.
    const [attributesMuatan, setAttributesMuatan] = useState<{
        typeId: string;
        items: AttributeDefinition[];
    } | null>(null);
    const [attributeValues, setAttributeValues] = useState<
        Record<string, FieldValue>
    >({});
    const [placements, setPlacements] = useState<Placement[]>([]);
    const [aset, setAset] = useState<Aset[]>([]);

    const readOnly = mode === 'view';
    const typeId = String(references.jenis_aset_id ?? '');
    const groupId = String(references.group_aset_id ?? '');
    const attributes =
        attributesMuatan?.typeId === typeId
            ? attributesMuatan.items
            : TANPA_ATRIBUT;

    const { options: locationOptions } = useMasterOptions('lokasi-aset');
    const { options: unitOptions } = useMasterOptions(
        'reference-data/unit-kerja',
    );
    const { options: anggotaOptions } = useMasterOptions(
        'reference-data/anggota',
    );

    // Daftar aset hanya dibutuhkan untuk memilih induk, dan itu tidak berlaku saat
    // membaca: menariknya di mode baca berarti satu permintaan untuk kotak yang tidak
    // pernah dibuka.
    useEffect(() => {
        if (readOnly) {
            return;
        }

        api<{ data: Aset[] }>('/aset')
            .then((result) => setAset(result.data))
            .catch(() => setAset([]));
    }, [readOnly]);

    useEffect(() => {
        if (!asetId) {
            return;
        }

        let dibatalkan = false;
        api<{ data: RincianAset }>(`/aset/${asetId}`)
            .then((result) => {
                if (dibatalkan) {
                    return;
                }

                const isi = result.data;
                setDetail(isi);
                setReferences(
                    Object.fromEntries(
                        PENERIMAAN_REFERENCES.map((field) => [
                            field.name,
                            String(isi[field.name] ?? ''),
                        ]),
                    ),
                );
                setValues({
                    ...textDefaults(),
                    nama: isi.nama,
                    serial_number: String(isi.serial_number ?? ''),
                    model_number: String(isi.model_number ?? ''),
                    acquired_on: String(isi.acquired_on ?? '').slice(0, 10),
                    placed_in_service_on: String(
                        isi.placed_in_service_on ?? '',
                    ).slice(0, 10),
                    acquisition_value: String(isi.acquisition_value ?? ''),
                    currency_code: String(isi.currency_code ?? 'IDR'),
                    keterangan: String(isi.keterangan ?? ''),
                });
                setParentAsetId(String(isi.induk_aset_id ?? ''));
                setAttributeValues(
                    Object.fromEntries(
                        isi.atribut.map((row) => [
                            row.tipe_atribut_id,
                            row.nilai ?? '',
                        ]),
                    ),
                );
            })
            .catch((caught) =>
                toast.error(errorMessage(caught, 'Aset belum dapat dibuka.')),
            )
            .finally(() => {
                if (!dibatalkan) {
                    setMemuat(false);
                }
            });

        return () => {
            dibatalkan = true;
        };
    }, [asetId, context]);

    // Riwayat penempatan hanya dibaca pada mode baca; ia bukan isian dan tidak pernah
    // ikut disimpan.
    useEffect(() => {
        if (!asetId || !readOnly) {
            return;
        }

        api<{ data: { aset: Aset; placements: Placement[] } }>(
            `/aset/${asetId}/history`,
        )
            .then((result) => setPlacements(result.data.placements))
            .catch(() => setPlacements([]));
    }, [asetId, readOnly]);

    useEffect(() => {
        if (!typeId) {
            return;
        }

        let cancelled = false;
        api<{ data: AttributeDefinition[] }>(
            `/jenis-aset/${typeId}/atribut-definisi`,
        )
            .then((result) => {
                if (cancelled) {
                    return;
                }

                setAttributesMuatan({ typeId, items: result.data });
                // Nilai yang sudah ada dipertahankan, bukan ditimpa kosong. Saat membuka
                // aset, definisinya baru selesai dimuat setelah nilainya dipasang;
                // menimpa di sini menghapus isian yang barusan dibaca dari server.
                setAttributeValues((current) =>
                    Object.fromEntries(
                        result.data.map((definition) => [
                            definition.tipe_atribut_id,
                            current[definition.tipe_atribut_id] ??
                                emptyValue(toFieldConfig(definition)),
                        ]),
                    ),
                );
            })
            .catch(() => {
                if (!cancelled) {
                    setAttributesMuatan({ typeId, items: [] });
                }
            });

        return () => {
            cancelled = true;
        };
    }, [typeId]);

    const parentOptions = useMemo(
        () =>
            aset
                .filter((aset) => aset.id !== asetId)
                .map((aset) => ({
                    id: aset.id,
                    label: `${aset.kode} — ${aset.nama}`,
                })),
        [aset, asetId],
    );

    const setValue = (name: string, next: string) =>
        setValues((current) => ({ ...current, [name]: next }));
    const ubahReferensi = (name: string, next: FieldValue) =>
        setReferences((current) => ({ ...current, [name]: next }));
    const namaDari = (options: { id: string; nama: string }[], id: unknown) =>
        options.find((option) => option.id === String(id ?? ''))?.nama ?? null;

    const attributePayload = () =>
        attributes.map((definition) => ({
            tipe_atribut_id: definition.tipe_atribut_id,
            nilai: payloadValue(
                toFieldConfig(definition),
                attributeValues[definition.tipe_atribut_id],
            ),
        }));

    async function simpan() {
        setMenyimpan(true);

        try {
            await api(`/aset/${asetId}`, {
                method: 'PATCH',
                body: JSON.stringify({
                    ...Object.fromEntries(
                        EDITABLE.map((field) => [
                            field.name,
                            payloadValue(field, references[field.name]),
                        ]),
                    ),
                    nama: values.nama,
                    induk_aset_id: parentAsetId || null,
                    serial_number: values.serial_number || null,
                    model_number: values.model_number || null,
                    placed_in_service_on: values.placed_in_service_on || null,
                    keterangan: values.keterangan || null,
                    atribut: attributePayload(),
                }),
            });
            toast.success('Koreksi aset disimpan.');
            bukaAset(String(asetId));
        } catch (caught) {
            toast.error(errorMessage(caught, 'Koreksi belum dapat disimpan.'));
        } finally {
            setMenyimpan(false);
        }
    }

    const textField = (
        name: string,
        label: string,
        extra: Record<string, unknown> = {},
    ) => (
        <Field>
            <Input
                label={label}
                readOnly={readOnly}
                value={values[name] ?? ''}
                onChange={(event) => setValue(name, event.target.value)}
                {...extra}
            />
        </Field>
    );

    /** Kelompok penunjuk; pada mode baca ia dibungkus perisai, bukan dinonaktifkan. */
    const rujukan = (fields: typeof CLASSIFICATION, label: string) => {
        const isi = (
            <ReferenceFields
                fields={fields}
                references={references}
                onChange={ubahReferensi}
            />
        );

        return readOnly ? (
            <EditShield
                active
                label={label}
                onActivate={() => asetId && bukaAsetUbah(asetId)}
            >
                {isi}
            </EditShield>
        ) : (
            isi
        );
    };

    const attributeFields = attributes.map((definition) => (
        <DynamicField
            key={definition.tipe_atribut_id}
            config={toFieldConfig(definition)}
            value={attributeValues[definition.tipe_atribut_id]}
            onChange={(next) =>
                setAttributeValues((current) => ({
                    ...current,
                    [definition.tipe_atribut_id]: next,
                }))
            }
        />
    ));

    if (memuat) {
        return (
            <div className="text-muted-foreground p-5 text-sm">
                Memuat aset…
            </div>
        );
    }

    const judul = `${detail?.kode} — ${detail?.nama}`;
    const status = detail ? LIFECYCLE[detail.lifecycle_state] : undefined;
    const sudahDilepas = detail?.lifecycle_state === 'disposed';

    return (
        <div className="flex h-full min-h-0 flex-col overflow-hidden">
            <RecordActionBar
                title={judul}
                trailing={
                    status ? (
                        <Badge variant={status.variant}>{status.label}</Badge>
                    ) : undefined
                }
            >
                {readOnly && canUpdate && !sudahDilepas && (
                    <ActionButton
                        action="edit"
                        type="button"
                        onClick={() => bukaAsetUbah(String(asetId))}
                    >
                        Ubah
                    </ActionButton>
                )}
                {readOnly && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={bukaDaftar}
                    >
                        Kembali ke daftar
                    </Button>
                )}
                {!readOnly && (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                asetId ? bukaAset(asetId) : bukaDaftar()
                            }
                        >
                            Batal
                        </Button>
                        <Button
                            type="button"
                            onClick={() => void simpan()}
                            disabled={menyimpan}
                        >
                            {menyimpan ? 'Menyimpan…' : 'Simpan koreksi'}
                        </Button>
                    </>
                )}
            </RecordActionBar>

            <div className="min-h-0 flex-1 overflow-y-auto">
                <div className="space-y-4 p-5">
                    {mode === 'edit' && (
                        <p className="text-muted-foreground text-sm">
                            Group aset dan lokasi tidak dapat diganti di sini.
                            Buku penyusutannya sudah terbentuk dari matriks
                            group, dan perpindahan lokasi dicatat sebagai
                            mutasi, bukan koreksi.
                        </p>
                    )}

                    <CollapsibleSectionGroup
                        defaultValue={['klasifikasi', 'perolehan']}
                    >
                        <CollapsibleSection
                            value="klasifikasi"
                            title="Identitas dan klasifikasi"
                            summary={
                                namaDari(
                                    locationOptions,
                                    references.lokasi_aset_id,
                                ) ?? undefined
                            }
                        >
                            <div className="space-y-4">
                                {textField('nama', 'Nama aset', {
                                    maxLength: 150,
                                    required: true,
                                })}
                                {rujukan(
                                    CLASSIFICATION.filter((field) =>
                                        EDITABLE.some(
                                            (item) => item.name === field.name,
                                        ),
                                    ),
                                    'klasifikasi',
                                )}
                            </div>
                        </CollapsibleSection>

                        <CollapsibleSection
                            value="pabrikan"
                            title="Pabrikan dan unit"
                            summary={values.serial_number || undefined}
                        >
                            <div className="space-y-4">
                                {rujukan(MANUFACTURER, 'pabrikan')}
                                {textField('serial_number', 'Nomor seri')}
                                {textField('model_number', 'Nomor model')}
                            </div>
                        </CollapsibleSection>

                        {attributes.length > 0 && (
                            <CollapsibleSection
                                value="atribut"
                                title="Atribut jenis aset"
                                summary={`${attributes.length} atribut`}
                            >
                                <div className="space-y-4">
                                    {readOnly ? (
                                        <EditShield
                                            active
                                            label="atribut"
                                            onActivate={() =>
                                                asetId && bukaAsetUbah(asetId)
                                            }
                                        >
                                            {attributeFields}
                                        </EditShield>
                                    ) : (
                                        attributeFields
                                    )}
                                </div>
                            </CollapsibleSection>
                        )}

                        <CollapsibleSection
                            value="perolehan"
                            title="Perolehan dan nilai"
                            summary={
                                values.acquisition_value
                                    ? money(
                                          values.acquisition_value,
                                          values.currency_code,
                                      )
                                    : undefined
                            }
                        >
                            <div className="space-y-4">
                                {/* Nilai perolehan dan tanggal perolehan hanya diisi saat
                                    penerimaan: mengubahnya sesudah buku punya periode
                                    berjalan ditolak server, dan menampilkannya sebagai
                                    isian pada koreksi hanya mengundang penolakan itu. */}
                                {textField('acquired_on', 'Tanggal perolehan', {
                                    type: 'date',
                                    readOnly: true,
                                })}
                                <Field>
                                    <Input
                                        label="Tanggal mulai digunakan"
                                        type="date"
                                        readOnly={readOnly}
                                        value={values.placed_in_service_on}
                                        onChange={(event) =>
                                            setValue(
                                                'placed_in_service_on',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <FieldDescription>
                                        Dasar perhitungan awal penyusutan.
                                        Kosong berarti sama dengan tanggal
                                        perolehan.
                                    </FieldDescription>
                                </Field>
                                {textField(
                                    'acquisition_value',
                                    'Nilai perolehan',
                                    {
                                        type: 'number',
                                        min: '0',
                                        step: '0.01',
                                        readOnly: true,
                                    },
                                )}
                                {textField('currency_code', 'Mata uang', {
                                    maxLength: 3,
                                    readOnly: true,
                                })}
                            </div>
                        </CollapsibleSection>

                        <CollapsibleSection
                            value="penyusutan"
                            title="Penyusutan"
                        >
                            <DepreciationPreview groupId={groupId} />
                        </CollapsibleSection>

                        <CollapsibleSection
                            value="penempatan"
                            title="Penempatan"
                            summary={
                                namaDari(
                                    unitOptions,
                                    references.usage_org_unit_id,
                                ) ?? undefined
                            }
                        >
                            <div className="space-y-4">
                                // Sesudah aset diterima, penempatan berpindah
                                lewat // dokumen mutasi — bukan lewat koreksi.
                                Yang tampil di // sini keadaan sekarang, dan
                                namanya, bukan idnya.
                                <dl className="grid gap-3 sm:grid-cols-2">
                                    {[
                                        [
                                            'Lokasi',
                                            namaDari(
                                                locationOptions,
                                                references.lokasi_aset_id,
                                            ),
                                        ],
                                        [
                                            'Unit pengguna',
                                            namaDari(
                                                unitOptions,
                                                references.usage_org_unit_id,
                                            ),
                                        ],
                                        [
                                            'Penanggung jawab',
                                            namaDari(
                                                anggotaOptions,
                                                references.custodian_user_id,
                                            ),
                                        ],
                                    ].map(([label, isi]) => (
                                        <div key={label}>
                                            <dt className="text-muted-foreground text-xs">
                                                {label}
                                            </dt>
                                            <dd className="text-sm">
                                                {isi ?? 'Belum diisi'}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </div>
                        </CollapsibleSection>

                        <CollapsibleSection
                            value="struktur"
                            title="Struktur"
                            summary={
                                parentOptions.find(
                                    (option) => option.id === parentAsetId,
                                )?.label
                            }
                        >
                            {readOnly ? (
                                <p className="text-muted-foreground text-sm">
                                    {parentOptions.find(
                                        (option) => option.id === parentAsetId,
                                    )?.label ?? 'Tanpa aset induk'}
                                </p>
                            ) : (
                                <Field>
                                    <Select
                                        label="Aset induk"
                                        items={parentOptions.map(
                                            (option) => option.label,
                                        )}
                                        value={
                                            parentOptions.find(
                                                (option) =>
                                                    option.id === parentAsetId,
                                            )?.label ?? null
                                        }
                                        placeholder="Tanpa induk"
                                        searchPlaceholder="Cari aset induk"
                                        emptyMessage="Aset tidak ditemukan."
                                        ariaLabel="Pilih aset induk"
                                        onValueChange={(item) =>
                                            setParentAsetId(
                                                parentOptions.find(
                                                    (option) =>
                                                        option.label === item,
                                                )?.id ?? '',
                                            )
                                        }
                                    />
                                    <FieldDescription>
                                        Isi bila aset ini bagian dari aset lain,
                                        misalnya mesin yang terpasang pada satu
                                        gedung.
                                    </FieldDescription>
                                </Field>
                            )}
                        </CollapsibleSection>

                        {readOnly && (
                            <CollapsibleSection
                                value="riwayat"
                                title="Riwayat penempatan"
                                summary={
                                    placements.length
                                        ? `${placements.length} perpindahan`
                                        : undefined
                                }
                            >
                                {placements.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">
                                        Belum ada perpindahan selain penerimaan.
                                    </p>
                                ) : (
                                    <div className="space-y-2">
                                        {placements.map((placement) => (
                                            <div
                                                className="rounded-md border px-3 py-2"
                                                key={placement.id}
                                            >
                                                <p className="text-sm font-medium">
                                                    {placement.effective_on}
                                                    <span className="text-muted-foreground ml-2 font-normal">
                                                        {placement.reason ||
                                                            'Penempatan aset'}
                                                    </span>
                                                </p>
                                                <p className="text-muted-foreground text-xs">
                                                    {[
                                                        namaDari(
                                                            locationOptions,
                                                            placement.lokasi_aset_id,
                                                        ),
                                                        namaDari(
                                                            unitOptions,
                                                            placement.usage_org_unit_id,
                                                        ),
                                                        namaDari(
                                                            anggotaOptions,
                                                            placement.custodian_user_id,
                                                        ),
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ') ||
                                                        'Tanpa lokasi, unit, atau PIC'}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CollapsibleSection>
                        )}

                        <CollapsibleSection value="catatan" title="Keterangan">
                            <Field>
                                <Textarea
                                    rows={3}
                                    maxLength={2000}
                                    readOnly={readOnly}
                                    placeholder="Keterangan"
                                    value={values.keterangan}
                                    onChange={(event) =>
                                        setValue(
                                            'keterangan',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </CollapsibleSection>
                    </CollapsibleSectionGroup>
                </div>
            </div>
        </div>
    );
}
