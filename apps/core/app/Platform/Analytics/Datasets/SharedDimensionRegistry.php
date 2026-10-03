<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Datasets;

use App\Platform\Modules\Contracts\Analytics\SharedDimension;
use App\Platform\Modules\Contracts\Analytics\SharedDimensionResolver;
use App\Platform\Modules\Contracts\Analytics\SharedDimensions;
use App\Platform\Modules\Contracts\DataClass;

/**
 * Penerjemah label dimensi bersama yang terdaftar di proses ini, satu per dimensi.
 *
 * Dimensi milik Platform — entitas legal, unit kerja, pengguna — dipasang di sini. Dimensi milik
 * Foundation — vendor, mata uang — didaftarkan fitur pemiliknya dari penyedia layanannya sendiri, karena
 * Platform tidak boleh menyebut Foundation (`LayerDirectionBoundaryTest`). Diikat sebagai satu benda
 * (`CoreServices::SINGLETON_BINDINGS`), supaya pendaftaran dan pembacaan memegang daftar yang sama.
 *
 * Label dibaca sesudah agregasi, sekali per himpunan id (area 3, `LabelResolver`), dan tidak pernah
 * disimpan di sini: label adalah data tenant.
 */
final class SharedDimensionRegistry implements SharedDimensions
{
    /** @var array<string, SharedDimensionResolver> */
    private array $resolvers = [];

    public function __construct()
    {
        $this->register(new OrganizationLabels(SharedDimension::LegalEntity));
        $this->register(new OrganizationLabels(SharedDimension::OperatingUnit));
        $this->register(new MemberLabels);
    }

    public function register(SharedDimensionResolver $resolver): void
    {
        $this->resolvers[$resolver->dimension()->value] = $resolver;
    }

    public function for(SharedDimension $dimension): ?SharedDimensionResolver
    {
        return $this->resolvers[$dimension->value] ?? null;
    }

    /**
     * Label untuk id yang ditanyakan. Label nama orang (`EndUserIdentifiableInformation`, misalnya nama
     * pengguna) hanya untuk principal yang berhak membaca data pribadi; tanpa hak itu, tidak ada label dan
     * layar menampilkan id-nya saja. Dimensi tanpa resolver juga tanpa label.
     *
     * @param  list<string>  $ids
     * @return array<int|string, string> id => label, seperti {@see SharedDimensionResolver::labels()}
     */
    public function labels(SharedDimension $dimension, string $tenantId, array $ids, bool $mayUsePersonalData): array
    {
        $resolver = $this->for($dimension);
        $ids = array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));

        if ($resolver === null || $ids === []
            || ($resolver->labelClassification() === DataClass::EndUserIdentifiableInformation && ! $mayUsePersonalData)) {
            return [];
        }

        return $resolver->labels($tenantId, $ids);
    }
}
