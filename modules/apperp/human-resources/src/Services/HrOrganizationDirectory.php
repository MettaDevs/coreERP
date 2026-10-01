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
 * **Tanda tangan dan bentuk jawabannya sengaja dipertahankan persis seperti milik klien
 * lama.** Kontrak Core memakai kunci `id`/`nama`, sedangkan endpoint module ini sejak awal
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
     * Anggota tenant yang cocok dengan kata pencarian.
     *
     * Penyaringan dan pembatasan dikerjakan di sini, bukan oleh kontrak. Kontrak Core
     * memulangkan seluruh anggota aktif tanpa parameter pencarian, jadi menghapus penyaringan
     * ini berarti mengubah perilaku endpoint — kata pencarian yang dikirim layar berhenti
     * berpengaruh, dan yang dipulangkan menjadi seluruh isi direktori.
     *
     * @return list<array{membership_id: string, name: string, email: string}>
     */
    public function members(string $tenantId, string $query): array
    {
        $search = mb_strtolower(trim($query));
        $result = [];

        foreach ($this->directory->members($tenantId) as $member) {
            if ($search !== ''
                && ! str_contains(mb_strtolower($member['nama']), $search)
                && ! str_contains(mb_strtolower($member['email']), $search)) {
                continue;
            }

            $result[] = $this->shapeMember($member);

            if (count($result) === self::RESULT_LIMIT) {
                break;
            }
        }

        return $result;
    }

    /**
     * Unit kerja tenant, dengan kunci yang sama seperti yang dipulangkan endpoint lama.
     *
     * @return list<array{id: string, name: string}>
     */
    public function operatingUnits(string $tenantId): array
    {
        $result = [];

        foreach ($this->directory->operatingUnits($tenantId) as $unit) {
            $result[] = ['id' => $unit['id'], 'name' => $unit['nama']];
        }

        return $result;
    }

    /**
     * Anggota aktif tenant yang emailnya sama persis dengan email pekerja, tanpa membedakan huruf besar
     * (TODO analisa gap BC 9.1). Dipakai sebagai usulan tautan pekerja; yang memutuskan tetap
     * pengguna. Hanya anggota tenant itu yang bisa muncul, karena kontrak Core menyaring per tenant.
     *
     * @return list<array{membership_id: string, name: string, email: string}>
     */
    public function membersWithEmail(string $tenantId, string $email): array
    {
        $wanted = mb_strtolower(trim($email));
        if ($wanted === '') {
            return [];
        }

        $result = [];
        foreach ($this->directory->members($tenantId) as $member) {
            if (mb_strtolower(trim($member['email'])) === $wanted) {
                $result[] = $this->shapeMember($member);
            }
        }

        return $result;
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

        $wanted = array_flip($membershipIds);
        $result = [];
        foreach ($this->directory->members($tenantId) as $member) {
            if (isset($wanted[$member['id']])) {
                $result[$member['id']] = $this->shapeMember($member);
            }
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
