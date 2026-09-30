import { CircleAlert, UserCheck } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@apperp/ui/alert';
import { Button } from '@apperp/ui/button';
import { Field, FieldError, FieldGroup, FieldHint } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@apperp/ui/sheet';
import { ApiError, api, errorMessage } from '../api';
import type { Account, AccountOption, Worker } from './types';

const NO_ACCOUNT = '__none__';

function accountLabel(account: Account): string {
    return `${account.name} — ${account.email}`;
}

/**
 * Form akun pengguna seorang pekerja (TODO analisa gap BC 9.1).
 *
 * Bila ada akun di organisasi ini yang emailnya sama dengan email pekerja, form mengusulkannya. Usulan hanya
 * usulan: tautan baru tersimpan setelah pengguna memilihnya dan menekan Simpan. Nama dan email pekerja
 * hanya ditampilkan.
 */
export default function WorkerSheet({
    worker,
    onClose,
    onSaved,
}: {
    worker: Worker;
    onClose: () => void;
    onSaved: (worker: Worker) => void;
}) {
    const [membershipId, setMembershipId] = useState<string | null>(
        worker.core_membership_id,
    );
    const [options, setOptions] = useState<AccountOption[]>([]);
    const [optionsError, setOptionsError] = useState('');
    const [suggestion, setSuggestion] = useState<AccountOption | null>(null);
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const [search, setSearch] = useState('');
    const contentRef = useRef<HTMLDivElement>(null);

    // Daftar akun untuk dipilih, dicari di server supaya organisasi besar tidak mengirim semua anggotanya.
    useEffect(() => {
        let cancelled = false;
        const timer = window.setTimeout(() => {
            api<{ data: AccountOption[] }>(
                `/core-members?q=${encodeURIComponent(search)}`,
            )
                .then((result) => {
                    if (!cancelled) {
                        setOptions(result.data);
                        setOptionsError('');
                    }
                })
                .catch((caught) => {
                    if (!cancelled) {
                        setOptionsError(
                            errorMessage(
                                caught,
                                'Daftar akun pengguna belum dapat dimuat.',
                            ),
                        );
                    }
                });
        }, 300);

        return () => {
            cancelled = true;
            window.clearTimeout(timer);
        };
    }, [search]);

    // Usulan dari email pekerja: akun organisasi ini yang emailnya sama persis dan belum dipegang pekerja lain.
    useEffect(() => {
        if (!worker.email) {
            return;
        }

        let cancelled = false;
        api<{ data: AccountOption[] }>(
            `/core-members?email=${encodeURIComponent(worker.email)}`,
        )
            .then((result) => {
                if (!cancelled) {
                    setSuggestion(
                        result.data.find(
                            (option) =>
                                option.linked_worker_id === null ||
                                option.linked_worker_id === worker.id,
                        ) ?? null,
                    );
                }
            })
            .catch(() => {
                // Usulan hanya pelengkap; pengguna tetap bisa memilih akun dari daftar.
            });

        return () => {
            cancelled = true;
        };
    }, [worker.email, worker.id]);

    const shownSuggestion =
        suggestion && suggestion.membership_id !== membershipId
            ? suggestion
            : null;

    // Akun yang sedang terpilih harus selalu ada di pilihan, walau tidak termasuk hasil pencarian terakhir.
    const listed = new Set(options.map((option) => option.membership_id));
    const selected =
        membershipId && !listed.has(membershipId)
            ? [
                  ...(worker.account ? [worker.account] : []),
                  ...(suggestion ? [suggestion] : []),
              ].find((account) => account.membership_id === membershipId)
            : undefined;
    const items = [
        { value: NO_ACCOUNT, label: 'Tanpa akun pengguna' },
        ...(selected
            ? [{ value: selected.membership_id, label: accountLabel(selected) }]
            : []),
        ...options.map((option) => ({
            value: option.membership_id,
            label:
                option.linked_worker_id && option.linked_worker_id !== worker.id
                    ? `${accountLabel(option)} (sudah dipakai pekerja lain)`
                    : accountLabel(option),
        })),
    ];

    async function submit(event: FormEvent) {
        event.preventDefault();
        setSaving(true);
        setError('');

        try {
            const result = await api<{ data: Worker }>(
                `/workers/${worker.id}/core-membership`,
                {
                    method: 'PATCH',
                    body: JSON.stringify({
                        core_membership_id: membershipId,
                        version: worker.version,
                    }),
                },
            );
            onSaved(result.data);
        } catch (caught) {
            setError(
                caught instanceof ApiError &&
                    caught.fieldErrors.core_membership_id
                    ? caught.fieldErrors.core_membership_id
                    : errorMessage(
                          caught,
                          'Akun pengguna belum dapat disimpan.',
                      ),
            );
        } finally {
            setSaving(false);
        }
    }

    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent
                ref={contentRef}
                side="right"
                className="w-full gap-0 p-0 sm:max-w-xl"
            >
                <SheetHeader className="border-b px-6 py-5 pr-12">
                    <SheetTitle>Akun pengguna pekerja</SheetTitle>
                    <SheetDescription>
                        {worker.personnel_number} · {worker.name}
                    </SheetDescription>
                </SheetHeader>
                <form
                    className="flex min-h-0 flex-1 flex-col"
                    onSubmit={submit}
                >
                    <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                        <FieldGroup>
                            <Field>
                                <Input
                                    label="Email pekerja"
                                    readOnly
                                    value={worker.email ?? ''}
                                />
                            </Field>
                            <Field
                                data-invalid={Boolean(error || optionsError)}
                            >
                                {shownSuggestion && (
                                    <Alert>
                                        <UserCheck />
                                        <AlertTitle>
                                            Ada akun dengan email yang sama
                                        </AlertTitle>
                                        <AlertDescription>
                                            <p>
                                                {accountLabel(shownSuggestion)}.
                                                Tautkan bila akun ini memang
                                                milik pekerja ini.
                                            </p>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                className="mt-2"
                                                onClick={() =>
                                                    setMembershipId(
                                                        shownSuggestion.membership_id,
                                                    )
                                                }
                                            >
                                                Pakai akun ini
                                            </Button>
                                        </AlertDescription>
                                    </Alert>
                                )}
                                <div className="flex items-center gap-1.5">
                                    <div className="min-w-0 flex-1">
                                        <Select
                                            label="Akun pengguna"
                                            items={items}
                                            value={membershipId ?? NO_ACCOUNT}
                                            placeholder="Pilih akun pengguna"
                                            searchPlaceholder="Cari nama atau email"
                                            emptyMessage="Akun tidak ditemukan."
                                            portalContainer={contentRef}
                                            onSearchChange={setSearch}
                                            onValueChange={(value) =>
                                                setMembershipId(
                                                    value &&
                                                        value !== NO_ACCOUNT
                                                        ? value
                                                        : null,
                                                )
                                            }
                                        />
                                    </div>
                                    <FieldHint hint="Tautkan bila pekerja ini juga masuk ke aplikasi. Satu akun hanya bisa dipakai satu pekerja. Pekerja tanpa akun tetap tercatat.">
                                        <button
                                            type="button"
                                            aria-label="Penjelasan akun pengguna"
                                            className="text-muted-foreground hover:text-foreground shrink-0"
                                        >
                                            <CircleAlert className="size-4" />
                                        </button>
                                    </FieldHint>
                                </div>
                                {error && <FieldError>{error}</FieldError>}
                                {optionsError && (
                                    <FieldError>{optionsError}</FieldError>
                                )}
                            </Field>
                        </FieldGroup>
                    </div>
                    <SheetFooter className="border-t px-6 py-4 sm:flex-row sm:justify-end">
                        <Button
                            variant="outline"
                            type="button"
                            onClick={onClose}
                        >
                            Batal
                        </Button>
                        <Button disabled={saving}>
                            {saving ? 'Menyimpan…' : 'Simpan'}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
