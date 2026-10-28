<?php
declare(strict_types=1);
namespace MaaleArna\MASES\Core\Domains\People\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use MaaleArna\MASES\Core\Domains\People\Enums\PersonParticipationType;
final class StorePersonParticipationRequest extends FormRequest {
 public function authorize(): bool { return function_exists('mases_tenant_id')&&mases_tenant_id()!==null; }
 public function rules(): array { return ['participation_type'=>['required',Rule::in(PersonParticipationType::values())],'title'=>['required','string','min:2','max:180'],'reference_code'=>['nullable','string','max:100'],'effective_from'=>['nullable','date'],'effective_to'=>['nullable','date','after_or_equal:effective_from'],'reason'=>['required','string','min:8','max:1000']]; }
}
