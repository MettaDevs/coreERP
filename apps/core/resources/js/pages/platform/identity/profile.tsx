import { useInertiaFeedback } from '@/hooks/use-inertia-feedback';
import { FormErrorHeader } from '@/components/form-error-header';
import { ActionButton } from '@apperp/ui/action-button';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Button } from '@apperp/ui/button';
import { Card, CardHeader, CardTitle, CardContent } from '@apperp/ui/card';
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
import AppearanceTabs from '@/components/appearance-tabs';
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
    const { wrap: wrapFormRequest, summary: formRequestSummary } =
        useInertiaFeedback();

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
                ? `Ikuti perusahaan (${legalEntityZone.label})`
                : 'Ikuti perusahaan',
        },
        ...dateTime.timezones,
    ];

    return (
        <Card>
            <CardHeader>
                <CardTitle>Tanggal dan waktu</CardTitle>
            </CardHeader>
            <CardContent>
                <form
                    noValidate
                    className="space-y-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.patch(
                            '/settings/date-time',
                            wrapFormRequest({
                                preserveScroll: true,
                            }),
                        );
                    }}
                >
                    <RecordActionBar>
                        <ActionButton
                            action="save"
                            type="submit"
                            disabled={form.processing}
                        />
                    </RecordActionBar>
                    <>
                        {formRequestSummary}
                        <FormErrorHeader errors={form.errors} />
                    </>

                    <div className="grid gap-2" data-error-field={'timezone'}>
                        <Select
                            label="Zona waktu"
                            items={zones}
                            value={form.data.timezone ?? FOLLOW_LEGAL_ENTITY}
                            onValueChange={(value) =>
                                form.setData(
                                    'timezone',
                                    value === null ||
                                        value === FOLLOW_LEGAL_ENTITY
                                        ? null
                                        : value,
                                )
                            }
                            searchPlaceholder="Cari zona waktu..."
                            emptyMessage="Zona waktu tidak ditemukan."
                        />
                        <InputError message={form.errors.timezone} />
                    </div>

                    <div className="grid gap-2" data-error-field={'work_date'}>
                        <div className="flex items-center gap-2">
                            <Input
                                id="work_date"
                                type="date"
                                label="Tanggal kerja"
                                className="block w-full"
                                value={form.data.work_date}
                                onChange={(event) =>
                                    form.setData(
                                        'work_date',
                                        event.target.value,
                                    )
                                }
                            />
                            <FieldHint hint="Tanggal bawaan untuk transaksi baru selama kamu masuk. Kembali ke hari ini saat kamu masuk lagi atau pindah ruang kerja atau perusahaan.">
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
                                    onClick={() =>
                                        form.setData('work_date', today)
                                    }
                                >
                                    Pakai hari ini
                                </Button>
                            </div>
                        )}
                        <InputError message={form.errors.work_date} />
                    </div>
                </form>
            </CardContent>
        </Card>
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
    const { wrap: wrapFormResponse1, summary: formResponse1Summary } =
        useInertiaFeedback();

    const { auth } = usePage<PageProps>().props;

    return (
        <>
            <Head title="Profil" />

            <main className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 sm:p-6">
                <h1 className="text-xl font-semibold">Profil</h1>

                <Card>
                    <CardHeader>
                        <CardTitle>Informasi pribadi</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Form
                            noValidate
                            {...ProfileController.update.form()}
                            options={{
                                preserveScroll: true,
                            }}
                            className="space-y-6"
                            {...wrapFormResponse1({})}
                        >
                            {({ processing, errors }) => (
                                <>
                                    <RecordActionBar>
                                        <ActionButton
                                            action="save"
                                            disabled={processing}
                                            data-test="update-profile-button"
                                            type="submit"
                                        />
                                    </RecordActionBar>
                                    <>
                                        {formResponse1Summary}
                                        <FormErrorHeader errors={errors} />
                                    </>

                                    <div
                                        className="grid gap-2"
                                        data-error-field={'name'}
                                    >
                                        <Input
                                            id="name"
                                            label="Nama lengkap"
                                            className="block w-full"
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

                                    <div
                                        className="grid gap-2"
                                        data-error-field={'email'}
                                    >
                                        <Input
                                            id="email"
                                            label="Email"
                                            type="email"
                                            className="block w-full"
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
                                        auth.user.email_verified_at ===
                                            null && (
                                            <div>
                                                <p className="-mt-4 text-sm text-muted-foreground">
                                                    Email Anda belum
                                                    diverifikasi.{' '}
                                                    <Link
                                                        href={send()}
                                                        as="button"
                                                        className="text-primary underline underline-offset-4"
                                                    >
                                                        Kirim ulang email
                                                        verifikasi.
                                                    </Link>
                                                </p>

                                                {status ===
                                                    'verification-link-sent' && (
                                                    <div className="mt-2 text-sm text-success">
                                                        Tautan verifikasi sudah
                                                        dikirim ke email Anda.
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    {/* @end-chisel-email-verification */}
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>

                <DateTimeSettings dateTime={dateTime} />

                <Card id="appearance">
                    <CardHeader>
                        <CardTitle>Tampilan</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <AppearanceTabs />
                        <p className="text-sm text-muted-foreground">
                            Tema langsung diterapkan dan disimpan di perangkat
                            ini.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent>
                        <DeleteUser />
                    </CardContent>
                </Card>
            </main>
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Profil',
            href: edit(),
        },
    ],
};
