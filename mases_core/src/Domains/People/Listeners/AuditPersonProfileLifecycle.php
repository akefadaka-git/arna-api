<?php
declare(strict_types=1);
namespace MaaleArna\MASES\Core\Domains\People\Listeners;
use MaaleArna\MASES\Core\Audit\Services\AuditService;
use MaaleArna\MASES\Core\Domains\People\Events\PersonProfileLifecycleChanged;
final class AuditPersonProfileLifecycle {
 public function __construct(private readonly AuditService $audit) {}
 public function handle(PersonProfileLifecycleChanged $event): void {
  $this->audit->log(
   action: $event->action,
   data: ['person_id'=>$event->personId,'record_type'=>$event->recordType,'record_id'=>$event->recordId],
   user: $event->actorId,
   tenantId: $event->tenantId,
   resourceType: 'person.'.$event->recordType,
   resourceId: (string)$event->recordId,
  );
 }
}
