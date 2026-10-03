/**
 * Sentry di peramban konsol: kesalahan JavaScript dan sesi operator, yang menjadi Crash Free Sessions dan
 * Crash Free Users per rilis di project `coreerp-konsol`. Setelannya dari meta di
 * `resources/views/app.blade.php`; tanpa meta `sentry-dsn` tidak ada yang dipasang. Operator hanya id-nya.
 * SDK-nya dimuat terpisah dan hanya bila DSN ada, supaya bundel utama tidak membayarnya.
 */
export function installSentry(): void {
    const dsn = meta('sentry-dsn');

    if (dsn === '') {
        return;
    }

    const userId = meta('sentry-user');

    void import('@sentry/react').then((Sentry) => {
        Sentry.init({
            dsn,
            release: meta('sentry-release') || undefined,
            environment: meta('sentry-environment') || undefined,
            sendDefaultPii: false,
            initialScope: userId === '' ? undefined : { user: { id: userId } },
        });
    });
}

function meta(name: string): string {
    return (
        document
            .querySelector(`meta[name="${name}"]`)
            ?.getAttribute('content')
            ?.trim() ?? ''
    );
}
