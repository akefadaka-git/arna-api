<?php
declare(strict_types=1);
namespace MaaleArna\MASES\Core\Domains\People\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use MaaleArna\MASES\Core\Domains\People\Events\PersonProfileLifecycleChanged;
use MaaleArna\MASES\Core\Domains\People\Http\Requests\DeactivatePersonRecordRequest;
use MaaleArna\MASES\Core\Domains\People\Http\Requests\StorePersonParticipationRequest;
use MaaleArna\MASES\Core\Domains\People\Http\Resources\PersonParticipationResource;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Models\PersonParticipation;
use MaaleArna\MASES\Core\Domains\People\Services\PersonParticipationService;
final class PersonParticipationController extends Controller {
 public function index(string $publicId): JsonResponse {
  $tenantId=$this->tenantId(); $person=$this->visiblePerson($publicId,$tenantId);
  $records=PersonParticipation::query()->where('tenant_id',$tenantId)->where('person_id',$person->getKey())->where('status','active')->where(static fn($q)=>$q->whereNull('effective_from')->orWhereDate('effective_from','<=',today()))->where(static fn($q)=>$q->whereNull('effective_to')->orWhereDate('effective_to','>=',today()))->orderBy('participation_type')->orderBy('title')->limit((int)config('people.participations.max_per_person',200))->get();
  return response()->json(['success'=>true,'data'=>PersonParticipationResource::collection($records)->resolve()]);
 }
 public function store(StorePersonParticipationRequest $request,string $publicId,PersonParticipationService $service): JsonResponse {
  $tenantId=$this->tenantId(); $person=$this->visiblePerson($publicId,$tenantId);
  $record=$service->create($tenantId,$person,$request->validated(),$request->user()?->getKey());
  event(new PersonProfileLifecycleChanged('people.participation.created',$tenantId,(int)$person->getKey(),$request->user()?->getKey(),'participation',(int)$record->getKey()));
  return response()->json(['success'=>true,'data'=>(new PersonParticipationResource($record))->resolve()],201);
 }
 public function deactivate(DeactivatePersonRecordRequest $request,string $publicId,int $participationId,PersonParticipationService $service): JsonResponse {
  $tenantId=$this->tenantId(); $person=$this->visiblePerson($publicId,$tenantId);
  $record=$service->deactivate($tenantId,$person,$participationId,$request->validated()['reason']);
  event(new PersonProfileLifecycleChanged('people.participation.deactivated',$tenantId,(int)$person->getKey(),$request->user()?->getKey(),'participation',(int)$record->getKey()));
  return response()->json(['success'=>true,'data'=>(new PersonParticipationResource($record))->resolve()]);
 }
 private function tenantId(): int { $tenantId=mases_tenant_id(); abort_if($tenantId===null,400,'A validated tenant context is required.'); return (int)$tenantId; }
 private function visiblePerson(string $publicId,int $tenantId): Person {
  return Person::query()->where('public_id',$publicId)->where('status','active')->whereHas('tenantAccesses',static fn($q)=>$q->where('tenant_id',$tenantId)->where('status','active'))->firstOrFail();
 }
}
