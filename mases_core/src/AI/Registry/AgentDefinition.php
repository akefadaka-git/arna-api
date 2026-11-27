<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\AI\Registry;

use InvalidArgumentException;

final class AgentDefinition
{
    /**
     * @param list<string> $allowedTools
     * @param list<string> $readDomains
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $description,
        public readonly array $allowedTools,
        public readonly array $readDomains,
        public readonly string $autonomyLevel = 'L0',
        public readonly bool $enabled = false,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.-]{2,79}$/', $key)) {
            throw new InvalidArgumentException('Invalid ARNA AI agent key.');
        }
        if (!in_array($autonomyLevel, ['L0', 'L1', 'L2'], true)) {
            throw new InvalidArgumentException('Only L0-L2 agent autonomy is permitted in this release.');
        }
        foreach (array_merge($allowedTools, $readDomains) as $value) {
            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException('Agent capabilities must be non-empty strings.');
            }
        }
    }
}
