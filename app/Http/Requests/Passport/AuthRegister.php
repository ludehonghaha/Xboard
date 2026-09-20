<?php

namespace App\Http\Requests\Passport;

use Illuminate\Foundation\Http\FormRequest;

class AuthRegister extends FormRequest
{
    public function rules()
    {
        return [
            'email' => 'required|email:strict',
            'password' => 'required|min:8',
            'invite_code' => 'required|string|min:6|max:32',
        ];
    }

    public function messages()
    {
        return [
            'email.required' => __('Email can not be empty'),
            'email.email' => __('Email format is incorrect'),
            'password.required' => __('Password can not be empty'),
            'password.min' => __('Password must be greater than 8 digits'),
            'invite_code.required' => __('You must use the invitation code to register'),
        ];
    }
}
