<?php

declare(strict_types=1);

namespace App\Support\Finance;

use App\Models\FinanceReferenceAccount;
use App\Models\FinanceReferenceAccountImport;
use Illuminate\Support\Facades\DB;

/**
 * Mengimpor daftar akun dari berkas CSV ekspor aplikasi finance (K-05, TODO 3.2).
 *
 * Tiga aturan yang menentukan bentuknya:
 *
 * 1. **Dicocokkan lewat `external_id`.** Nomor dan nama akun boleh berubah di sisi finance; baris
 *    yang `external_id`-nya sama diperbarui, tidak dibuat ulang, sehingga pemetaan yang menunjuk
 *    baris itu tetap utuh.
 * 2. **Akun yang hilang dari berkas tidak dinonaktifkan.** Berkas yang terpotong atau salah ekspor
 *    tidak boleh diam-diam mematikan akun yang sedang dipakai pemetaan. Akun itu dilaporkan, dan
 *    pengguna yang memutuskan.
 * 3. **Semua atau tidak sama sekali.** Satu baris yang ditolak membuat seluruh berkas ditolak.
 *    Daftar akun yang diperbarui separuh lebih sulit ditelusuri daripada berkas yang diperbaiki lalu
 *    diimpor ulang. Pratinjau menunjukkan masalahnya lebih dulu.
 */
final class ReferenceAccountImporter
{
    public const HEADER = ['external_id', 'code', 'name', 'type', 'active'];

    public const MAX_ROWS = 5000;

    private const TRUE_VALUES = ['', 'true', '1', 'yes', 'ya', 'aktif', 'y'];

    private const FALSE_VALUES = ['false', '0', 'no', 'tidak', 'nonaktif', 'n'];

