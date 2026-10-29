<?php
declare(strict_types=1);
namespace MaaleArna\MASES\Core\Domains\People\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use MaaleArna\MASES\Core\Domains\People\Enums\PersonRelationshipType;
final class StorePersonRelationshipRequest extends FormRequest {
 public function authorize(): bool { return function_exists('mases_tenant_id')&&mases_tenant_id()!==null; }
 public function rules(): array { return ['related_public_id'=>['required','uuid'],'relationship_type'=>['required',Rule::in(PersonRelationshipType::values())],'effective_from'=>['nullable','date'],'effective_to'=>['nullable','date','after_or_equal:effective_from'],'reason'=>['required','string','min:8','max:1000']]; }
}
