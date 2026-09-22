import { ActionButton } from '@apperp/ui/action-button';
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
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import { Checkbox } from '@apperp/ui/checkbox';
import {
    Dialog,
    DialogBody,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@apperp/ui/dropdown-menu';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import {
    Sheet,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@apperp/ui/sheet';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Textarea } from '@apperp/ui/textarea';
import { Head, router } from '@inertiajs/react';
import { Copy, MoreHorizontal } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import { apiJson, CoreApiError, errorText } from '@/lib/core-api';
import type { BreadcrumbItem } from '@/types/navigation';

type Mode = 'pull' | 'push';
type Client = {
    id: string;
    name: string;
    delivery_mode: Mode;
    push_url: string | null;
    scopes: string[];
    allowed_ips: string[];
    posting_type_prefixes: string[];
    status: 'active' | 'revoked';
    has_signing_secret: boolean;
    last_used_at: string | null;
    last_pulled_at: string | null;
    revoked_at: string | null;
    created_at: string | null;
};
type Props = {
    clients: Client[];
    scopes: Record<string, string>;
    endpoint: string;
};
type Secrets = {
    title: string;
    token: string | null;
    signing_secret: string | null;
};
type Form = {
    name: string;
    delivery_mode: Mode;
    push_url: string;
    scopes: string[];
    posting_type_prefixes: string;
    allowed_ips: string;
};

const MODE_LABEL: Record<Mode, string> = {
    pull: 'Tarik (pembaca menarik)',
    push: 'Dorong (CoreERP mengirim)',
};

function waktu(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat('id-ID', {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : 'Belum pernah';
}

function toForm(client: Client | null): Form {
    return {
        name: client?.name ?? '',
        delivery_mode: client?.delivery_mode ?? 'pull',
        push_url: client?.push_url ?? '',
        scopes: client?.scopes ?? [
            'finance-postings.read',
            'finance-postings.ack',
            'vendors.read',
            'operating-units.read',
        ],
        posting_type_prefixes: (
            client?.posting_type_prefixes ?? ['asset.']
        ).join(', '),
        allowed_ips: (client?.allowed_ips ?? []).join('\n'),
    };
}

function payload(form: Form) {
    const list = (value: string, separator: RegExp) =>
        value
            .split(separator)
            .map((item) => item.trim())
            .filter(Boolean);

    return {
        name: form.name,
        delivery_mode: form.delivery_mode,
        push_url: form.delivery_mode === 'push' ? form.push_url : null,
        scopes: form.scopes,
        posting_type_prefixes: list(form.posting_type_prefixes, /[,\s]+/),
        allowed_ips: list(form.allowed_ips, /[\n,]+/),
    };
}

function ClientSheet({
    client,
    scopes,
    onClose,
    onSecrets,
}: {
    client: Client | null;
    scopes: Props['scopes'];
    onClose: () => void;
    onSecrets: (secrets: Secrets) => void;
}) {
    const [form, setForm] = useState<Form>(toForm(client));
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);
    const set = <K extends keyof Form>(key: K, value: Form[K]) =>
        setForm((current) => ({ ...current, [key]: value }));
    const error = (key: string) =>
        errors[key]?.[0] ??
        Object.entries(errors).find(([name]) =>
            name.startsWith(`${key}.`),
        )?.[1][0];

    const save = async () => {
        setSaving(true);
        setErrors({});

        try {
            const result = await apiJson<{
                data: Client;
                token?: string;
                signing_secret?: string | null;
            }>(
                client
                    ? `/api/v1/integration-clients/${client.id}`
                    : '/api/v1/integration-clients',
                {
                    method: client ? 'PATCH' : 'POST',
                    body: JSON.stringify(payload(form)),
                },
            );
            router.reload({ only: ['clients'] });
            onClose();

            if (result.token || result.signing_secret) {
                onSecrets({
                    title: client
                        ? 'Rahasia penanda tangan baru'
                        : `Klien ${result.data.name} dibuat`,
                    token: result.token ?? null,
                    signing_secret: result.signing_secret ?? null,
                });
            } else {
                toast.success('Klien integrasi disimpan.');
            }
        } catch (caught) {
            if (caught instanceof CoreApiError) {
                setErrors(caught.errors);
            }

            toast.error(errorText(caught, 'Klien integrasi belum disimpan.'));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent side="right" className="w-full gap-0 p-0 sm:max-w-xl">
                <SheetHeader className="border-b px-6 py-5 pr-12">
                    <SheetTitle>
                        {client
                            ? 'Ubah klien integrasi'
                            : 'Klien integrasi baru'}
                    </SheetTitle>
                </SheetHeader>
                <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                    <FieldGroup>
                        <Field data-invalid={Boolean(error('name'))}>
                            <Input
                                label="Nama"
                                required
                                maxLength={120}
                                placeholder="Contoh: Old-finance"
                                value={form.name}
                                onChange={(event) =>
                                    set('name', event.target.value)
                                }
                            />
                            <FieldError>{error('name')}</FieldError>
                        </Field>
                        <Field data-invalid={Boolean(error('delivery_mode'))}>
                            <NativeSelect
                                label="Mode pengiriman"
                                value={form.delivery_mode}
                                onChange={(event) =>
                                    set(
                                        'delivery_mode',
                                        event.target.value as Mode,
                                    )
                                }
                            >
                                <NativeSelectOption value="pull">
                                    {MODE_LABEL.pull}
                                </NativeSelectOption>
                                <NativeSelectOption value="push">
                                    {MODE_LABEL.push}
                                </NativeSelectOption>
                            </NativeSelect>
                            <FieldDescription>
                                Tarik: aplikasi finance mengambil posting dan
                                mengakuinya. Dorong: CoreERP mengirim tiap
                                posting ke URL aplikasi finance dengan tanda
                                tangan.
                            </FieldDescription>
                        </Field>
                        {form.delivery_mode === 'push' && (
                            <Field data-invalid={Boolean(error('push_url'))}>
                                <Input
                                    label="URL tujuan"
                                    required
                                    type="url"
                                    placeholder="https://finance.contoh.co.id/coreerp/postings"
                                    value={form.push_url}
                                    onChange={(event) =>
                                        set('push_url', event.target.value)
                                    }
                                />
                                <FieldError>{error('push_url')}</FieldError>
                            </Field>
                        )}
                        <FieldSet data-invalid={Boolean(error('scopes'))}>
                            <FieldLegend>Izin</FieldLegend>
                            {Object.entries(scopes).map(([code, label]) => (
                                <Field key={code} orientation="horizontal">
                                    <Checkbox
                                        id={`scope-${code}`}
                                        checked={form.scopes.includes(code)}
                                        onCheckedChange={(checked) =>
                                            set(
                                                'scopes',
                                                checked
                                                    ? [...form.scopes, code]
                                                    : form.scopes.filter(
                                                          (scope) =>
                                                              scope !== code,
                                                      ),
                                            )
                                        }
                                    />
                                    <FieldLabel htmlFor={`scope-${code}`}>
                                        {label}
                                        <span className="ml-1 font-mono text-xs text-muted-foreground">
                                            {code}
                                        </span>
                                    </FieldLabel>
                                </Field>
                            ))}
                            <FieldError>{error('scopes')}</FieldError>
                        </FieldSet>
                        <Field
                            data-invalid={Boolean(
                                error('posting_type_prefixes'),
                            )}
                        >
                            <Input
                                label="Jenis posting yang boleh dibaca"
                                placeholder="asset."
                                value={form.posting_type_prefixes}
                                onChange={(event) =>
                                    set(
                                        'posting_type_prefixes',
                                        event.target.value,
                                    )
                                }
                            />
                            <FieldDescription>
                                Awalan jenis posting, pisahkan dengan koma.
                                Kosong berarti semua jenis.
                            </FieldDescription>
                            <FieldError>
                                {error('posting_type_prefixes')}
                            </FieldError>
                        </Field>
                        <Field data-invalid={Boolean(error('allowed_ips'))}>
                            <Textarea
                                label="Alamat IP yang diizinkan"
                                rows={3}
                                placeholder="203.0.113.10"
                                value={form.allowed_ips}
                                onChange={(event) =>
                                    set('allowed_ips', event.target.value)
                                }
                            />
                            <FieldDescription>
                                Satu alamat atau rentang CIDR per baris. Kosong
                                berarti semua alamat; token tetap wajib.
                            </FieldDescription>
                            <FieldError>{error('allowed_ips')}</FieldError>
                        </Field>
                    </FieldGroup>
                </div>
                <SheetFooter className="border-t px-6 py-4 sm:flex-row sm:justify-end">
                    <Button variant="outline" type="button" onClick={onClose}>
                        Batal
                    </Button>
                    <Button type="button" disabled={saving} onClick={save}>
                        {saving ? 'Menyimpan…' : 'Simpan'}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}

function SecretValue({ label, value }: { label: string; value: string }) {
    return (
        <Field>
            <FieldLabel>{label}</FieldLabel>
            <div className="flex gap-2">
                <Input readOnly value={value} className="font-mono text-xs" />
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label={`Salin ${label.toLowerCase()}`}
                    onClick={() =>
                        navigator.clipboard
                            .writeText(value)
                            .then(() => toast.success(`${label} disalin.`))
                    }
                >
                    <Copy />
                </Button>
            </div>
        </Field>
    );
}

function SecretsDialog({
    secrets,
    endpoint,
    onClose,
}: {
    secrets: Secrets;
    endpoint: string;
    onClose: () => void;
}) {
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent size="wide">
                <DialogHeader>
                    <DialogTitle>{secrets.title}</DialogTitle>
                    <DialogDescription>
                        Salin sekarang dan simpan di aplikasi finance. Nilai ini
                        tidak akan ditampilkan lagi; bila hilang, terbitkan yang
                        baru.
                    </DialogDescription>
                </DialogHeader>
                <DialogBody className="space-y-4 py-3">
                    {secrets.token && (
                        <>
                            <SecretValue label="Token" value={secrets.token} />
                            <p className="text-sm text-muted-foreground">
                                Kirim di setiap permintaan ke{' '}
                                <span className="font-mono">{endpoint}</span>{' '}
                                sebagai header{' '}
                                <span className="font-mono">
                                    Authorization: Bearer &lt;token&gt;
                                </span>
                                .
                            </p>
                        </>
                    )}
                    {secrets.signing_secret && (
                        <>
                            <SecretValue
                                label="Rahasia penanda tangan"
                                value={secrets.signing_secret}
                            />
                            <p className="text-sm text-muted-foreground">
                                Dipakai aplikasi finance untuk memeriksa header{' '}
                                <span className="font-mono">
                                    X-CoreERP-Event-Signature
                                </span>
                                : HMAC-SHA256 atas{' '}
                                <span className="font-mono">
                                    &lt;timestamp&gt;.&lt;badan&gt;
                                </span>
                                .
                            </p>
                        </>
                    )}
                </DialogBody>
                <DialogFooter>
                    <Button type="button" onClick={onClose}>
                        Sudah disimpan
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function IntegrationClients({
    clients,
    scopes,
    endpoint,
}: Props) {
    const [editing, setEditing] = useState<Client | 'new' | null>(null);
    const [secrets, setSecrets] = useState<Secrets | null>(null);
    const [revoking, setRevoking] = useState<Client | null>(null);

    const action = async (
        client: Client,
        path: string,
        title: string,
        success: string,
    ) => {
        try {
            const result = await apiJson<{
                token?: string;
                signing_secret?: string;
            }>(`/api/v1/integration-clients/${client.id}/${path}`, {
                method: 'POST',
            });
            router.reload({ only: ['clients'] });

            if (result.token || result.signing_secret) {
                setSecrets({
                    title,
                    token: result.token ?? null,
                    signing_secret: result.signing_secret ?? null,
                });
            } else {
                toast.success(success);
            }
        } catch (caught) {
            toast.error(errorText(caught, 'Tindakan belum berhasil.'));
        }
    };

    const testPush = async (client: Client) => {
        try {
            const result = await apiJson<{
                data: { ok: boolean; message: string };
            }>(`/api/v1/integration-clients/${client.id}/test-push`, {
                method: 'POST',
            });

            if (result.data.ok) {
                toast.success(result.data.message);
            } else {
                toast.error(result.data.message);
            }
        } catch (caught) {
            toast.error(errorText(caught, 'Kirim uji belum berhasil.'));
        }
    };

    return (
        <>
            <Head title="Klien integrasi" />
            <main className="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-6 p-6">
                <Heading
                    title="Klien integrasi"
                    description="Sistem di luar CoreERP yang membaca posting finance, vendor, dan operating unit milik tenant ini."
                />
                <Card>
                    <CardHeader>
                        <CardTitle>Klien terdaftar</CardTitle>
                        <CardDescription>
                            Setiap klien memakai token sendiri dengan izin yang
                            sempit. Mencabut klien berlaku pada permintaan
                            berikutnya.
                        </CardDescription>
                        <CardAction>
                            <ActionButton
                                action="create"
                                size="sm"
                                onClick={() => setEditing('new')}
                            >
                                Tambah klien
                            </ActionButton>
                        </CardAction>
                    </CardHeader>
                    <CardContent>
                        {clients.length === 0 ? (
                            <Empty className="py-12">
                                <EmptyHeader>
                                    <EmptyTitle>Belum ada klien</EmptyTitle>
                                    <EmptyDescription>
                                        Tambahkan klien untuk aplikasi finance
                                        yang akan membaca posting dari CoreERP.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <div className="overflow-x-auto rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Nama</TableHead>
                                            <TableHead>Mode</TableHead>
                                            <TableHead>Izin</TableHead>
                                            <TableHead>Jenis posting</TableHead>
                                            <TableHead>
                                                Terakhir dipakai
                                            </TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead className="w-12">
                                                <span className="sr-only">
                                                    Aksi
                                                </span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {clients.map((client) => (
                                            <TableRow key={client.id}>
                                                <TableCell className="font-medium">
                                                    {client.name}
                                                    {client.push_url && (
                                                        <span className="block text-xs font-normal text-muted-foreground">
                                                            {client.push_url}
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {client.delivery_mode ===
                                                    'push'
                                                        ? 'Dorong'
                                                        : 'Tarik'}
                                                </TableCell>
                                                <TableCell className="font-mono text-xs">
                                                    {client.scopes.join(', ')}
                                                </TableCell>
                                                <TableCell className="font-mono text-xs">
                                                    {client
                                                        .posting_type_prefixes
                                                        .length
                                                        ? client.posting_type_prefixes.join(
                                                              ', ',
                                                          )
                                                        : 'Semua'}
                                                </TableCell>
                                                <TableCell>
                                                    {waktu(client.last_used_at)}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant={
                                                            client.status ===
                                                            'active'
                                                                ? 'secondary'
                                                                : 'outline'
                                                        }
                                                    >
                                                        {client.status ===
                                                        'active'
                                                            ? 'Aktif'
                                                            : 'Dicabut'}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    {client.status ===
                                                        'active' && (
                                                        <DropdownMenu>
                                                            <DropdownMenuTrigger
                                                                asChild
                                                            >
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    aria-label={`Aksi untuk ${client.name}`}
                                                                >
                                                                    <MoreHorizontal />
                                                                </Button>
                                                            </DropdownMenuTrigger>
                                                            <DropdownMenuContent align="end">
                                                                <DropdownMenuItem
                                                                    onSelect={() =>
                                                                        setEditing(
                                                                            client,
                                                                        )
                                                                    }
                                                                >
                                                                    Ubah
                                                                </DropdownMenuItem>
                                                                <DropdownMenuItem
                                                                    onSelect={() =>
                                                                        action(
                                                                            client,
                                                                            'rotate-token',
                                                                            `Token baru untuk ${client.name}`,
                                                                            'Token diterbitkan ulang.',
                                                                        )
                                                                    }
                                                                >
                                                                    Terbitkan
                                                                    ulang token
                                                                </DropdownMenuItem>
                                                                {client.delivery_mode ===
                                                                    'push' && (
                                                                    <>
                                                                        <DropdownMenuItem
                                                                            onSelect={() =>
                                                                                action(
                                                                                    client,
                                                                                    'rotate-signing-secret',
                                                                                    `Rahasia penanda tangan baru untuk ${client.name}`,
                                                                                    'Rahasia diganti.',
                                                                                )
                                                                            }
                                                                        >
                                                                            Ganti
                                                                            rahasia
                                                                            penanda
                                                                            tangan
                                                                        </DropdownMenuItem>
                                                                        <DropdownMenuItem
                                                                            onSelect={() =>
                                                                                testPush(
                                                                                    client,
                                                                                )
                                                                            }
                                                                        >
                                                                            Kirim
                                                                            uji
                                                                        </DropdownMenuItem>
                                                                    </>
                                                                )}
                                                                <DropdownMenuSeparator />
                                                                <DropdownMenuItem
                                                                    variant="destructive"
                                                                    onSelect={() =>
                                                                        setRevoking(
                                                                            client,
                                                                        )
                                                                    }
                                                                >
                                                                    Cabut
                                                                </DropdownMenuItem>
                                                            </DropdownMenuContent>
                                                        </DropdownMenu>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </main>
            {editing && (
                <ClientSheet
                    key={editing === 'new' ? 'new' : editing.id}
                    client={editing === 'new' ? null : editing}
                    scopes={scopes}
                    onClose={() => setEditing(null)}
                    onSecrets={setSecrets}
                />
            )}
            {secrets && (
                <SecretsDialog
                    secrets={secrets}
                    endpoint={endpoint}
                    onClose={() => setSecrets(null)}
                />
            )}
            <AlertDialog
                open={revoking !== null}
                onOpenChange={(open) => !open && setRevoking(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Cabut {revoking?.name}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Token klien ini langsung tidak berlaku, dan klien
                            yang sudah dicabut tidak dapat dihidupkan lagi.
                            Aplikasi finance yang memakainya berhenti menerima
                            posting sampai diberi klien baru.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => {
                                if (revoking) {
                                    action(
                                        revoking,
                                        'revoke',
                                        '',
                                        'Klien integrasi dicabut.',
                                    );
                                }

                                setRevoking(null);
                            }}
                        >
                            Cabut klien
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

IntegrationClients.layout = {
    breadcrumbs: [
        { title: 'Klien integrasi', href: '/settings/integration-clients' },
    ] satisfies BreadcrumbItem[],
};
