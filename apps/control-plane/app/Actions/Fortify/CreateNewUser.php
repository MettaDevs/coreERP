<?php

namespace App\Actions\Fortify;

use App\Actions\Onboarding\RegisterBusiness;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private readonly RegisterBusiness $registerBusiness) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array{name:string,email:string,password:string,business_name:string,module_ids:list<string>}  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'business_name' => ['required', 'string', 'max:255'],
            'module_ids' => ['required', 'array', 'min:1'],
            'module_ids.*' => ['required', 'string', 'distinct', 'exists:modules,id'],
            'password' => $this->passwordRules(),
        ])->validate();

        return $this->registerBusiness->handle([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'business_name' => $input['business_name'],
            'module_ids' => $input['module_ids'],
        ]);
    }
}
