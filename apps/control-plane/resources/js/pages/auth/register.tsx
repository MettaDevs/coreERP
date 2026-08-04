import { Head, useForm } from '@inertiajs/react';
import { Building2, KeyRound, Package } from 'lucide-react';
import { useState } from 'react';

import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Alert, AlertDescription, AlertTitle } from '@apperp/ui/alert';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLegend,
    FieldSet,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Spinner } from '@apperp/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@apperp/ui/tabs';
import { ToggleGroup, ToggleGroupItem } from '@apperp/ui/toggle-group';

type AppOption = { id: string; name: string; description: string };
type Props = { passwordRules: string; apps: AppOption[] };
type Step = 'business' | 'products' | 'security';

export default function Register({ passwordRules, apps }: Props) {
    const [step, setStep] = useState<Step>('business');
    const form = useForm({
        name: '',
        business_name: '',
        email: '',
        app_ids: [] as string[],
        password: '',
        password_confirmation: '',
    });
    const businessComplete =
        form.data.name.trim() !== '' &&
        form.data.business_name.trim() !== '' &&
        form.data.email.trim() !== '';
    const productsComplete = form.data.app_ids.length > 0;

    const submit = () => {
        form.post('/register', {
            onError: (errors) => {
                if (errors.name || errors.business_name || errors.email) {
                    setStep('business');
                } else if (errors.app_ids) {
                    setStep('products');
                } else {
                    setStep('security');
                }
            },
        });
    };

    return (
        <>
            <Head title="Pendaftaran bisnis" />
            <Tabs
                value={step}
                onValueChange={(value) => setStep(value as Step)}
            >
                <TabsList className="grid w-full grid-cols-3">
                    <TabsTrigger value="business">1. Bisnis</TabsTrigger>
                    <TabsTrigger value="products" disabled={!businessComplete}>
                        2. Produk
                    </TabsTrigger>
                    <TabsTrigger
                        value="security"
                        disabled={!businessComplete || !productsComplete}
                    >
                        3. Keamanan
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="business">
                    <Card>
                        <CardHeader>
                            <CardTitle>Pemilik dan bisnis</CardTitle>
                            <CardDescription>
                                Isi identitas pemilik akun dan nama bisnis.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <FieldGroup>
                                <Field data-invalid={Boolean(form.errors.name)}>
                                    <Input
                                        label="Nama pemilik"
                                        value={form.data.name}
                                        onChange={(event) =>
                                            form.setData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={Boolean(form.errors.name)}
                                        autoComplete="name"
                                        autoFocus
                                    />
                                    <FieldError>{form.errors.name}</FieldError>
                                </Field>
                                <Field
                                    data-invalid={Boolean(
                                        form.errors.business_name,
                                    )}
                                >
                                    <Input
                                        label="Nama bisnis"
                                        value={form.data.business_name}
                                        onChange={(event) =>
                                            form.setData(
                                                'business_name',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={Boolean(
                                            form.errors.business_name,
                                        )}
                                        autoComplete="organization"
                                    />
                                    <FieldError>
                                        {form.errors.business_name}
                                    </FieldError>
                                </Field>
                                <Field
                                    data-invalid={Boolean(form.errors.email)}
                                >
                                    <Input
                                        label="Email pemilik"
                                        type="email"
                                        value={form.data.email}
                                        onChange={(event) =>
                                            form.setData(
                                                'email',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={Boolean(
                                            form.errors.email,
                                        )}
                                        autoComplete="email"
                                    />
                                    <FieldError>{form.errors.email}</FieldError>
                                </Field>
                            </FieldGroup>
                        </CardContent>
                        <CardFooter className="justify-end">
                            <Button
                                type="button"
                                disabled={!businessComplete}
                                onClick={() => setStep('products')}
                            >
                                Lanjutkan
                            </Button>
                        </CardFooter>
                    </Card>
                </TabsContent>

                <TabsContent value="products">
                    <Card>
                        <CardHeader>
                            <CardTitle>Produk awal</CardTitle>
                            <CardDescription>
                                Struktur perusahaan diatur setelah pendaftaran,
                                sesuai keadaan bisnis yang sebenarnya.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <FieldSet
                                data-invalid={Boolean(form.errors.app_ids)}
                            >
                                <FieldLegend hint="Produk yang tidak dipilih belum dapat digunakan oleh bisnis Anda. Proses pemasangannya berlangsung terpisah.">
                                    Pilihan produk
                                </FieldLegend>
                                <ToggleGroup
                                    type="multiple"
                                    variant="outline"
                                    spacing={2}
                                    className="grid w-full gap-3 md:grid-cols-2"
                                    value={form.data.app_ids}
                                    onValueChange={(values) =>
                                        form.setData('app_ids', values)
                                    }
                                >
                                    {apps.map((app) => (
                                        <ToggleGroupItem
                                            key={app.id}
                                            value={app.id}
                                            className="h-auto min-h-24 w-full items-start justify-start p-4 text-left whitespace-normal"
                                        >
                                            <Package />
                                            <span className="flex flex-col gap-1">
                                                <span className="font-medium">
                                                    {app.name}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {app.description}
                                                </span>
                                            </span>
                                        </ToggleGroupItem>
                                    ))}
                                </ToggleGroup>
                                <FieldError>{form.errors.app_ids}</FieldError>
                            </FieldSet>
                        </CardContent>
                        <CardFooter className="justify-between">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setStep('business')}
                            >
                                Kembali
                            </Button>
                            <Button
                                type="button"
                                disabled={!productsComplete}
                                onClick={() => setStep('security')}
                            >
                                Lanjutkan
                            </Button>
                        </CardFooter>
                    </Card>
                </TabsContent>

                <TabsContent value="security">
                    <Card>
                        <CardHeader>
                            <CardTitle>Keamanan dan konfirmasi</CardTitle>
                            <CardDescription>
                                Buat kata sandi untuk akun pemilik bisnis.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <FieldGroup>
                                <Alert>
                                    <Building2 />
                                    <AlertTitle>
                                        {form.data.business_name}
                                    </AlertTitle>
                                    <AlertDescription>
                                        {form.data.app_ids.length} produk
                                        dipilih. Legal entity dan unit
                                        operasional dibuat setelah tenant aktif.
                                    </AlertDescription>
                                </Alert>
                                <Field
                                    data-invalid={Boolean(form.errors.password)}
                                >
                                    <PasswordInput
                                        label="Kata sandi"
                                        value={form.data.password}
                                        onChange={(event) =>
                                            form.setData(
                                                'password',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={Boolean(
                                            form.errors.password,
                                        )}
                                        autoComplete="new-password"
                                        passwordrules={passwordRules}
                                    />
                                    <FieldError>
                                        {form.errors.password}
                                    </FieldError>
                                </Field>
                                <Field
                                    data-invalid={Boolean(
                                        form.errors.password_confirmation,
                                    )}
                                >
                                    <PasswordInput
                                        label="Konfirmasi kata sandi"
                                        value={form.data.password_confirmation}
                                        onChange={(event) =>
                                            form.setData(
                                                'password_confirmation',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={Boolean(
                                            form.errors.password_confirmation,
                                        )}
                                        autoComplete="new-password"
                                        passwordrules={passwordRules}
                                    />
                                    <FieldError>
                                        {form.errors.password_confirmation}
                                    </FieldError>
                                </Field>
                            </FieldGroup>
                        </CardContent>
                        <CardFooter className="justify-between">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setStep('products')}
                            >
                                Kembali
                            </Button>
                            <Button
                                type="button"
                                disabled={
                                    form.processing ||
                                    form.data.password === '' ||
                                    form.data.password_confirmation === ''
                                }
                                onClick={submit}
                            >
                                {form.processing ? <Spinner /> : <KeyRound />}
                                Buat tenant
                            </Button>
                        </CardFooter>
                    </Card>
                </TabsContent>
            </Tabs>

            <p className="text-center text-sm text-muted-foreground">
                Punya kode akses?{' '}
                <TextLink href="/join">Daftar sebagai anggota</TextLink>
                <span className="mx-2">·</span>
                Sudah punya akun? <TextLink href="/login">Masuk</TextLink>
            </p>
        </>
    );
}

Register.layout = {
    title: 'Pendaftaran bisnis',
    description: 'Isi data pemilik akun dan pilih produk untuk bisnis Anda.',
};
