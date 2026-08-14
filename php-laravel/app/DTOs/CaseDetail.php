<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CaseStatus;
use Carbon\Carbon;

/**
 * Full case detail as returned by GET /api/v1/cases/:id
 */
final readonly class CaseDetail
{
    /** @param list<array{url: string, thumb_url: string, is_primary: bool}> $photos */
    public function __construct(
        public string     $id,
        public string     $referenceNo,
        public string     $childName,
        public int        $age,
        public string     $gender,
        public CaseStatus $status,
        public string     $county,
        public string     $lastSeenArea,
        public float      $lat,
        public float      $lng,
        public string     $description,
        public Carbon     $missingSince,
        public string     $clothing,
        public string     $distinctive,
        public ?string    $resolution,
        public ?Carbon    $resolvedAt,
        public array      $photos,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        foreach (['id', 'child_name', 'age', 'status', 'county', 'last_seen_area', 'missing_since'] as $field) {
            if (! array_key_exists($field, $data)) {
                throw new \InvalidArgumentException("CaseDetail: required field '{$field}' missing");
            }
        }

        $status = CaseStatus::tryFrom((string) $data['status']);
        if ($status === null) {
            throw new \InvalidArgumentException("CaseDetail: unknown status '{$data['status']}'");
        }

        return new self(
            id:           (string) $data['id'],
            referenceNo:  (string) ($data['reference_no'] ?? ''),
            childName:    (string) $data['child_name'],
            age:          (int)    $data['age'],
            gender:       (string) ($data['gender'] ?? 'unknown'),
            status:       $status,
            county:       (string) $data['county'],
            lastSeenArea: (string) $data['last_seen_area'],
            lat:          (float)  ($data['last_seen_lat'] ?? 0.0),
            lng:          (float)  ($data['last_seen_lng'] ?? 0.0),
            description:  (string) ($data['description']  ?? ''),
            missingSince: Carbon::parse((string) $data['missing_since']),
            clothing:     (string) ($data['clothing']     ?? ''),
            distinctive:  (string) ($data['distinctive']  ?? ''),
            resolution:   isset($data['resolution'])  ? (string) $data['resolution']  : null,
            resolvedAt:   isset($data['resolved_at'])
                ? Carbon::parse((string) $data['resolved_at'])
                : null,
            photos:       (array) ($data['photos'] ?? []),
        );
    }

    public function primaryPhotoUrl(): ?string
    {
        foreach ($this->photos as $photo) {
            if (($photo['is_primary'] ?? false) === true) {
                return $photo['url'];
            }
        }
        return $this->photos[0]['url'] ?? null;
    }
}