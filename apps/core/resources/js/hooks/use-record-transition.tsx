import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@apperp/ui/alert-dialog';
import { useState } from 'react';

export function useRecordTransition(dirty: boolean, busy: boolean) {
    const [pending, setPending] = useState<(() => void) | null>(null);
    const request = (action: () => void) => {
        if (busy) {
            return;
        }

        if (dirty) {
            setPending(() => action);
        } else {
            action();
        }
    };

    return {
        request,
        confirmation: (
            <AlertDialog
                open={pending !== null}
                onOpenChange={(open) => !open && setPending(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Buang perubahan yang belum disimpan?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Perubahan pada kartu ini belum disimpan. Pilih Batal
                            untuk melanjutkan mengisi kartu.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => {
                                const action = pending;
                                setPending(null);
                                action?.();
                            }}
                        >
                            Buang perubahan
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        ),
    };
}
