<?php

namespace App\Http\Requests;

use App\Models\EmergencyResponse;
use Illuminate\Support\Facades\Gate;

class AdminResponseRequest extends ParentResponseRequest
{
    public function authorize(): bool
    {
        $response = $this->route('response');
        if (! $response instanceof EmergencyResponse) {
            return false;
        }
        Gate::authorize('update', $response->allocation);

        return true;
    }
}