    /**
     * @return array{
     *     status: 'preview'|'applied'|'rejected',
     *     import_id: ?string,
     *     created: list<array{external_id: string, code: string, name: string, type: string, active: bool, line: int}>,
     *     updated: list<array{external_id: string, code: string, changes: array<string, array{from: mixed, to: mixed}>}>,
     *     unchanged_count: int,
     *     missing: list<array{id: string, external_id: string, code: string, name: string, active: bool}>,
     *     rejected: list<array{line: int, external_id: ?string, reason: string}>,
     * }
     */
    public function run(
        string $tenantId,
        ?string $legalEntityId,
        string $fileName,
        string $contents,
        bool $apply,
        ?string $userId,
    ): array {
        [$rows, $rejected] = $this->parse($contents);

        if (! $apply) {
            [$created, $updated, $unchanged, $missing, $rejected] = $this->compare($tenantId, $legalEntityId, $rows, $rejected);

            return $this->report('preview', null, $created, $updated, $unchanged, $missing, $rejected);
        }

        return DB::transaction(function () use ($tenantId, $legalEntityId, $fileName, $rows, $rejected, $userId): array {
            // Dua impor untuk cakupan yang sama dijalankan berurutan. Tanpa kunci, keduanya membaca
            // daftar lama yang sama lalu saling menyisipkan akun yang sama, dan yang kalah jatuh
            // sebagai kesalahan indeks unik di tengah jalan.
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [
                'finance-reference-accounts|'.$tenantId.'|'.($legalEntityId ?? '*'),
            ]);

            [$created, $updated, $unchanged, $missing, $rejected, $existing] = $this->compare($tenantId, $legalEntityId, $rows, $rejected);
            $status = $rejected === [] ? FinanceReferenceAccountImport::APPLIED : FinanceReferenceAccountImport::REJECTED;

            if ($status === FinanceReferenceAccountImport::APPLIED) {
                $now = now();
                foreach ($rows as $row) {
                    $nilai = [
                        'external_id' => $row['external_id'],
                        'code' => $row['code'],
                        'name' => $row['name'],
                        'type' => $row['type'],
                        'active' => $row['active'],
                        'synced_at' => $now,
                    ];
                    $account = $existing[$row['external_id']] ?? null;
                    if ($account === null) {
                        FinanceReferenceAccount::query()->create([
                            'tenant_id' => $tenantId,
                            'legal_entity_id' => $legalEntityId,
                            ...$nilai,
                        ]);

                        continue;
                    }
                    $account->fill($nilai)->save();
                }
            }

            $import = FinanceReferenceAccountImport::query()->create([
                'tenant_id' => $tenantId,
                'legal_entity_id' => $legalEntityId,
                'file_name' => mb_substr($fileName, 0, 255),
                'imported_by_user_id' => $userId,
                'status' => $status,
                'created_count' => count($created),
                'updated_count' => count($updated),
                'unchanged_count' => $unchanged,
                'missing_count' => count($missing),
                'rejected_count' => count($rejected),
                'rejected_rows' => $rejected === [] ? null : array_slice($rejected, 0, 500),
            ]);

            return $this->report($status, $import->id, $created, $updated, $unchanged, $missing, $rejected);
        });
    }

    /**
     * Membaca berkas menjadi baris yang sah dan baris yang ditolak.
     *
     * Pemisahnya dikenali dari baris judul: Excel berbahasa Indonesia menyimpan CSV dengan titik
     * koma karena koma dipakai sebagai pemisah desimal. Tanda BOM di awal berkas dibuang.
     *
     * @return array{0: list<array{external_id: string, code: string, name: string, type: string, active: bool, line: int}>, 1: list<array{line: int, external_id: ?string, reason: string}>}
     */
    public function parse(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $firstLine = strtok($contents, "\r\n");
        if ($firstLine === false || trim($firstLine) === '') {
            return [[], [['line' => 1, 'external_id' => null, 'reason' => 'Berkas kosong.']]];
        }
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return [[], [['line' => 1, 'external_id' => null, 'reason' => 'Berkas tidak dapat dibaca.']]];
        }
        fwrite($stream, $contents);
        rewind($stream);

        $header = fgetcsv($stream, null, $delimiter, '"', '');
        $columns = array_map(static fn (mixed $name): string => strtolower(trim((string) $name)), is_array($header) ? $header : []);
        $missing = array_values(array_diff(self::HEADER, $columns));
        if ($missing !== []) {
            fclose($stream);

            return [[], [[
                'line' => 1,
                'external_id' => null,
                'reason' => 'Baris judul harus memuat kolom '.implode(', ', self::HEADER).'. Tidak ditemukan: '.implode(', ', $missing).'.',
            ]]];
        }
        $index = array_flip($columns);

        $rows = [];
        $rejected = [];
        $lineByExternal = [];
        $line = 1;
        while (($record = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $line++;
            if ($record === [null] || implode('', array_map(static fn (mixed $v): string => trim((string) $v), $record)) === '') {
                continue;
            }
            if (count($rows) + count($rejected) >= self::MAX_ROWS) {
                fclose($stream);

                return [[], [['line' => $line, 'external_id' => null, 'reason' => 'Berkas melebihi '.self::MAX_ROWS.' baris. Bagi menjadi beberapa berkas.']]];
            }

            $value = static fn (string $column): string => trim((string) ($record[$index[$column]] ?? ''));
            $externalId = $value('external_id');
            $reason = $this->validate($externalId, $value('code'), $value('name'), strtolower($value('type')), strtolower($value('active')));
            if ($reason !== null) {
                $rejected[] = ['line' => $line, 'external_id' => $externalId === '' ? null : $externalId, 'reason' => $reason];

                continue;
            }

            $lineByExternal[$externalId][] = $line;
            $rows[] = [
                'external_id' => $externalId,
                'code' => $value('code'),
                'name' => $value('name'),
                'type' => strtolower($value('type')),
                'active' => ! in_array(strtolower($value('active')), self::FALSE_VALUES, true),
                'line' => $line,
            ];
        }
        fclose($stream);

        // Id ganda dalam satu berkas: tidak ada cara tahu baris mana yang benar, jadi keduanya ditolak.
        $clean = [];
        foreach ($rows as $row) {
            $lines = $lineByExternal[$row['external_id']];
            if (count($lines) > 1) {
                $rejected[] = [
                    'line' => $row['line'],
                    'external_id' => $row['external_id'],
                    'reason' => 'external_id ganda di berkas (baris '.implode(', ', $lines).').',
                ];

                continue;
            }
            $clean[] = $row;
        }

        usort($rejected, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        return [$clean, $rejected];
    }

    private function validate(string $externalId, string $code, string $name, string $type, string $active): ?string
    {
        return match (true) {
            $externalId === '' => 'external_id wajib diisi.',
            mb_strlen($externalId) > 64 => 'external_id paling panjang 64 karakter.',
            $code === '' => 'code wajib diisi.',
            mb_strlen($code) > 50 => 'code paling panjang 50 karakter.',
            $name === '' => 'name wajib diisi.',
            mb_strlen($name) > 200 => 'name paling panjang 200 karakter.',
            ! in_array($type, FinanceReferenceAccount::TYPES, true) => 'type harus balance_sheet atau profit_loss.',
            ! in_array($active, [...self::TRUE_VALUES, ...self::FALSE_VALUES], true) => 'active harus true atau false.',
            default => null,
        };
    }

    /**
     * @param  list<array{external_id: string, code: string, name: string, type: string, active: bool, line: int}>  $rows
     * @param  list<array{line: int, external_id: ?string, reason: string}>  $rejected
     * @return array{0: list<array{external_id: string, code: string, name: string, type: string, active: bool, line: int}>, 1: list<array{external_id: string, code: string, changes: array<string, array{from: mixed, to: mixed}>}>, 2: int, 3: list<array{id: string, external_id: string, code: string, name: string, active: bool}>, 4: list<array{line: int, external_id: ?string, reason: string}>, 5: array<string, FinanceReferenceAccount>}
     */
    private function compare(string $tenantId, ?string $legalEntityId, array $rows, array $rejected): array
    {
        $existing = FinanceReferenceAccount::query()
            ->where('tenant_id', $tenantId)
            ->when(
                $legalEntityId === null,
                fn ($query) => $query->whereNull('legal_entity_id'),
                fn ($query) => $query->where('legal_entity_id', $legalEntityId),
            )
            ->get()
            ->keyBy('external_id')
            ->all();

        // Akun yang sama tidak boleh terdaftar untuk semua entitas sekaligus khusus satu entitas:
        // dropdown satu entitas akan menampilkan keduanya, dan pemetaan bisa menunjuk yang salah.
        $bentrok = FinanceReferenceAccount::query()
            ->where('tenant_id', $tenantId)
            ->when(
                $legalEntityId === null,
                fn ($query) => $query->whereNotNull('legal_entity_id'),
                fn ($query) => $query->whereNull('legal_entity_id'),
            )
            ->whereIn('external_id', array_column($rows, 'external_id'))
            ->pluck('external_id')
            ->flip()
            ->all();

        $created = [];
        $updated = [];
        $unchanged = 0;
        foreach ($rows as $row) {
            if (isset($bentrok[$row['external_id']])) {
                $rejected[] = [
                    'line' => $row['line'],
                    'external_id' => $row['external_id'],
                    'reason' => $legalEntityId === null
                        ? 'Akun ini sudah terdaftar khusus untuk satu entitas legal. Impor ke entitas itu, atau hapus pendaftaran khususnya lebih dulu.'
                        : 'Akun ini sudah terdaftar untuk semua entitas legal.',
                ];

                continue;
            }
            $account = $existing[$row['external_id']] ?? null;
            if ($account === null) {
                $created[] = $row;

                continue;
            }
            $changes = [];
            foreach (['code', 'name', 'type', 'active'] as $field) {
                if ($account->{$field} !== $row[$field]) {
                    $changes[$field] = ['from' => $account->{$field}, 'to' => $row[$field]];
                }
            }
            if ($changes === []) {
                $unchanged++;
            } else {
                $updated[] = ['external_id' => $row['external_id'], 'code' => $row['code'], 'changes' => $changes];
            }
        }

        usort($rejected, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        $inFile = array_flip(array_column($rows, 'external_id'));
        $missing = [];
        foreach ($existing as $externalId => $account) {
            if (! isset($inFile[$externalId])) {
                $missing[] = [
                    'id' => $account->id,
                    'external_id' => $account->external_id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'active' => $account->active,
                ];
            }
        }

        return [$created, $updated, $unchanged, $missing, $rejected, $existing];
    }

    /**
     * @param  'preview'|'applied'|'rejected'  $status
     * @param  list<array{external_id: string, code: string, name: string, type: string, active: bool, line: int}>  $created
     * @param  list<array{external_id: string, code: string, changes: array<string, array{from: mixed, to: mixed}>}>  $updated
     * @param  list<array{id: string, external_id: string, code: string, name: string, active: bool}>  $missing
     * @param  list<array{line: int, external_id: ?string, reason: string}>  $rejected
     * @return array{status: 'preview'|'applied'|'rejected', import_id: ?string, created: list<array{external_id: string, code: string, name: string, type: string, active: bool, line: int}>, updated: list<array{external_id: string, code: string, changes: array<string, array{from: mixed, to: mixed}>}>, unchanged_count: int, missing: list<array{id: string, external_id: string, code: string, name: string, active: bool}>, rejected: list<array{line: int, external_id: ?string, reason: string}>}
     */
    private function report(string $status, ?string $importId, array $created, array $updated, int $unchanged, array $missing, array $rejected): array
    {
        return [
            'status' => $status,
            'import_id' => $importId,
            'created' => $created,
            'updated' => $updated,
            'unchanged_count' => $unchanged,
            'missing' => $missing,
            'rejected' => $rejected,
        ];
    }
}
