import { Checkbox } from '@apperp/ui/checkbox';
import { FormControl } from '@apperp/ui/form-control';
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
        <div className="space-y-5 rounded-md border bg-muted/20 p-4">
            <p className="text-sm font-medium">Pilihan pemasangan</p>

            <label className="flex cursor-pointer items-start gap-3">
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

            <label className="flex cursor-pointer items-start gap-3">
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

            <FormControl
                className="max-w-sm"
                error={portError}
                hint="Kosongkan untuk memakai port 8000. Pilih port lain bila 8000 sudah digunakan di server klien."
            >
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
            </FormControl>
        </div>
    );
}
