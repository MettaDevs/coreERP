<?php

namespace App\Platform\Tenant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenant\Actions\RegisterBusiness;
use App\Platform\Tenant\Http\Requests\BusinessRegistrationRequest;
use Illuminate\Http\JsonResponse;

class BusinessRegistrationController extends Controller
{
    public function store(BusinessRegistrationRequest $request, RegisterBusiness $action): JsonResponse
    {
        $user = $action->handle($request->payload());

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], 201);
    }
}
