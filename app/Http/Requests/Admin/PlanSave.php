<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class PlanSave extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'nullable|integer',
            'name' => 'required|string|max:255',
            'content' => 'nullable|string',
            'reset_traffic_method' => 'integer|nullable',
            'transfer_enable' => 'integer|required|min:1',
            'group_id' => 'integer|nullable',
            'speed_limit' => 'integer|nullable|min:0',
            'device_limit' => 'integer|nullable|min:0',
            'capacity_limit' => 'integer|nullable|min:0',
            'tags' => 'array|nullable',
            'show' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => '套餐名称不能为空',
            'name.max' => '套餐名称不能超过 255 个字符',
            'transfer_enable.required' => '流量配额不能为空',
            'transfer_enable.integer' => '流量配额必须是整数',
            'transfer_enable.min' => '流量配额必须大于 0',
            'group_id.integer' => '权限组ID必须是整数',
            'speed_limit.integer' => '速度限制必须是整数',
            'speed_limit.min' => '速度限制不能为负数',
            'device_limit.integer' => '设备限制必须是整数',
            'device_limit.min' => '设备限制不能为负数',
            'capacity_limit.integer' => '容量限制必须是整数',
            'capacity_limit.min' => '容量限制不能为负数',
            'tags.array' => '标签格式必须是数组',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'data' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()->toArray(),
            ], 422)
        );
    }
}
