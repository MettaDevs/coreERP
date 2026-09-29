import { Checkbox } from '@apperp/ui/checkbox';
import { Input } from '@apperp/ui/input';

export type InstallOptionValues = {
    kunci_lisensi: boolean;
    proxy_luar: boolean;
    app_port: string;
};

/**
 * Tiga pilihan yang dulu ditambahkan tangan ke perintah pasang.
 *
 * Bentuknya sengaja kotak yang selalu terlihat, bukan bagian yang harus dibuka: yang memilih salah
 * di sini baru mengetahuinya sesudah pemasangan gagal di server orang lain. Kalimat di bawah tiap
 * pilihan menyebut akibatnya, bukan nama tandanya.
 */
export default function InstallOptionsFields({
    value,
    onChange,
    portError,
    disabled,
}: {
    value: InstallOptionValues;
    onChange: (value: InstallOptionValues) => void;
    portError?: string;
    disabled: boolean;
}) {
    return (
        <div className="space-y-3 rounded-md border border-dashed p-4">
            <p className="text-sm font-medium">Pilihan pemasangan</p>

            <label className="flex items-start gap-3">
                <Checkbox
                    checked={value.kunci_lisensi}
                    onCheckedChange={(checked) =>
                        onChange({ ...value, kunci_lisensi: checked === true })
                    }
                    disabled={disabled}
                    className="mt-0.5"
                />
                <span className="text-sm">
                    Kunci aplikasi saat lisensi habis
                    <span className="mt-0.5 block text-xs text-muted-foreground">
                        Tanpa ini lisensi tetap diterbitkan dan diperpanjang,
                        tetapi tidak pernah menutup aplikasi. Nyalakan sesudah
                        penerbitan lisensi terbukti berjalan untuk server ini.
                    </span>
                </span>
            </label>

            <label className="flex items-start gap-3">
                <Checkbox
                    checked={value.proxy_luar}
                    onCheckedChange={(checked) =>
                        onChange({ ...value, proxy_luar: checked === true })
                    }
                    disabled={disabled}
                    className="mt-0.5"
                />
                <span className="text-sm">
                    Server ini sudah punya reverse proxy sendiri
                    <span className="mt-0.5 block text-xs text-muted-foreground">
                        Agen tidak memasang proxy HTTPS dan tidak menyentuh port
                        80 dan 443. Reverse proxy di sana yang diarahkan ke port
                        aplikasi — termasuk sertifikatnya.
                    </span>
                </span>
            </label>

            <div className="max-w-xs">
                <Input
                    label="Port aplikasi"
                    inputMode="numeric"
                    placeholder="8000"
                    value={value.app_port}
                    disabled={disabled}
                    onChange={(e) =>
                        onChange({ ...value, app_port: e.target.value })
                    }
                />
                <p className="mt-1 text-xs text-muted-foreground">
                    Kosongkan untuk 8000. Isi hanya bila port itu sudah dipakai
                    di server klien.
                </p>
                {portError && (
                    <p className="mt-1 text-xs text-destructive">{portError}</p>
                )}
            </div>
        </div>
    );
}
