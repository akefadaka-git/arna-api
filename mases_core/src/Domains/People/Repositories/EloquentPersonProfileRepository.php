<?php
declare(strict_types=1);
namespace MaaleArna\MASES\Core\Domains\People\Repositories;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use MaaleArna\MASES\Core\Domains\People\Contracts\PersonProfileRepositoryContract;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
final class EloquentPersonProfileRepository implements PersonProfileRepositoryContract {
 public function paginateVisible(int $tenantId,string $search,int $perPage): LengthAwarePaginator {
  $query=Person::query()->where('people.status','active')->whereHas('tenantAccesses',static fn($access)=>$access->where('tenant_id',$tenantId)->where('status','active'));
  if($search!==''){$pattern='%'.$search.'%';$query->where(static function($names)use($pattern):void{foreach(['first_name_am','father_name_am','grandfather_name_am','first_name_en','father_name_en','grandfather_name_en'] as $column){$names->orWhere('people.'.$column,'like',$pattern);}});}
  $maximum=max(1,(int)config('people.directory.max_per_page',100));$default=max(1,min($maximum,(int)config('people.directory.default_per_page',25)));
  return $query->orderBy('people.id')->paginate(max(1,min($maximum,$perPage?:$default)));
 }
 public function findVisible(int $tenantId,string $publicId): ?Person {
  return Person::query()->where('public_id',$publicId)->where('status','active')->whereHas('tenantAccesses',static fn($access)=>$access->where('tenant_id',$tenantId)->where('status','active'))->first();
 }
}
