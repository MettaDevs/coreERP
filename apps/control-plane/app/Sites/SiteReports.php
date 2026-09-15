<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

use ControlPlane\Models\Site;
use ControlPlane\Models\SiteReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Menerima laporan heartbeat situs.
 *
 * ## Daftar tertutup ditegakkan di sini
 *
 * "Data yang boleh keluar dari server klien" tidak cukup dijaga kebiasaan agen. Kunci di luar skema
 * kontrak ditolak utuh, pada setiap tingkat, sehingga agen yang kelak diubah untuk mengirim sesuatu
 * yang lebih — log, nama pengguna, potongan data — gagal di sini dan terlihat, bukan diam-diam
 * tersimpan.
 *
 * ## Hanya yang berubah yang menjadi riwayat
 *
 * Laporan terakhir selalu menimpa `sites.last_report`. Baris `site_reports` hanya lahir ketika isinya
 * berbeda dari baris sebelumnya — dengan mengabaikan jam laporan dan sisa disk, yang berubah setiap
 * menit tanpa ada yang terjadi.
 */
final class SiteReports
{
    private const TOP_LEVEL = [
        'site_id', 'agent_version', 'created_at', 'server_time', 'edition', 'release', 'image', 'digest',
        'containers', 'disk', 'last_backup', 'last_operation', 'certificate_expires_at', 'license_expires_at',
        'license_required',
    ];

    /** Kunci yang berubah tanpa ada yang terjadi; tidak ikut menentukan apakah laporan "berubah". */
    private const VOLATILE = ['created_at', 'server_time', 'disk'];

    /**
     * @param  array<mixed>  $payload
     * @return array<string, mixed>
     */
    public function validate(array $payload, Site $site): array
    {
        $validator = Validator::make(['report' => $payload], [
            'report' => ['required', 'array:'.implode(',', self::TOP_LEVEL)],
            'report.site_id' => ['required', 'string', 'in:'.$site->id],
            'report.agent_version' => ['required', 'string', 'max:40'],
            'report.created_at' => ['required', 'date'],
            'report.server_time' => ['required', 'date'],
            'report.edition' => ['nullable', 'string', 'max:80'],
            'report.release' => ['nullable', 'string', 'max:40'],
            'report.image' => ['nullable', 'string', 'max:255'],
            'report.digest' => ['nullable', 'string', 'max:100'],
            'report.containers' => ['nullable', 'array', 'list', 'max:20'],
            'report.containers.*' => ['array:service,state,health'],
            'report.containers.*.service' => ['required', 'string', 'max:60'],
            'report.containers.*.state' => ['required', 'string', 'max:30'],
            'report.containers.*.health' => ['nullable', 'string', 'max:30'],
            'report.disk' => ['nullable', 'array:data_free_bytes,backup_free_bytes'],
            'report.disk.data_free_bytes' => ['nullable', 'integer', 'min:0'],
            'report.disk.backup_free_bytes' => ['nullable', 'integer', 'min:0'],
            'report.last_backup' => ['nullable', 'array:at,size_bytes,result'],
            'report.last_backup.at' => ['required_with:report.last_backup', 'date'],
            'report.last_backup.size_bytes' => ['nullable', 'integer', 'min:0'],
            'report.last_backup.result' => ['required_with:report.last_backup', 'in:succeeded,failed'],
            'report.last_operation' => ['nullable', 'array:id,result,step'],
            'report.last_operation.id' => ['required_with:report.last_operation', 'string', 'max:40'],
            'report.last_operation.result' => ['required_with:report.last_operation', 'in:succeeded,failed'],
            'report.last_operation.step' => ['nullable', 'string', 'max:120'],
            'report.certificate_expires_at' => ['nullable', 'date'],
            'report.license_expires_at' => ['nullable', 'date_format:Y-m-d'],
            // Boolean JSON sungguhan, bukan `1` atau `"true"`. Nilai ini memicu peringatan "server tidak
            // mewajibkan lisensi"; agen yang mengirim teks sedang membaca `.env` dengan cara yang salah,
            // dan menerimanya berarti peringatan itu diam-diam bergantung pada tafsiran PHP.
            'report.license_required' => ['nullable', 'boolean:strict'],
        ]);

        if ($validator->fails()) {
            throw new SiteRejected('report_invalid', 'Laporan tidak sesuai kontrak: '.$validator->errors()->first());
        }

        /** @var array<string, mixed> $report */
        $report = $payload;

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report  laporan yang sudah lolos `validate()`
     * @param  ?string  $ip  alamat asal permintaan agen; di belakang Traefik ia terbaca benar karena proxy-nya
     *                       dipercaya (`COREERP_TRUSTED_PROXIES`)
     */
    public function record(Site $site, array $report, ?string $ip = null): void
    {
        $comparable = array_diff_key($report, array_flip(self::VOLATILE));
        ksort($comparable);
        $hash = hash('sha256', (string) json_encode($comparable, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $reportedAt = Carbon::parse((string) $report['created_at']);

        DB::transaction(function () use ($site, $report, $hash, $reportedAt, $ip): void {
            $site->forceFill([
                'reported_edition' => $report['edition'] ?? null,
                'reported_release' => $report['release'] ?? null,
                'reported_digest' => $report['digest'] ?? null,
                'last_seen_at' => now(),
                // Hanya bila alamatnya sah. Kolomnya 45 karakter — panjang IPv6 terpanjang — dan nilai
                // lain dari header yang rusak tidak boleh menggagalkan laporan yang sudah sah.
                'last_seen_ip' => filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : $site->last_seen_ip,
                'last_report' => $report,
            ])->save();

            $previous = SiteReport::query()
                ->where('site_id', $site->id)
                ->orderByDesc('received_at')
                ->value('payload_hash');

            if ($previous === $hash) {
                return;
            }

            SiteReport::query()->create([
                'site_id' => $site->id,
                'payload' => $report,
                'payload_hash' => $hash,
                'reported_at' => $reportedAt,
                'received_at' => now(),
            ]);
        });
    }
}
