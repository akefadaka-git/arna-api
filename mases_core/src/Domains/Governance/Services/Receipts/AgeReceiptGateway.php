<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services\Receipts;

/**
 * Provider-neutral transport boundary for the external receipt authority and
 * the independently administered witness.
 *
 * Implementations must keep the witness challenge on a direct witness
 * connection. They must not convert a receipt-authority response into proof
 * of the independent witness head.
 */
interface AgeReceiptGateway
{
    /**
     * Append one immutable event and return only after receipt and witness
     * durability acknowledgements have both been obtained.
     *
     * @param array<string,mixed> $request Validated against the AGE append contract.
     * @return array<string,mixed> Receipt plus independently signed witness checkpoint.
     */
    public function append(array $request): array;

    /**
     * Challenge the independent witness directly with a fresh 256-bit nonce.
     *
     * @param array<string,mixed> $request Validated against the AGE head-challenge contract.
     * @return array<string,mixed> Signed witness head response.
     */
    public function challengeWitnessHead(array $request): array;
}
