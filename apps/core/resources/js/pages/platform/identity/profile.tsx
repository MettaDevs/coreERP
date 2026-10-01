import { Button } from '@apperp/ui/button';
import { FieldHint } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { Form, Head, useForm, usePage } from '@inertiajs/react';
import { CircleHelp } from 'lucide-react';
/* @chisel-email-verification */
import { Link } from '@inertiajs/react';
/* @end-chisel-email-verification */
import ProfileController from '@/actions/App/Platform/Identity/Http/Controllers/ProfileController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { useWorkDate } from '@/hooks/use-work-date';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';
/* @chisel-email-verification */
/* @end-chisel-email-verification */

type PageProps = {
    auth: Auth;
};

type DateTimeProps = {
    timezone: string | null;
    legal_entity_timezone: string | null;
    timezones: { value: string; label: string }[];
};

/** Nilai pilihan "ikuti entitas legal"; disimpan sebagai zona kosong. */
const FOLLOW_LEGAL_ENTITY = 'ikuti-entitas-legal';

function DateTimeSettings({ dateTime }: { dateTime: DateTimeProps }) {
    const { date: workDate, today } = useWorkDate();
    const form = useForm<{ timezone: string | null; work_date: string }>({
        timezone: dateTime.timezone,
        work_date: workDate,
    });
    const legalEntityZone = dateTime.timezones.find(
        (zone) => zone.value === dateTime.legal_entity_timezone,
    );
    const zones = [
        {
            value: FOLLOW_LEGAL_ENTITY,
            label: legalEntityZone
                ? `Ikuti entitas legal (${legalEntityZone.label})`
                : 'Ikuti entitas legal',
        },
        ...dateTime.timezones,
    ];

    return (
        <div className="flex flex-col gap-6">
            <Heading variant="small" title="Tanggal dan waktu" />

            <form
                className="space-y-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch('/settings/date-time', { preserveScroll: true });
                }}
            >
                <div className="grid gap-2">
                    <Select
                        label="Zona waktu"
                        items={zones}
                        value={form.data.timezone ?? FOLLOW_LEGAL_ENTITY}
                        onValueChange={(value) =>
                            form.setData(
                                'timezone',
                                value === null || value === FOLLOW_LEGAL_ENTITY
                                    ? null
                                    : value,
                            )
                        }
                        searchPlaceholder="Cari zona waktu..."
                        emptyMessage="Zona waktu tidak ditemukan."
                    />
                    <InputError message={form.errors.timezone} />
                </div>

                <div className="grid gap-2">
                    <div className="flex items-center gap-2">
                        <Input
                            id="work_date"
                            type="date"
                            label="Tanggal kerja"
                            className="block w-full"
                            value={form.data.work_date}
                            onChange={(event) =>
                                form.setData('work_date', event.target.value)
                            }
                        />
                        <FieldHint hint="Tanggal bawaan untuk transaksi baru selama kamu masuk. Kembali ke hari ini saat kamu masuk lagi atau pindah tenant atau entitas legal.">
                            <button
                                type="button"
                                aria-label="Bantuan tanggal kerja"
                                className="text-muted-foreground"
                            >
                                <CircleHelp className="size-4" />
                            </button>
                        </FieldHint>
                    </div>
                    {form.data.work_date !== today && (
                        <div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => form.setData('work_date', today)}
                            >
                                Pakai hari ini
                            </Button>
                        </div>
                    )}
                    <InputError message={form.errors.work_date} />
                </div>

                <Button type="submit" disabled={form.processing}>
                    Simpan
                </Button>
            </form>
        </div>
    );
}

export default function Profile(
    /* @chisel-email-verification */
    {
        mustVerifyEmail,
        status,
        dateTime,
    }: {
        mustVerifyEmail: boolean;
        status?: string;
        dateTime: DateTimeProps;
    },
    /* @end-chisel-email-verification */
) {
    const { auth } = usePage<PageProps>().props;

    return (
        <>
            <Head title="Profile settings" />

            <main className="mx-auto flex w-full max-w-3xl flex-col gap-12 p-6">
                <h1 className="sr-only">Profile settings</h1>

                <div className="flex flex-col gap-6">
                    <Heading
                        variant="small"
                        title="Profile"
                        description="Update your name and email address"
                    />

                    <Form
                        {...ProfileController.update.form()}
                        options={{
                            preserveScroll: true,
                        }}
                        className="space-y-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Input
                                        id="name"
                                        label="Name"
                                        className="mt-1 block w-full"
                                        defaultValue={auth.user.name}
                                        name="name"
                                        required
                                        autoComplete="name"
                                    />

                                    <InputError
                                        className="mt-2"
                                        message={errors.name}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Input
                                        id="email"
                                        label="Email address"
                                        type="email"
                                        className="mt-1 block w-full"
                                        defaultValue={auth.user.email}
                                        name="email"
                                        required
                                        autoComplete="username"
                                    />

                                    <InputError
                                        className="mt-2"
                                        message={errors.email}
                                    />
                                </div>

                                {/* @chisel-email-verification */}
                                {mustVerifyEmail &&
                                    auth.user.email_verified_at === null && (
                                        <div>
                                            <p className="-mt-4 text-sm text-muted-foreground">
                                                Your email address is
                                                unverified.{' '}
                                                <Link
                                                    href={send()}
                                                    as="button"
                                                    className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                                >
                                                    Click here to re-send the
                                                    verification email.
                                                </Link>
                                            </p>

                                            {status ===
                                                'verification-link-sent' && (
                                                <div className="mt-2 text-sm font-medium text-green-600">
                                                    A new verification link has
                                                    been sent to your email
                                                    address.
                                                </div>
                                            )}
                                        </div>
                                    )}
                                {/* @end-chisel-email-verification */}

                                <div className="flex items-center gap-4">
                                    <Button
                                        disabled={processing}
                                        data-test="update-profile-button"
                                    >
                                        Save
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </div>

                <DateTimeSettings dateTime={dateTime} />

                <DeleteUser />
            </main>
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Profile settings',
            href: edit(),
        },
    ],
};
