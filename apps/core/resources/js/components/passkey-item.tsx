import { Button } from '@apperp/ui/button';
import {
    Dialog,
    DialogAction,
    DialogCancel,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@apperp/ui/dialog';
import { KeyRound, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { FormErrorHeader } from '@/components/form-error-header';
import type { Passkey } from '@/types/auth';

export type PasskeyDeleteCallbacks = {
    onError: (errors: Record<string, string>) => void;
    onSuccess: () => void;
    onFinish: () => void;
};

type Props = {
    passkey: Passkey;
    onDelete: (id: number, callbacks: PasskeyDeleteCallbacks) => void;
};

export default function PasskeyItem({ passkey, onDelete }: Props) {
    const [isDeleting, setIsDeleting] = useState(false);
    const [open, setOpen] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const handleDelete = () => {
        setIsDeleting(true);
        setError(null);
        onDelete(passkey.id, {
            onError: (errors) =>
                setError(
                    Object.values(errors).join(' ') ||
                        'Passkey belum dapat dihapus.',
                ),
            onSuccess: () => setOpen(false),
            onFinish: () => setIsDeleting(false),
        });
    };

    return (
        <div className="flex items-center justify-between border-b p-4 last:border-b-0">
            <div className="flex items-center gap-4">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                    <KeyRound className="h-5 w-5 text-muted-foreground" />
                </div>
                <div className="space-y-1">
                    <div className="flex items-center gap-2.5">
                        <p className="font-medium tracking-tight">
                            {passkey.name}
                        </p>
                        {passkey.authenticator && (
                            <span className="inline-flex items-center gap-1 rounded-md bg-muted px-2 py-0.5 text-[11px] font-medium tracking-wide text-muted-foreground uppercase ring-1 ring-border ring-inset">
                                {passkey.authenticator}
                            </span>
                        )}
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Added {passkey.created_at_diff}
                        {passkey.last_used_at_diff && (
                            <>
                                <span className="mx-1 text-muted-foreground/50">
                                    /
                                </span>
                                Last used {passkey.last_used_at_diff}
                            </>
                        )}
                    </p>
                </div>
            </div>

            <Dialog
                open={open}
                onOpenChange={(next) => {
                    if (!isDeleting) {
                        setOpen(next);

                        if (next) {
                            setError(null);
                        }
                    }
                }}
            >
                <DialogTrigger asChild>
                    <Button
                        variant="ghost"
                        size="sm"
                        className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                    >
                        <Trash2 className="h-4 w-4" />
                        <span className="sr-only">Remove</span>
                    </Button>
                </DialogTrigger>
                <DialogContent size="compact">
                    <DialogHeader>
                        <DialogTitle>Delete passkey ini?</DialogTitle>
                        <DialogDescription>
                            Passkey "{passkey.name}" tidak dapat dipakai lagi
                            untuk masuk setelah dihapus.
                        </DialogDescription>
                    </DialogHeader>
                    <FormErrorHeader
                        message={error}
                        operation="Passkey belum dihapus"
                    />
                    <DialogFooter>
                        <DialogAction
                            variant="destructive"
                            onClick={(event) => {
                                event.preventDefault();
                                handleDelete();
                            }}
                            disabled={isDeleting}
                        >
                            {isDeleting ? 'Menghapus...' : 'Delete'}
                        </DialogAction>
                        <DialogCancel disabled={isDeleting}>Batal</DialogCancel>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
