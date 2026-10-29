<?php
declare(strict_types=1);
namespace MaaleArna\MASES\Core\Domains\People\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
final class PersonProfileResource extends JsonResource {
 public static $wrap=null;
 public function toArray(Request $request): array {
  return [
   'public_id'=>$this->public_id,
   'first_name_am'=>$this->first_name_am,
   'father_name_am'=>$this->father_name_am,
   'grandfather_name_am'=>$this->grandfather_name_am,
   'first_name_en'=>$this->first_name_en,
   'father_name_en'=>$this->father_name_en,
   'grandfather_name_en'=>$this->grandfather_name_en,
   'status'=>$this->status,
   'created_at'=>$this->created_at?->toIso8601String(),
   'updated_at'=>$this->updated_at?->toIso8601String(),
  ];
 }
}
