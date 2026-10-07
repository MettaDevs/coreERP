<?php

declare(strict_types=1);

namespace Modules\Apperp\ContohB\Analytics;

use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\Dataset;
use App\Platform\Modules\Contracts\Analytics\DatasetDefinition;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;
use App\Platform\Modules\Contracts\Analytics\SharedDimension;
use App\Platform\Modules\Contracts\FieldType;
use Modules\Apperp\ContohB\Models\Order;

/** Dataset berkebijakan untuk membuktikan blend menjalankan kebijakan tiap sumber secara terpisah. */
final class OrderDataset implements Dataset
{
    public function moduleId(): string
    {
        return 'contoh-b';
    }

    public function definition(): DatasetDefinition
    {
        return DatasetDefinition::make('contoh-b.orders', 'Pesanan')
            ->model(Order::class)
            ->permission('contoh-b.orders.read')
            ->dataPolicy('contoh-b.orders-scope', legalEntity: 'legal_entity_id', operatingUnit: 'org_unit_id')
            ->field('legal_entity_id', 'Entitas legal', FieldType::Reference)
            ->fieldsFromModel(only: ['org_unit_id', 'currency_code', 'amount'])
            ->shared('org_unit_id', SharedDimension::OperatingUnit)
            ->shared('legal_entity_id', SharedDimension::LegalEntity)
            ->shared('currency_code', SharedDimension::Currency)
            ->measure('count', 'Jumlah pesanan', Aggregate::Count)
            ->measure('amount', 'Nilai pesanan', Aggregate::Sum, field: 'amount', format: MeasureFormat::Money, currency: 'currency_code')
            ->version(1);
    }
}
