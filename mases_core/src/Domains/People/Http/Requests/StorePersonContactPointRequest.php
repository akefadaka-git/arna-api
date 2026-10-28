<?php
declare(strict_types=1);
namespace MaaleArna\MASES\Core\Domains\People\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use MaaleArna\MASES\Core\Domains\People\Enums\PersonContactType;
final class StorePersonContactPointRequest extends FormRequest {
 public function authorize(): bool { return function_exists('mases_tenant_id')&&mases_tenant_id()!==null; }
 public function rules(): array { return ['contact_type'=>['required',Rule::in(PersonContactType::values())],'label'=>['nullable','string','max:120'],'contact_value'=>['required','string','min:2','max:320'],'is_primary'=>['sometimes','boolean']]; }
}
