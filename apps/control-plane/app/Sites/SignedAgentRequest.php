<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

use ControlPlane\Models\Site;
use Illuminate\Http\Request;

/**
 * Memeriksa tanda tangan permintaan agen: bagian kecil HTTP Message Signatures (RFC 9421).
 *
 * Bentuk yang diterima ditulis di `contracts/openapi-agent.yaml`, dan hanya bentuk itu. Label selain
 * `sig1`, komponen dalam urutan lain, atau parameter tambahan ditolak — bukan dicoba ditafsirkan.
 * Pengurai tanda tangan yang murah hati adalah tempat klasik lahirnya celah yang membuat dua pihak
 * sepakat sebuah tanda tangan sah atas isi yang berbeda.
 *
 * Yang diperiksa, berurutan:
 *
 * 1. `Signature-Input` persis berbentuk yang dikontrakkan, dengan `alg` `rsa-v1_5-sha256`.
 * 2. `created` berselisih paling banyak `sites.signature_skew_seconds` dari jam konsol ini.
 * 3. `Content-Digest` sama dengan SHA-256 isi permintaan yang benar-benar diterima.
 * 4. `keyid` menunjuk situs yang terdaftar dan belum dicabut.
 * 5. Tanda tangan atas signature base sah terhadap kunci publik situs itu.
 *
 * Batas yang diketahui dan diterima: permintaan yang tercegat dapat diputar ulang di dalam batas
 * selisih jam. TLS menjaga dari pencegatan itu, dan setiap endpoint agen dirancang aman bila
 * dipanggil dua kali — laporan menimpa laporan, dan langkah operasi yang sama tidak mengubah apa pun.
 */
final class SignedAgentRequest
{
    private const LABEL_PATTERN = '/^sig1=(\("@method" "@path" "content-digest"\)((?:;[a-z]+=(?:"[^"\\\\]*"|[0-9]+))+))$/';

    private const SIGNATURE_PATTERN = '/^sig1=:([A-Za-z0-9+\/]+={0,2}):$/';

    public const ALGORITHM = 'rsa-v1_5-sha256';

    public function verify(Request $request): Site
    {
        $input = trim((string) $request->header('Signature-Input'));
        $signature = trim((string) $request->header('Signature'));
        $digest = trim((string) $request->header('Content-Digest'));

        if ($input === '' || $signature === '' || $digest === '') {
            throw new SignatureInvalid('Header tanda tangan tidak lengkap.');
        }

        if (preg_match(self::LABEL_PATTERN, $input, $label) !== 1) {
            throw new SignatureInvalid('Signature-Input tidak berbentuk yang dikontrakkan.');
        }

        $parameters = $this->parameters($label[2]);

        if (array_keys($parameters) !== ['created', 'keyid', 'alg']) {
            throw new SignatureInvalid('Parameter tanda tangan harus tepat created, keyid, alg, berurutan.');
        }

        if ($parameters['alg'] !== self::ALGORITHM) {
            throw new SignatureInvalid('Algoritma tanda tangan tidak didukung.');
        }

        $created = (int) $parameters['created'];

        if (abs(now()->getTimestamp() - $created) > (int) config('sites.signature_skew_seconds')) {
            throw new SignatureInvalid('created di luar batas selisih jam.');
        }

        $expectedDigest = 'sha-256=:'.base64_encode(hash('sha256', $request->getContent(), true)).':';

        if (! hash_equals($expectedDigest, $digest)) {
            throw new SignatureInvalid('Content-Digest tidak cocok dengan isi permintaan.');
        }

        if (preg_match(self::SIGNATURE_PATTERN, $signature, $value) !== 1) {
            throw new SignatureInvalid('Header Signature tidak berbentuk yang dikontrakkan.');
        }

        $raw = base64_decode($value[1], true);

        if ($raw === false) {
            throw new SignatureInvalid('Tanda tangan bukan base64.');
        }

        $site = Site::query()->find($parameters['keyid']);

        if (! $site instanceof Site || ! $site->enrolled() || $site->revoked()) {
            throw new SignatureInvalid('keyid tidak menunjuk situs yang terdaftar dan aktif.');
        }

        $base = self::signatureBase($request->getMethod(), $request->getBaseUrl().$request->getPathInfo(), $digest, $label[1]);

        if (openssl_verify($base, $raw, (string) $site->public_key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new SignatureInvalid('Tanda tangan tidak sah terhadap kunci situs.');
        }

        return $site;
    }

    /**
     * Signature base, persis seperti yang dikontrakkan. Dipakai juga oleh test untuk menandatangani
     * permintaan — satu fungsi, supaya test tidak membuktikan kesepakatan dengan salinan yang keliru.
     */
    public static function signatureBase(string $method, string $path, string $contentDigest, string $signatureParams): string
    {
        return implode("\n", [
            '"@method": '.strtoupper($method),
            '"@path": '.$path,
            '"content-digest": '.$contentDigest,
            '"@signature-params": '.$signatureParams,
        ]);
    }

    /** @return array<string, string> */
    private function parameters(string $serialized): array
    {
        $parameters = [];

        foreach (array_filter(explode(';', $serialized)) as $pair) {
            [$name, $value] = explode('=', $pair, 2);

            if (array_key_exists($name, $parameters)) {
                throw new SignatureInvalid('Parameter tanda tangan berulang.');
            }

            $parameters[$name] = trim($value, '"');
        }

        return $parameters;
    }
}
