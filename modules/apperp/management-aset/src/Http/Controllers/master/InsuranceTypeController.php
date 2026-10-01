<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\InsuranceType;

/**
 * Jenis asuransi; padanan *Insurance Type* Business Central.
 *
 * @extends MasterDataController<InsuranceType>
 */
class InsuranceTypeController extends MasterDataController
{
    protected function resource(): string
    {
        return 'jenis-asuransi';
    }

    protected function model(): string
    {
        return InsuranceType::class;
    }
}
