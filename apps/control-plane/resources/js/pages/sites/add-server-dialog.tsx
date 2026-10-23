import { FormErrorHeader } from '@/components/form-error-header';
import { useInertiaFeedback } from '@/hooks/use-inertia-feedback';
import { ActionButton } from '@apperp/ui/action-button';
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
    DialogToolbar,
} from '@apperp/ui/dialog';
import { NativeSelect } from '@apperp/ui/native-select';
import { useConfirmation } from '@apperp/ui/use-confirmation';
import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import {
    ServerAddressField,
    ServerAdvancedFields,
} from '@/components/server-settings-fields';

export type Candidate = { id: string; name: string; tenant: string };

/**
 * "Server klien baru" dari daftar server klien.
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
    const { wrap: wrapLocalForm0Request, summary: localForm0RequestSummary } =
        useInertiaFeedback();

    const [open, setOpen] = useState(false);
    const { confirm, confirmation } = useConfirmation();
    const {
        data,
        setData,
        post,
        processing,
        errors,
        transform,
        clearErrors,
        reset,
        isDirty,
    } = useForm({
        environment_id: candidates[0]?.id ?? '',
        server_address: '',
        update_window_start: '',
        update_window_end: '',
    });

    // Penolakan `ClientServerSetup` datang dengan nama `server_client`, bukan nama satu isian.
    const refusal = (errors as Record<string, string | undefined>)
        .server_client;

    async function closeDraft() {
        if (processing) {
            return;
        }

        if (
            isDirty &&
            !(await confirm({
                title: 'Buang perubahan?',
                description: 'Isian yang belum disimpan akan dibuang.',
                confirmLabel: 'Buang perubahan',
                destructive: true,
            }))
        ) {
            return;
        }

        reset();
        clearErrors();
        setOpen(false);
    }
    function submit(e: FormEvent) {
        e.preventDefault();

        // Id lingkungan ada di alamatnya, bukan di isian.
        transform((values) => ({
            server_address: values.server_address,
            update_window_start: values.update_window_start,
            update_window_end: values.update_window_end,
        }));
        post(
            `/lingkungan/${data.environment_id}/server-klien`,
            wrapLocalForm0Request({
                onSuccess: () => setOpen(false),
            }),
        );
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(opened) => {
                if (opened) {
                    setOpen(true);
                } else {
                    void closeDraft();
                }
            }}
        >
            {confirmation}
            <DialogTrigger asChild>
                <ActionButton action="create" />
            </DialogTrigger>
            <DialogContent>
                {candidates.length === 0 ? (
                    <>
                        <DialogHeader>
                            <DialogTitle>Server klien baru</DialogTitle>
                            <DialogDescription>
                                Setiap server klien menjalankan satu lingkungan
                                produksi.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-3 py-4 text-sm">
                            <p>
                                Belum ada lingkungan produksi yang dapat
                                dipilih. Siapkan lingkungan di server klien
                                terlebih dahulu:
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
                    <form noValidate onSubmit={submit}>
                        <DialogHeader>
                            <DialogTitle>Server klien baru</DialogTitle>
                            <DialogDescription>
                                Pilih lingkungan dan catat alamat server.
                                Setelah disimpan, lanjutkan dengan menjalankan
                                perintah pemasangan di server klien.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogToolbar>
                            <ActionButton
                                action="save"
                                type="submit"
                                pending={processing}
                                disabled={data.environment_id === ''}
                            />
                            <ActionButton
                                action="cancel"
                                disabled={processing}
                                onClick={() => void closeDraft()}
                            />
                        </DialogToolbar>
                        <>
                            {localForm0RequestSummary}
                            <FormErrorHeader errors={errors} />
                        </>

                        <div className="space-y-4 py-4">
                            {refusal && (
                                <div
                                    role="alert"
                                    className="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive"
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
                                    summary="Jendela pembaruan, boleh diisi belakangan"
                                >
                                    <ServerAdvancedFields
                                        data={data}
                                        setData={setData}
                                        errors={errors}
                                    />
                                </CollapsibleSection>
                            </CollapsibleSectionGroup>
                        </div>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
