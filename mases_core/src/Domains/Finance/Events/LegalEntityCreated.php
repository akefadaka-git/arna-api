<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Events;

use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;

final class LegalEntityCreated extends FinanceLifecycleEvent
{
    public function __construct(LegalEntity $entity, int $actorId)
    {
        parent::__construct((int)$entity->tenant_id, (int)$entity->id, $actorId, 'finance.entity.created',
            'legal_entity', (int)$entity->id, null,
            ['code'=>$entity->code,'name_en'=>$entity->name_en,'name_am'=>$entity->name_am,
             'base_currency'=>$entity->base_currency,'status'=>$entity->status]);
    }
}
