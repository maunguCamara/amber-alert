<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\SubmitCaseData;
use App\Enums\ReporterType;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class StoreCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public submissions — no auth required
    }

    public function rules(): array
    {
        return [
            'child_name'        => ['required', 'string', 'max:120'],
            'age'               => ['required', 'integer', 'min:0', 'max:17'],
            'gender'            => ['required', 'in:male,female,unknown'],
            'height_cm'         => ['nullable', 'numeric', 'min:30', 'max:220'],
            'complexion'        => ['nullable', 'string', 'max:80'],
            'clothing'          => ['required', 'string', 'max:255'],
            'distinctive'       => ['nullable', 'string', 'max:500'],
            'last_seen_area'    => ['required', 'string', 'max:255'],
            'county'            => ['required', 'string', 'in:' . implode(',', self::KENYAN_COUNTIES)],
            'sub_county'        => ['nullable', 'string', 'max:120'],
            'lat'               => ['required', 'numeric', 'between:-5,5'],
            'lng'               => ['required', 'numeric', 'between:34,42'],
            'description'       => ['required', 'string', 'min:20', 'max:2000'],
            'missing_since'     => ['required', 'date', 'before_or_equal:now'],
            'circumstance_type' => ['required', 'in:wandered,abducted,unknown'],
            'reporter_type'     => ['required', new Enum(ReporterType::class)],
            'contact_phone'     => ['nullable', 'string', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'photo'             => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'county.in'                     => 'Please select a valid Kenyan county.',
            'lat.between'                   => 'Coordinates are outside Kenya (latitude must be between −5 and +5).',
            'lng.between'                   => 'Coordinates are outside Kenya (longitude must be between 34 and 42).',
            'missing_since.before_or_equal' => 'The date last seen cannot be in the future.',
            'description.min'               => 'Please provide at least 20 characters of description.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'child_name'  => strip_tags(trim((string) ($this->child_name  ?? ''))),
            'description' => strip_tags(trim((string) ($this->description ?? ''))),
        ]);
    }

    /**
     * Build a typed DTO from validated data.
     * Only call after validation passes.
     */
    public function toDTO(): SubmitCaseData
    {
        $v = $this->validated();

        return new SubmitCaseData(
            childName:        (string) $v['child_name'],
            age:              (int)    $v['age'],
            gender:           (string) $v['gender'],
            clothing:         (string) $v['clothing'],
            lastSeenArea:     (string) $v['last_seen_area'],
            county:           (string) $v['county'],
            lat:              (float)  $v['lat'],
            lng:              (float)  $v['lng'],
            description:      (string) $v['description'],
            missingSince:     Carbon::parse((string) $v['missing_since']),
            circumstanceType: (string) $v['circumstance_type'],
            reporterType:     ReporterType::from((string) $v['reporter_type']),
            heightCm:         isset($v['height_cm'])    ? (float)  $v['height_cm']    : null,
            complexion:       isset($v['complexion'])   ? (string) $v['complexion']   : null,
            distinctive:      isset($v['distinctive'])  ? (string) $v['distinctive']  : null,
            subCounty:        isset($v['sub_county'])   ? (string) $v['sub_county']   : null,
            contactPhone:     isset($v['contact_phone'])? (string) $v['contact_phone']: null,
        );
    }

    public const array KENYAN_COUNTIES = [
        'Mombasa','Kwale','Kilifi','Tana River','Lamu','Taita-Taveta',
        'Garissa','Wajir','Mandera','Marsabit','Isiolo','Meru',
        'Tharaka-Nithi','Embu','Kitui','Machakos','Makueni','Nyandarua',
        'Nyeri','Kirinyaga',"Murang'a",'Kiambu','Turkana','West Pokot',
        'Samburu','Trans Nzoia','Uasin Gishu','Elgeyo-Marakwet','Nandi',
        'Baringo','Laikipia','Nakuru','Narok','Kajiado','Kericho',
        'Bomet','Kakamega','Vihiga','Bungoma','Busia','Siaya','Kisumu',
        'Homa Bay','Migori','Kisii','Nyamira','Nairobi',
    ];
}