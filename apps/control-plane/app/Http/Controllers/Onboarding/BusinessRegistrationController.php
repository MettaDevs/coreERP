<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\RegisterBusiness;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\BusinessRegistrationRequest;
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
