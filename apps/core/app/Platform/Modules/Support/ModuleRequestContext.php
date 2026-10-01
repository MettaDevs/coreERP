<?php

declare(strict_types=1);

namespace App\Platform\Modules\Support;

use App\Platform\Identity\Support\UserClock;
use App\Platform\Modules\Contracts\RequestContext;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Pembungkus baca di atas atribut permintaan yang ditulis middleware konteks module.
 *
 * Kelas ini sengaja tidak menyimpan apa pun dan tidak menghitung apa pun. Yang menghitung
 * adalah middleware; yang ini hanya membaca. Pemisahan itu yang membuat atribut permintaan
 * tetap menjadi satu-satunya kebenaran, sehingga kode lama yang membaca atribut langsung dan
 * kode baru yang membaca lewat kelas ini tidak mungkin memberi jawaban berbeda.
 *
 * Nama kuncinya diambil apa adanya dari middleware app lama
 * (`api/app/Http/Middleware/RequireCoreErpContext.php` pada repo Management Aset). Awalan
 * `coreerp.` dipertahankan walaupun sekarang tidak ada lagi "core lain" yang perlu
 * dibedakan, karena 22 berkas modul aset membacanya langsung dan mengganti nama kunci
 * berarti menyentuh 22 berkas untuk keuntungan nol.
 *
 * Semua pembacanya gagal menutup. Atribut boleh saja tidak ada — misalnya ketika sebuah rute
 * module lupa dipasangi middleware-nya — dan pada keadaan itu jawaban yang benar adalah
 * "tidak punya izin", bukan "punya semua izin".
 */
final class ModuleRequestContext implements RequestContext
{
    public const TENANT_ID = 'coreerp.tenant_id';

    public const LEGAL_ENTITY_ID = 'coreerp.legal_entity_id';

    public const ORG_UNIT_ID = 'coreerp.org_unit_id';

    public const USER_ID = 'coreerp.user_id';

    public const PERMISSIONS = 'coreerp.permissions';

    public const DATA_POLICIES = 'coreerp.data_policies';

    public function __construct(
        private readonly Request $request,
        private readonly UserClock $clock,
    ) {}

    public function userId(): string
    {
        $value = $this->request->attributes->get(self::USER_ID);

        if (! is_string($value) || $value === '') {
            // Melempar, bukan mengembalikan string kosong. Id pengguna kosong akan diteruskan
            // ke kolom "dibuat oleh" dan menghasilkan jejak audit yang tidak menunjuk siapa pun.
            throw new RuntimeException('Konteks module belum terpasang pada permintaan ini.');
        }

        return $value;
    }

    /** @return list<string> */
    public function permissions(): array
    {
        $value = $this->request->attributes->get(self::PERMISSIONS, []);

        if (! is_array($value)) {
            return [];
        }

        $code = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $code[] = $item;
            }
        }

        return $code;
    }

    public function hasPermission(string $code): bool
    {
        return in_array($code, $this->permissions(), true);
    }

    /** @return array<string, mixed> */
    public function dataPolicies(): array
    {
        $value = $this->request->attributes->get(self::DATA_POLICIES, []);

        if (! is_array($value)) {
            return [];
        }

        $policy = [];

        foreach ($value as $code => $scope) {
            $policy[(string) $code] = $scope;
        }

        return $policy;
    }

    /**
     * Satu-satunya jawaban di kelas ini yang tidak dibaca dari atribut permintaan: zona waktu sudah
     * punya penghitung sendiri, `UserClock`, yang juga dipakai layar dan cetakan. Menyalinnya ke
     * atribut berarti dua sumber, dan menghitungnya di middleware berarti setiap rute module membayar
     * query entitas legal walau tidak butuh "hari ini".
     */
    public function timezone(): string
    {
        return $this->clock->timezone($this->request);
    }
}
