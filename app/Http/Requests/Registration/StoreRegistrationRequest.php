<?php

namespace App\Http\Requests\Registration;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /*
         * Registration submission is intentionally public.
         *
         * The Center is determined by the route and the person
         * cannot select Role, Branch, lifecycle state, or reviewer.
         */
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (
            [
                'national_id_number',
                'full_name',
                'city_of_residence',
                'phone_number',
            ] as $field
        ) {
            if ($this->has($field)) {
                $normalized[$field] =
                    trim(
                        (string) $this->input(
                            $field
                        )
                    );
            }
        }

        if ($this->has('email')) {
            $normalized['email'] =
                Str::lower(
                    trim(
                        (string) $this->input(
                            'email'
                        )
                    )
                );
        }

        $this->merge(
            $normalized
        );
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'national_id_number' => [
                'required',
                'string',
                'max:50',
            ],

            'full_name' => [
                'required',
                'string',
                'max:255',
            ],

            'date_of_birth' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],

            'city_of_residence' => [
                'required',
                'string',
                'max:150',
            ],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
            ],

            'phone_number' => [
                'required',
                'string',
                'max:50',

                /*
                 * International E.164-style number.
                 *
                 * Examples:
                 * +970599123456
                 * +15551234567
                 */
                'regex:/^\+[1-9][0-9]{7,14}$/',
            ],

            'personal_picture' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone_number.regex' =>
            'The phone number must include a valid international country code.',

            'personal_picture.image' =>
            'The personal picture must be a valid image.',

            'personal_picture.mimes' =>
            'The personal picture must be a JPG, JPEG, PNG, or WEBP image.',

            'personal_picture.max' =>
            'The personal picture may not be larger than 5 MB.',
        ];
    }
}
