import { Button } from '@apperp/ui/button';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@apperp/ui/dialog';
import { NativeSelect } from '@apperp/ui/native-select';
import { Spinner } from '@apperp/ui/spinner';
import { Link, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import {
    ServerAddressField,
    ServerAdvancedFields,
} from '@/components/server-settings-fields';

export type Candidate = { id: string; name: string; tenant: string };

/**
 * "Tambah server klien" dari daftar server klien.
 *
 * Bukan formulir situs yang berdiri sendiri. Server klien selalu milik satu lingkungan produksi yang
 * berjalan di server klien, jadi yang dipilih di sini lingkungannya — daftarnya dari server, hanya yang
 * belum punya server — lalu isiannya dikirim ke pintu yang sama dengan panel di halaman lingkungan. Sesudah
 * tersimpan, operator dibawa ke halaman lingkungan itu, tempat perintah pasang dibuat.
 */
export default function AddServerDialog({
    candidates,
}: {
    candidates: Candidate[];
}) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, transform, clearErrors } =
        useForm({
            environment_id: candidates[0]?.id ?? '',
            server_address: '',
            address: '',
            update_window_start: '',
            update_window_end: '',
        });

    // Penolakan `ClientServerSetup` datang dengan nama `server_client`, bukan nama satu isian.
    const refusal = (errors as Record<string, string | undefined>)
        .server_client;

    function submit(e: FormEvent) {
        e.preventDefault();

        // Id lingkungan ada di alamatnya, bukan di isian.
        transform((values) => ({
            server_address: values.server_address,
            address: values.address,
            update_window_start: values.update_window_start,
            update_window_end: values.update_window_end,
        }));
        post(`/lingkungan/${data.environment_id}/server-klien`, {
            onSuccess: () => setOpen(false),
        });
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(opened) => {
                setOpen(opened);

                if (!opened) {
                    clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button>
                    <Plus />
                    Tambah server klien
                </Button>
            </DialogTrigger>
            <DialogContent>
                {candidates.length === 0 ? (
                    <>
                        <DialogHeader>
                            <DialogTitle>Tambah server klien</DialogTitle>
                            <DialogDescription>
                                Setiap server klien menjalankan satu lingkungan
                                produksi.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-3 py-4 text-sm">
                            <p>
                                Belum ada lingkungan produksi di server klien
                                yang belum punya server. Buat dulu lingkungan
                                produksinya:
                            </p>
                            <ol className="list-decimal space-y-1 ps-5 text-muted-foreground">
                                <li>
                                    Buka <strong>Lingkungan</strong>, tekan{' '}
                                    <strong>Buat lingkungan</strong>.
                                </li>
                                <li>
                                    Pilih jenis <strong>Produksi</strong>, lalu{' '}
                                    <strong>Server klien</strong>.
                                </li>
                                <li>
                                    Kembali ke sini, atau siapkan server
                                    kliennya langsung dari halaman lingkungan
                                    itu.
                                </li>
                            </ol>
                            <p className="text-xs text-muted-foreground">
                                Tenant baru juga dapat langsung lahir dengan
                                produksi di server klien dari layar Tenant.
                            </p>
                        </div>
                        <DialogFooter>
                            <Button asChild>
                                <Link href="/lingkungan">Ke Lingkungan</Link>
                            </Button>
                        </DialogFooter>
                    </>
                ) : (
                    <form onSubmit={submit}>
                        <DialogHeader>
                            <DialogTitle>Tambah server klien</DialogTitle>
                            <DialogDescription>
                                Catat VPS milik klien untuk satu lingkungan
                                produksi. Belum ada yang dipasang sampai
                                perintah pasangnya dijalankan di sana.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4 py-4">
                            {refusal && (
                                <div
                                    role="alert"
                                    className="rounded-md border border-destructive/40 bg-red-50 px-4 py-3 text-sm text-destructive dark:bg-red-950/40 dark:text-red-200"
                                >
                                    {refusal}
                                </div>
                            )}

                            <div className="space-y-2">
                                <NativeSelect
                                    id="environment_id"
                                    label="Lingkungan produksi"
                                    value={data.environment_id}
                                    onChange={(e) =>
                                        setData(
                                            'environment_id',
                                            e.target.value,
                                        )
                                    }
                                >
                                    {candidates.map((candidate) => (
                                        <option
                                            key={candidate.id}
                                            value={candidate.id}
                                        >
                                            {candidate.tenant} —{' '}
                                            {candidate.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <p className="text-xs text-muted-foreground">
                                    Hanya produksi di server klien yang belum
                                    punya server. Nama server, app yang dibeli,
                                    dan rilisnya diambil sistem.
                                </p>
                            </div>

                            <ServerAddressField
                                value={data.server_address}
                                onChange={(value) =>
                                    setData('server_address', value)
                                }
                                error={errors.server_address}
                            />

                            <CollapsibleSectionGroup>
                                <CollapsibleSection
                                    value="lanjutan"
                                    title="Lanjutan"
                                    summary="Alamat aplikasi dan jendela pembaruan, boleh diisi belakangan"
                                >
                                    <ServerAdvancedFields
                                        data={data}
                                        setData={setData}
                                        errors={errors}
                                    />
                                </CollapsibleSection>
                            </CollapsibleSectionGroup>
                        </div>

                        <DialogFooter>
                            <Button
                                type="submit"
                                disabled={
                                    processing || data.environment_id === ''
                                }
                            >
                                {processing && <Spinner />}
                                Simpan dan lanjut ke perintah pasang
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
