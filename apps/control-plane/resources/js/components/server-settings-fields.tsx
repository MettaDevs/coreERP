import { Input } from '@apperp/ui/input';

export type ServerSettingsKey =
    'server_address' | 'update_window_start' | 'update_window_end';

export type ServerSettingsData = Record<ServerSettingsKey, string>;

function FieldError({ message }: { message?: string }) {
    return message ? (
        <p className="text-sm text-destructive">{message}</p>
    ) : null;
}

/**
 * Alamat server — IP atau nama host mesin milik klien.
 *
 * Ditaruh terpisah dari isian lainnya karena ia yang dicari operator saat server klien bermasalah, dan
 * karena itu tidak disembunyikan di bawah "Lanjutan". Aturannya diperiksa server (`ServerAddress`); layar
 * hanya menyebut bentuk yang diterima sebelum orang mengetik.
 */
export function ServerAddressField({
    value,
    onChange,
    error,
    lastSeenIp,
}: {
    value: string;
    onChange: (value: string) => void;
    error?: string;
    lastSeenIp?: string | null;
}) {
    return (
        <div className="space-y-2">
            <Input
                id="server_address"
                label="Alamat server (IP atau nama host VPS)"
                value={value}
                autoComplete="off"
                spellCheck={false}
                className="font-mono"
                onChange={(e) => onChange(e.target.value)}
            />
            <p className="text-xs text-muted-foreground">
                Mesin tempat server klien berjalan, misalnya{' '}
                <span className="font-mono">103.122.2.72</span> atau{' '}
                <span className="font-mono">vps1.klinik.id</span>. Tanpa
                https://, port, atau garis miring. Boleh diisi belakangan.
            </p>
            {lastSeenIp && value !== lastSeenIp && (
                <p className="text-xs text-muted-foreground">
                    Agen terakhir melapor dari{' '}
                    <span className="font-mono">{lastSeenIp}</span>.{' '}
                    <button
                        type="button"
                        className="font-medium text-foreground underline underline-offset-4"
                        onClick={() => onChange(lastSeenIp)}
                    >
                        Pakai alamat ini
                    </button>
                    <span>
                        {' '}
                        — periksa dulu: server di belakang NAT melapor dari IP
                        gerbangnya.
                    </span>
                </p>
            )}
            <FieldError message={error} />
        </div>
    );
}

/**
 * Jendela pembaruan — diisi sekali dan jarang diubah.
 *
 * Alamat aplikasi sengaja tidak ada di sini: ia diturunkan dari lingkungannya dan tidak dapat diganti operator.
 */
export function ServerAdvancedFields({
    data,
    setData,
    errors,
}: {
    data: ServerSettingsData;
    setData: (key: ServerSettingsKey, value: string) => void;
    errors: Partial<Record<ServerSettingsKey, string>>;
}) {
    return (
        <div className="space-y-3">
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-2">
                    <Input
                        id="update_window_start"
                        label="Jendela pembaruan mulai"
                        type="time"
                        value={data.update_window_start}
                        onChange={(e) =>
                            setData('update_window_start', e.target.value)
                        }
                    />
                    <FieldError message={errors.update_window_start} />
                </div>
                <div className="space-y-2">
                    <Input
                        id="update_window_end"
                        label="Jendela pembaruan selesai"
                        type="time"
                        value={data.update_window_end}
                        onChange={(e) =>
                            setData('update_window_end', e.target.value)
                        }
                    />
                    <FieldError message={errors.update_window_end} />
                </div>
            </div>
            <p className="text-xs text-muted-foreground">
                Jam pembaruan yang disepakati dengan klien, waktu Jakarta.
                Kosongkan keduanya bila pembaruan boleh kapan saja. Pemasangan
                pertama tidak menunggu jendela ini.
            </p>
        </div>
    );
}
