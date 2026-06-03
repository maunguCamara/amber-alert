<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'child_name'        => ['required', 'string', 'max:120'],
            'age'               => ['required', 'integer', 'min:0', 'max:17'],
            'gender'            => ['required', 'in:male,female,unknown'],
            'height_cm'         => ['nullable', 'numeric', 'min:30', 'max:220'],
            'weight_kg'         => ['nullable', 'numeric', 'min:1', 'max:150'],
            'complexion'        => ['nullable', 'string', 'max:80'],
            'clothing'          => ['required', 'string', 'max:255'],
            'distinctive'       => ['nullable', 'string', 'max:500'],
            'languages'         => ['nullable', 'array'],
            'languages.*'       => ['string', 'max:60'],
            'last_seen_area'    => ['required', 'string', 'max:255'],
            'county'            => ['required', 'string', 'in:' . implode(',', self::KENYAN_COUNTIES)],
            'sub_county'        => ['nullable', 'string', 'max:120'],
            'lat'               => ['required', 'numeric', 'between:-5,5'],    // Kenya lat range
            'lng'               => ['required', 'numeric', 'between:34,42'],   // Kenya lng range
            'description'       => ['required', 'string', 'min:20', 'max:2000'],
            'missing_since'     => ['required', 'date', 'before_or_equal:now'],
            'circumstance_type' => ['required', 'in:wandered,abducted,unknown'],
            'reporter_type'     => ['required', 'in:public,police,school,ngo'],
            'contact_phone'     => ['nullable', 'string', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'photo'             => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'], // 10 MB
        ];
    }

    public function messages(): array
    {
        return [
            'child_name.required'    => __('validation.child_name_required'),
            'county.in'              => __('validation.invalid_county'),
            'missing_since.before_or_equal' => __('validation.missing_since_future'),
            'lat.between'            => __('validation.lat_out_of_kenya'),
            'lng.between'            => __('validation.lng_out_of_kenya'),
        ];
    }

    /**
     * Sanitise the input before validation runs.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'child_name'  => strip_tags(trim($this->child_name ?? '')),
            'description' => strip_tags(trim($this->description ?? '')),
        ]);
    }

    // All 47 Kenyan counties.
    const KENYAN_COUNTIES = [
        'Mombasa','Kwale','Kilifi','Tana River','Lamu','Taita-Taveta',
        'Garissa','Wajir','Mandera','Marsabit','Isiolo','Meru',
        'Tharaka-Nithi','Embu','Kitui','Machakos','Makueni','Nyandarua',
        'Nyeri','Kirinyaga','Murang\'a','Kiambu','Turkana','West Pokot',
        'Samburu','Trans Nzoia','Uasin Gishu','Elgeyo-Marakwet','Nandi',
        'Baringo','Laikipia','Nakuru','Narok','Kajiado','Kericho',
        'Bomet','Kakamega','Vihiga','Bungoma','Busia','Siaya','Kisumu',
        'Homa Bay','Migori','Kisii','Nyamira','Nairobi',
    ];
}