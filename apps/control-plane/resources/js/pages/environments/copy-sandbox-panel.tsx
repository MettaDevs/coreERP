import { FormErrorHeader } from '@/components/form-error-header';
import { Button } from '@apperp/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@apperp/ui/dialog';
import { FormControl } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import { Spinner } from '@apperp/ui/spinner';
import { Link, useForm, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';

export type CopyState = {
    sourceId: string;
    targetId: string | null;
    name: string;
    canRequest: boolean;
    pending: boolean;
    status: string;
    reason: string | null;
};

export default function CopySandboxPanel({
    copy,
    environmentId,
}: {
    copy: CopyState;
    environmentId: string;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({ name: copy.name });
    const copyError = usePage().props.errors.copy;
    const { start, stop } = usePoll(
        3000,
        { only: ['environment', 'history', 'copyState', 'canProvision'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (copy.pending) start();
        else stop();
        return stop;
    }, [copy.pending, start, stop]);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(`/lingkungan/${copy.sourceId}/salin`, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    }

    return (
        <section className="space-y-3 rounded-md border px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2 font-medium">
                {copy.pending && <Spinner />}
                {copy.status}
            </div>
            {copy.reason && (
                <p className="text-muted-foreground">{copy.reason}</p>
            )}
            <FormErrorHeader
                message={copyError}
                operation="Sandbox belum dapat diantrekan"
            />
            <div className="flex flex-wrap gap-2">
                {copy.canRequest && (
                    <Button
                        type="button"
                        onClick={() => {
                            form.clearErrors();
                            form.setData('name', copy.name);
                            setOpen(true);
                        }}
                    >
                        {copy.targetId
                            ? 'Lanjutkan penyalinan'
                            : 'Buat sandbox dari production'}
                    </Button>
                )}
                {copy.targetId && copy.targetId !== environmentId && (
                    <Button asChild variant="ghost">
                        <Link href={`/lingkungan/${copy.targetId}`}>
                            Lihat sandbox
                        </Link>
                    </Button>
                )}
            </div>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {copy.targetId
                                ? 'Lanjutkan penyalinan'
                                : 'Buat sandbox dari production'}
                        </DialogTitle>
                        <DialogDescription>
                            Production tetap berjalan. Salinan memakai database
                            sendiri dan sambungan keluarnya dimatikan. Proses
                            berjalan di latar; hasilnya dapat diikuti di
                            riwayat.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <FormErrorHeader
                            message={copyError}
                            operation="Sandbox belum dapat diantrekan"
                        />
                        <FormControl error={form.errors.name}>
                            <Input
                                id="copy-sandbox-name"
                                label="Nama sandbox"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                readOnly={copy.targetId !== null}
                                required
                                maxLength={100}
                            />
                        </FormControl>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setOpen(false)}
                                disabled={form.processing}
                            >
                                Batal
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing && <Spinner />}
                                {form.processing
                                    ? 'Mengantrekan…'
                                    : copy.targetId
                                      ? 'Lanjutkan penyalinan'
                                      : 'Buat sandbox'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </section>
    );
}
