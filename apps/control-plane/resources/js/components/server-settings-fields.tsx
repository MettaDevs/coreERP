import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';

export type ServerSettingsKey =
    'server_address' | 'update_window_start' | 'update_window_end';

export type ServerSettingsData = Record<ServerSettingsKey, string>;

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
        <div className="space-y-3">
            <FormControl
                error={error}
                hint="Isi IP publik atau nama host, tanpa https://, port, atau garis miring. Alamat ini dipakai untuk DNS aplikasi."
            >
                <Input
                    id="server_address"
                    label="Alamat server"
                    value={value}
                    autoComplete="off"
                    spellCheck={false}
                    className="font-mono"
                    onChange={(e) => onChange(e.target.value)}
                />
            </FormControl>
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
            <FormGrid>
                <FormControl error={errors.update_window_start}>
                    <Input
                        id="update_window_start"
                        label="Mulai pembaruan paling awal"
                        type="time"
                        value={data.update_window_start}
                        onChange={(e) =>
                            setData('update_window_start', e.target.value)
                        }
                    />
                </FormControl>
                <FormControl error={errors.update_window_end}>
                    <Input
                        id="update_window_end"
                        label="Mulai pembaruan paling lambat"
                        type="time"
                        value={data.update_window_end}
                        onChange={(e) =>
                            setData('update_window_end', e.target.value)
                        }
                    />
                </FormControl>
            </FormGrid>
            <p className="text-xs text-muted-foreground">
                Batas waktu agen mulai mengambil pembaruan, waktu Jakarta.
                Proses yang sudah berjalan dapat selesai setelah batas ini.
                Kosongkan keduanya bila pembaruan boleh kapan saja. Pemasangan
                pertama tidak menunggu jendela ini.
            </p>
        </div>
    );
}
