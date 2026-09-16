<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature\Sites;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Cloudflare tiruan yang menyimpan keadaan: record yang dibuat benar-benar ada pada permintaan berikutnya,
 * ditimpa lewat id, dan hilang saat dihapus.
 *
 * Jawaban tetap tidak cukup di sini. `SiteDns` membaca record yang ada sebelum menulis, jadi test yang memalsukan
 * jawaban satu per satu hanya membuktikan kode memanggil alamat yang diharapkannya — bukan bahwa urutan baca,
 * tulis, dan hapusnya menghasilkan keadaan yang benar. Bentuk permintaan dan jawabannya mengikuti dokumentasi API
 * Cloudflare yang sama dengan `CloudflareClient`; bila salah satunya bergeser, test ini ikut salah, jadi
 * keduanya dicocokkan ulang dengan dokumentasi, bukan satu terhadap yang lain.
 */
final class FakeCloudflare
{
    public const API = 'https://cf.uji/client/v4';

    public const ZONE_ID = 'zona-grenery-uji';

    /** @var array<string, array{id: string, name: string, type: string, content: string, proxied: bool, ttl: int, comment: ?string}> */
    public array $records = [];

    /** @var list<string> metode dan jalur setiap permintaan, untuk memeriksa apa yang tidak boleh terjadi */
    public array $calls = [];

    public ?int $failWith = null;

    public function __construct(public string $zoneName = 'contoh.test', public ?string $token = 'token-uji')
    {
        Http::fake([self::API.'/*' => fn (Request $request) => $this->handle($request)]);
    }

    /** Record milik orang lain, seperti yang dibuat dengan tangan di dasbor Cloudflare. */
    public function foreignRecord(string $name, string $type = 'A', string $content = '198.51.100.7'): void
    {
        $id = 'tangan-'.Str::lower(Str::random(8));
        $this->records[$id] = ['id' => $id, 'name' => $name, 'type' => $type, 'content' => $content, 'proxied' => true, 'ttl' => 1, 'comment' => null];
    }

    /** @return list<array{id: string, name: string, type: string, content: string, proxied: bool, ttl: int, comment: ?string}> */
    public function named(string $name): array
    {
        return array_values(array_filter($this->records, fn (array $record): bool => $record['name'] === $name));
    }

    private function handle(Request $request): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $path = substr($path, strlen((string) parse_url(self::API, PHP_URL_PATH)));
        $this->calls[] = $request->method().' '.$path;

        if ($this->failWith !== null) {
            return Http::response(['success' => false, 'errors' => [['code' => 1000, 'message' => 'gangguan uji']], 'result' => null], $this->failWith);
        }

        if ($request->header('Authorization') !== ['Bearer '.$this->token]) {
            return Http::response(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]], 403);
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if ($request->method() === 'GET' && $path === '/zones') {
            $zones = ($query['name'] ?? null) === $this->zoneName ? [['id' => self::ZONE_ID, 'name' => $this->zoneName]] : [];

            return $this->ok($zones);
        }

        $prefix = '/zones/'.self::ZONE_ID.'/dns_records';

        if (! str_starts_with($path, $prefix)) {
            return Http::response(['success' => false, 'errors' => [['code' => 7003, 'message' => 'Could not route']]], 404);
        }

        $id = trim(substr($path, strlen($prefix)), '/');
        // `parse_str` mengganti titik pada nama parameter menjadi garis bawah: `name.exact` tiba sebagai `name_exact`.
        $exact = $query['name_exact'] ?? null;

        return match (true) {
            $request->method() === 'GET' && $id === '' => $this->ok($this->named(is_string($exact) ? $exact : '')),
            $request->method() === 'POST' && $id === '' => $this->write('rec-'.Str::lower(Str::random(10)), $request->data()),
            $request->method() === 'PUT' && isset($this->records[$id]) => $this->write($id, $request->data()),
            $request->method() === 'DELETE' && isset($this->records[$id]) => $this->delete($id),
            default => Http::response(['success' => false, 'errors' => [['code' => 81044, 'message' => 'Record does not exist.']]], 404),
        };
    }

    /** @param  array<string, mixed>  $data */
    private function write(string $id, array $data): mixed
    {
        foreach ($this->named((string) $data['name']) as $existing) {
            // Aturan Cloudflare yang sungguhan: CNAME tidak boleh berdampingan dengan A/AAAA pada satu nama.
            if ($existing['id'] !== $id && ($existing['type'] === 'CNAME') !== ($data['type'] === 'CNAME')) {
                return Http::response(['success' => false, 'errors' => [['code' => 81053, 'message' => 'An A, AAAA, or CNAME record with that host already exists.']]], 400);
            }
        }

        $this->records[$id] = [
            'id' => $id,
            'name' => (string) $data['name'],
            'type' => (string) $data['type'],
            'content' => (string) $data['content'],
            'proxied' => (bool) $data['proxied'],
            'ttl' => (int) $data['ttl'],
            'comment' => isset($data['comment']) ? (string) $data['comment'] : null,
        ];

        return $this->ok($this->records[$id]);
    }

    private function delete(string $id): mixed
    {
        unset($this->records[$id]);

        return $this->ok(['id' => $id]);
    }

    private function ok(mixed $result): mixed
    {
        return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => $result]);
    }
}
