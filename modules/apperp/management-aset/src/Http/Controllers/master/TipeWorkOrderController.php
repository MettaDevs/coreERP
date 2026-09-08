<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\TipeWorkOrder;
use Modules\Apperp\ManagementAset\Models\MasterData;

class TipeWorkOrderController extends MasterDataController
{
    /**
     * Penanda batasan bentuk penugasan. Aturan isi data tidak di sini melainkan pada
     * validasi per status, supaya kekerasannya dapat berbeda di tiap langkah.
     */
    private const FLAGS = ['satu_pekerja'];

    protected function resource(): string
    {
        return 'tipe-work-order';
    }

    protected function model(): string
    {
        return TipeWorkOrder::class;
    }

    protected function extraRules(string $tenantId, bool $creating): array
    {
        $rules = [];
        foreach (self::FLAGS as $flag) {
            $rules[$flag] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    protected function extraPayload(array $data): array
    {
        $payload = [];
        foreach (self::FLAGS as $flag) {
            // Dinormalkan ke boolean asli supaya replay idempoten membandingkan nilai yang
            // setipe dengan atribut model; rule `boolean` menerima 1/0/"1"/"0" apa adanya.
            if (array_key_exists($flag, $data)) {
                $payload[$flag] = filter_var($data[$flag], FILTER_VALIDATE_BOOL);
            }
        }

        return $payload;
    }

    protected function extraPresent(MasterData $record): array
    {
        $present = [];
        foreach (self::FLAGS as $flag) {
            $present[$flag] = (bool) $record->{$flag};
        }

        return $present;
    }
}
