<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

final class PoDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $step = $this->route('step');
        if (! in_array($step, ['hr', 'gm'], true)) {
            return false;
        }
        $permission = $this->routeIs('*.reject') ? 'po.reject.'.$step : 'po.approve.'.$step;

        return $this->user()?->can($permission) ?? false;
    }

    public function rules(): array
    {
        return $this->routeIs('*.reject')
            ? ['reason' => ['required', 'string', 'min:5', 'max:2000']]
            : [];
    }
}
