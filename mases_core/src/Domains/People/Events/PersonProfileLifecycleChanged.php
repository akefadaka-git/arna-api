<?php
declare(strict_types=1);
namespace MaaleArna\MASES\Core\Domains\People\Events;
final class PersonProfileLifecycleChanged {
 public function __construct(
  public readonly string $action,
  public readonly int $tenantId,
  public readonly int $personId,
  public readonly ?int $actorId,
  public readonly string $recordType,
  public readonly int $recordId,
 ) {}
}
