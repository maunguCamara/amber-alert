<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\ReporterType;
use Carbon\Carbon;

/**
 * Validated, typed data for a new case submission.
 * Built from StoreCaseRequest — never constructed from raw input.
 */
final readonly class SubmitCaseData
{
    public function __construct(
        public string       $childName,
        public int          $age,
        public string       $gender,
        public string       $clothing,
        public string       $lastSeenArea,
        public string       $county,
        public float        $lat,
        public float        $lng,
        public string       $description,
        public Carbon       $missingSince,
        public string       $circumstanceType,
        public ReporterType $reporterType,
        public ?float       $heightCm,
        public ?string      $complexion,
        public ?string      $distinctive,
        public ?string      $subCounty,
        public ?string      $contactPhone,
    ) {}

    /** @return array<string, mixed> JSON-serialisable for the Go API */
    public function toApiArray(): array
    {
        return array_filter([
            'child_name'        => $this->childName,
            'age'               => $this->age,
            'gender'            => $this->gender,
            'clothing'          => $this->clothing,
            'last_seen_area'    => $this->lastSeenArea,
            'county'            => $this->county,
            'lat'               => $this->lat,
            'lng'               => $this->lng,
            'description'       => $this->description,
            'missing_since'     => $this->missingSince->toISOString(),
            'circumstance_type' => $this->circumstanceType,
            'reporter_type'     => $this->reporterType->value,
            'height_cm'         => $this->heightCm,
            'complexion'        => $this->complexion,
            'distinctive'       => $this->distinctive,
            'sub_county'        => $this->subCounty,
            'contact_phone'     => $this->contactPhone,
        ], fn ($v) => $v !== null);
    }
}