<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\StoreServiceOrderRequest;

class StoreMobileOrderRequest extends StoreServiceOrderRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === 'customer';
    }
}
