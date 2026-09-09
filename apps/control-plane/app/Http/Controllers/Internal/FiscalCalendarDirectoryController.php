<?php

namespace App\Http\Controllers\Internal;

use App\Actions\FiscalCalendar\FiscalCalendarService;
use App\Http\Controllers\Controller;
use App\Models\FiscalPeriod;
use App\Models\LegalEntity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Kalender fiskal untuk app yang perhitungannya bergantung pada batas tahun buku,
 * misalnya penyusutan aset dengan dasar tahun fiskal. Kalender melekat pada legal
 * entity, jadi app cukup menyebut legal entity dan tanggal yang ditanyakan.
 *
 * Fakta kalender tetap dimiliki Core dan tidak boleh diduplikasi di database app.
 *
 * **Pencarian periodenya sekarang milik `FiscalCalendarService::resolve()`, bukan milik
 * controller ini.** Sebelumnya keduanya menjalankan pencarian yang sama dengan query yang
 * ditulis terpisah: layanan mencari tahun lebih dulu lalu periode di dalam tahun itu,
 * controller mencari periode langsung lewat kalendernya. Selama tahun buku tidak pernah
 * bertumpang tindih, keduanya menjawab sama — dan itulah yang membuat penyimpangannya
 * berbahaya: hari ketika salah satu aturannya berubah, yang satu ikut berubah dan yang lain
 * tidak, tanpa ada satu pun test yang membandingkan keduanya.
 *
 * Yang tinggal di sini hanyalah dua hal yang memang bukan milik layanan: penyaringan tenant
 * pada entitas legal, dan **bentuk jawaban** endpoint ini. Keduanya berbeda; layanan
 * memulangkan bentuk datar tanpa tanggal (`year_id`, `year_name`, …), sementara app yang
 * memanggil endpoint ini justru membutuhkan tanggal mulai dan selesai tahun bukunya.
 */
final class FiscalCalendarDirectoryController extends Controller
{
    public function resolve(Request $request, FiscalCalendarService $calendars): JsonResponse
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'string', 'size:26'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $entity = LegalEntity::query()
            ->where('tenant_id', $request->attributes->get('coreerp.tenant_id'))
            ->whereKey($data['legal_entity_id'])
            // Primary key legal entity adalah `organization_id`, bukan `id`.
            ->first(['organization_id', 'fiscal_calendar_id']);

        // Diperiksa di sini, bukan diserahkan ke layanan, karena hanya di sini yang tahu
        // bedanya "entitas legal tidak ada di tenant ini" dan "entitas legal ada tetapi belum
        // punya kalender". Keduanya dijawab 404 yang sama supaya app pemanggil tidak bisa
        // memakai endpoint ini untuk menebak entitas legal milik tenant lain.
        if (! $entity?->fiscal_calendar_id) {
            return response()->json(['error' => [
                'code' => 'fiscal_calendar_not_assigned',
                'message' => 'Perusahaan ini belum memiliki kalender tahun buku.',
            ]], 404);
        }

        try {
            $position = $calendars->resolve($entity, Carbon::parse($data['date']));
        } catch (ValidationException) {
            // Layanan melempar untuk tanggal di luar tahun maupun di luar periode. Endpoint ini
            // sejak awal menjawab 404 untuk keduanya, dan app yang memanggilnya membaca 404 itu
            // sebagai "belum tercakup" — bukan sebagai kesalahan. Menerjemahkannya di sini
            // menjaga janji itu tetap sama meski aturannya kini tinggal satu salinan.
            return $this->periodNotFound();
        }

        // Tanggal dan identitas kalender hanya ada pada barisnya, bukan pada jawaban layanan.
        $period = FiscalPeriod::query()
            ->whereKey($position['period_id'])
            ->with('year.calendar:id,code,name')
            ->first();

        if ($period === null) {
            return $this->periodNotFound();
        }

        return response()->json(['data' => [
            'calendar' => [
                'id' => $period->year->calendar->id,
                'code' => $period->year->calendar->code,
                'name' => $period->year->calendar->name,
            ],
            'year' => [
                'id' => $position['year_id'],
                'name' => $position['year_name'],
                'starts_on' => $period->year->starts_on->toDateString(),
                'ends_on' => $period->year->ends_on->toDateString(),
            ],
            'period' => [
                'id' => $position['period_id'],
                'ordinal' => $position['period_ordinal'],
                'name' => $position['period_name'],
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
            ],
        ]]);
    }

    private function periodNotFound(): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'fiscal_period_not_found',
            'message' => 'Tanggal tersebut belum tercakup pada kalender tahun buku perusahaan ini.',
        ]], 404);
    }
}
