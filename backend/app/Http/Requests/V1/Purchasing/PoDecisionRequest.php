<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Purchasing;

use App\Domain\Purchasing\Actions\PurchaseOrderWorkflow;
use Illuminate\Foundation\Http\FormRequest;

final class PoDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $step = $this->route('step');
        $user = $this->user();
        $decision = $this->routeIs('*.reject') ? 'reject' : 'approve';

        return is_string($step) && $user !== null && PurchaseOrderWorkflow::canDecide($user, $step, $decision);
    }

    public function rules(): array
    {
        return $this->routeIs('*.reject')
            ? ['reason' => ['required', 'string', 'min:5', 'max:2000']]
            : [];
    }
}
