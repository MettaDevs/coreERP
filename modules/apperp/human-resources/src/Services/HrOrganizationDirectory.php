<?php

namespace Modules\Apperp\HumanResources\Services;

use App\Platform\Modules\Contracts\OrganizationDirectory;

/**
 * Anggota dan unit kerja tenant lewat kontrak Core, bukan lewat HTTP.
 *
 * Menggantikan klien yang dulu memanggil tiga endpoint internal Core. Di dalam satu runtime
 * permintaan itu tidak menambah satu pun kemampuan; ia hanya menambah kegagalan yang bisa
 * terjadi — batas waktu, token layanan yang tidak sinkron, dan 503 yang harus dijelaskan ke
 * pengguna padahal Core berada di proses yang sama.
 *
 * Kontrak Core memakai kunci `id`/`nama`, sedangkan endpoint module ini sejak awal
 * memulangkan `membership_id`/`name`. Yang berpindah adalah jalurnya, bukan janjinya ke
 * pemanggil, jadi penerjemahan kunci dikerjakan di sini — satu tempat, bukan di setiap
 * controller yang memakainya.
 */
final class HrOrganizationDirectory
{
    /**
     * Sebanyak-banyaknya anggota yang dipulangkan pencarian.
     *
     * Angkanya diambil dari endpoint lama apa adanya. Ia bukan kerapian: daftar ini mengisi
     * kotak pencarian pada layar penautan akun, dan sebuah tenant besar akan mengirim ribuan
     * baris ke layar yang hanya menampilkan beberapa.
     */
    private const RESULT_LIMIT = 20;

    public function __construct(private readonly OrganizationDirectory $directory) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{data: list<array{membership_id: string, name: string, email: string}>, next_cursor: ?string}
     */
    public function members(string $tenantId, array $input): array
    {
        $batch = $this->directory->lookup($tenantId, 'memberships', ['per_page' => self::RESULT_LIMIT, ...$input]);
        $batch['data'] = array_values($batch['data']->map(fn (\stdClass $member): array => $this->shapeMember([
            'id' => $member->id, 'nama' => $member->nama, 'email' => $member->email,
        ]))->all());

        return $batch;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{data: list<array{id: string, name: string}>, next_cursor: ?string}
     */
    public function operatingUnits(string $tenantId, array $input): array
    {
        $batch = $this->directory->lookup($tenantId, 'operating_units', $input);
        $batch['data'] = array_values($batch['data']->map(fn (\stdClass $unit): array => ['id' => $unit->id, 'name' => $unit->nama])->all());

        return $batch;
    }

    /**
     * Anggota aktif tenant berkunci id keanggotaan, untuk menampilkan nama akun yang tertaut di daftar pekerja.
     * Keanggotaan yang tidak aktif lagi tidak ikut.
     *
     * @param  list<string>  $membershipIds
     * @return array<string, array{membership_id: string, name: string, email: string}>
     */
    public function membersById(string $tenantId, array $membershipIds): array
    {
        if ($membershipIds === []) {
            return [];
        }

        $result = [];
        foreach ($this->directory->membersById($tenantId, $membershipIds) as $member) {
            $result[$member['id']] = $this->shapeMember($member);
        }

        return $result;
    }

    /**
     * Satu anggota tenant, dipakai untuk memastikan keanggotaan yang ditautkan memang milik tenant ini.
     * `null` bila tidak ada, termasuk keanggotaan milik tenant lain: yang memanggilnya sedang memvalidasi
     * masukan pengguna, dan jawabannya galat isian, bukan kegagalan server.
     *
     * @return array{membership_id: string, name: string, email: string}|null
     */
    public function member(string $tenantId, string $membershipId): ?array
    {
        $member = $this->directory->member($tenantId, $membershipId);

        return $member === null ? null : $this->shapeMember($member);
    }

    /**
     * @param  array{id: string, nama: string, email: string}  $member
     * @return array{membership_id: string, name: string, email: string}
     */
    private function shapeMember(array $member): array
    {
        return [
            'membership_id' => $member['id'],
            'name' => $member['nama'],
            'email' => $member['email'],
        ];
    }
}
