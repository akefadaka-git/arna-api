<?php
declare(strict_types=1);
namespace MaaleArna\MASES\Core\Domains\People\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Domains\People\Events\PersonProfileLifecycleChanged;
use MaaleArna\MASES\Core\Domains\People\Http\Requests\DeactivatePersonRecordRequest;
use MaaleArna\MASES\Core\Domains\People\Http\Requests\StorePersonContactPointRequest;
use MaaleArna\MASES\Core\Domains\People\Http\Resources\PersonContactPointResource;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Models\PersonContactPoint;
use MaaleArna\MASES\Core\Domains\People\Services\PersonContactPointService;
final class PersonContactPointController extends Controller {
 public function index(string $publicId): JsonResponse {
  $tenantId=$this->tenantId(); $person=$this->visiblePerson($publicId,$tenantId);
  $records=PersonContactPoint::query()->where('tenant_id',$tenantId)->where('person_id',$person->getKey())->where('status','active')->orderByDesc('is_primary')->orderBy('contact_type')->orderBy('id')->limit((int)config('people.contacts.max_per_person',20))->get();
  return response()->json(['success'=>true,'data'=>PersonContactPointResource::collection($records)->resolve()]);
 }
 public function store(StorePersonContactPointRequest $request,string $publicId,PersonContactPointService $service): JsonResponse {
  $tenantId=$this->tenantId(); $person=$this->visiblePerson($publicId,$tenantId);
  $contact=DB::transaction(function () use ($service,$tenantId,$person,$request): PersonContactPoint {
   $contact=$service->create($tenantId,$person,$request->validated(),$request->user()?->getKey());
   event(new PersonProfileLifecycleChanged('people.contact.created',$tenantId,(int)$person->getKey(),$request->user()?->getKey(),'contact_point',(int)$contact->getKey()));
   return $contact;
  });
  return response()->json(['success'=>true,'data'=>(new PersonContactPointResource($contact))->resolve()],201);
 }
 public function deactivate(DeactivatePersonRecordRequest $request,string $publicId,int $contactId,PersonContactPointService $service): JsonResponse {
  $tenantId=$this->tenantId(); $person=$this->visiblePerson($publicId,$tenantId);
  $contact=DB::transaction(function () use ($service,$tenantId,$person,$contactId,$request): PersonContactPoint {
   $contact=$service->deactivate($tenantId,$person,$contactId,$request->validated()['reason']);
   event(new PersonProfileLifecycleChanged('people.contact.deactivated',$tenantId,(int)$person->getKey(),$request->user()?->getKey(),'contact_point',(int)$contact->getKey()));
   return $contact;
  });
  return response()->json(['success'=>true,'data'=>(new PersonContactPointResource($contact))->resolve()]);
 }
 private function tenantId(): int { $tenantId=mases_tenant_id(); abort_if($tenantId===null,400,'A validated tenant context is required.'); return (int)$tenantId; }
 private function visiblePerson(string $publicId,int $tenantId): Person {
  return Person::query()->where('public_id',$publicId)->where('status','active')->whereHas('tenantAccesses',static fn($q)=>$q->where('tenant_id',$tenantId)->where('status','active'))->firstOrFail();
 }
}
