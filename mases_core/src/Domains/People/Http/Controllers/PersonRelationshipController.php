<?php
declare(strict_types=1);
namespace MaaleArna\MASES\Core\Domains\People\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use MaaleArna\MASES\Core\Domains\People\Events\PersonProfileLifecycleChanged;
use MaaleArna\MASES\Core\Domains\People\Http\Requests\DeactivatePersonRecordRequest;
use MaaleArna\MASES\Core\Domains\People\Http\Requests\StorePersonRelationshipRequest;
use MaaleArna\MASES\Core\Domains\People\Http\Resources\PersonRelationshipResource;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Models\PersonRelationship;
use MaaleArna\MASES\Core\Domains\People\Services\PersonRelationshipService;
final class PersonRelationshipController extends Controller {
 public function index(string $publicId): JsonResponse {
  $tenantId=$this->tenantId(); $person=$this->visiblePerson($publicId,$tenantId);
  $records=PersonRelationship::query()->with(['relatedPerson:id,public_id,first_name_am,father_name_am,grandfather_name_am,first_name_en,father_name_en,grandfather_name_en'])->where('tenant_id',$tenantId)->where('person_id',$person->getKey())->where('status','active')->where(static fn($q)=>$q->whereNull('effective_from')->orWhereDate('effective_from','<=',today()))->where(static fn($q)=>$q->whereNull('effective_to')->orWhereDate('effective_to','>=',today()))->orderBy('relationship_type')->orderBy('id')->limit((int)config('people.relationships.max_per_person',100))->get();
  return response()->json(['success'=>true,'data'=>PersonRelationshipResource::collection($records)->resolve()]);
 }
 public function store(StorePersonRelationshipRequest $request,string $publicId,PersonRelationshipService $service): JsonResponse {
  $tenantId=$this->tenantId(); $person=$this->visiblePerson($publicId,$tenantId); $attributes=$request->validated();
  $related=$this->visiblePerson($attributes['related_public_id'],$tenantId);
  $record=$service->create($tenantId,$person,$related,$attributes,$request->user()?->getKey());
  event(new PersonProfileLifecycleChanged('people.relationship.created',$tenantId,(int)$person->getKey(),$request->user()?->getKey(),'relationship',(int)$record->getKey()));
  return response()->json(['success'=>true,'data'=>(new PersonRelationshipResource($record))->resolve()],201);
 }
 public function deactivate(DeactivatePersonRecordRequest $request,string $publicId,int $relationshipId,PersonRelationshipService $service): JsonResponse {
  $tenantId=$this->tenantId(); $person=$this->visiblePerson($publicId,$tenantId);
  $record=$service->deactivate($tenantId,$person,$relationshipId,$request->validated()['reason']);
  event(new PersonProfileLifecycleChanged('people.relationship.deactivated',$tenantId,(int)$person->getKey(),$request->user()?->getKey(),'relationship',(int)$record->getKey()));
  return response()->json(['success'=>true,'data'=>(new PersonRelationshipResource($record))->resolve()]);
 }
 private function tenantId(): int { $tenantId=mases_tenant_id(); abort_if($tenantId===null,400,'A validated tenant context is required.'); return (int)$tenantId; }
 private function visiblePerson(string $publicId,int $tenantId): Person {
  return Person::query()->where('public_id',$publicId)->where('status','active')->whereHas('tenantAccesses',static fn($q)=>$q->where('tenant_id',$tenantId)->where('status','active'))->firstOrFail();
 }
}
