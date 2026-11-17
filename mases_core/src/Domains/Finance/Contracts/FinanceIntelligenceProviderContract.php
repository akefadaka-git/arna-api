<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Contracts;

interface FinanceIntelligenceProviderContract
{
    /**
     * Generate a proposal from the supplied read-only, tenant-scoped evidence.
     * Implementations must not expose posting, payment, reversal, or period-close tools.
     *
     * @return array{agent_key:string,task_template_version:string,model_provider:?string,model_name:?string,model_version:?string,title:string,summary:string,proposal:array,confidence:?float,limitations:array}
     */
    public function generateProposal(array $task, array $evidence): array;
}
