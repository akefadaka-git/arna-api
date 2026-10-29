<?php
declare(strict_types=1);
namespace MaaleArna\MASES\Core\Domains\People\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
final class PersonDocumentLinkResource extends JsonResource {
 public static $wrap=null;
 public function toArray(Request $request): array {
  return [
   'file_id'=>(int)data_get($this->resource,'file_id'),
   'display_name_am'=>data_get($this->resource,'display_name_am'),
   'display_name_en'=>data_get($this->resource,'display_name_en'),
   'original_name'=>data_get($this->resource,'original_name'),
   'mime_type'=>data_get($this->resource,'mime_type'),
   'size'=>(int)data_get($this->resource,'size'),
   'version'=>(int)data_get($this->resource,'version'),
   'status'=>data_get($this->resource,'status'),
   'linked_at'=>data_get($this->resource,'linked_at'),
  ];
 }
}
