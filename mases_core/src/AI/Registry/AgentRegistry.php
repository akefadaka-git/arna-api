<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\AI\Registry;

use InvalidArgumentException;

final class AgentRegistry
{
    /** @var array<string,AgentDefinition> */
    private array $agents = [];

    /** @param iterable<AgentDefinition> $agents */
    public function __construct(iterable $agents = [])
    {
        foreach ($agents as $agent) {
            $this->register($agent);
        }
    }

    public function register(AgentDefinition $agent): void
    {
        if (isset($this->agents[$agent->key])) {
            throw new InvalidArgumentException('Duplicate ARNA AI agent key: '.$agent->key);
        }
        $this->agents[$agent->key] = $agent;
    }

    public function get(string $key): AgentDefinition
    {
        if (!isset($this->agents[$key])) {
            throw new InvalidArgumentException('Unknown ARNA AI agent: '.$key);
        }
        return $this->agents[$key];
    }

    /** @return list<AgentDefinition> */
    public function all(): array
    {
        return array_values($this->agents);
    }
}
