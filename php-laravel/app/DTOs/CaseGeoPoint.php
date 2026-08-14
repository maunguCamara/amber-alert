<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CaseStatus;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Immutable typed representation of a case geo-point returned by the Go API.
 * Replaces raw ?array with a shape-guaranteed object.
 */
final readonly class CaseGeoPoint
{
    public function __construct(
        public string       $id,
        public string       $referenceNo,
        public string       $childName,
        public int          $age,
        public string       $gender,
        public CaseStatus   $status,
        public string       $county,
        public float        $lat,
        public float        $lng,
        public Carbon       $missingSince,
        public ?string      $thumbnailUrl,
    ) {}

    /**
     * @param array<string, mixed> $data Raw API response array
     * @throws \InvalidArgumentException if required fields are missing or invalid
     */
    public static function fromArray(array $data): self
    {
        // Validate required fields before touching them
        foreach (['id', 'child_name', 'age', 'status', 'county', 'lat', 'lng', 'missing_since'] as $field) {
            if (! array_key_exists($field, $data)) {
                throw new \InvalidArgumentException("CaseGeoPoint: required field '{$field}' is missing from API response");
            }
        }

        $status = CaseStatus::tryFrom((string) $data['status']);
        if ($status === null) {
            throw new \InvalidArgumentException("CaseGeoPoint: unknown status '{$data['status']}'");
        }

        return new self(
            id:           (string) $data['id'],
            referenceNo:  (string) ($data['reference_no'] ?? ''),
            childName:    (string) $data['child_name'],
            age:          (int)    $data['age'],
            gender:       (string) ($data['gender'] ?? 'unknown'),
            status:       $status,
            county:       (string) $data['county'],
            lat:          (float)  $data['lat'],
            lng:          (float)  $data['lng'],
            missingSince: Carbon::parse((string) $data['missing_since']),
            thumbnailUrl: isset($data['thumbnail_url']) ? (string) $data['thumbnail_url'] : null,
        );
    }

    public function missingSinceHuman(): string
    {
        return $this->missingSince->diffForHumans();
    }

    public function isActive(): bool
    {
        return $this->status->isLive();
    }
}