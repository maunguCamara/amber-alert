<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\CaseDetail;
use App\DTOs\CaseGeoPoint;
use App\DTOs\SubmitCaseData;
use App\Exceptions\ApiException;
use App\Exceptions\ApiNotFoundException;
use App\Exceptions\ApiUnauthorizedException;

interface AlertApiContract
{
    /**
     * Fetch all geo-points for the map.
     *
     * @param  int     $limit  Max results (default 500)
     * @param  ?string $county Filter by county (null = all)
     * @param  ?string $status Filter by status (null = all)
     * @return list<CaseGeoPoint>
     * @throws ApiException on any failure
     */
    public function getGeoPoints(int $limit = 500, ?string $county = null, ?string $status = null): array;

    /**
     * @throws ApiNotFoundException if the case does not exist
     * @throws ApiException         on other failures
     */
    public function getCase(string $id): CaseDetail;

    /**
     * @return array{id: string, reference_no: string}
     * @throws ApiUnauthorizedException if the token is invalid
     * @throws ApiException             on other failures
     */
    public function createCase(SubmitCaseData $data, string $apiToken): array;

    /**
     * @throws ApiUnauthorizedException
     * @throws ApiException
     */
    public function updateStatus(string $id, string $status, string $resolution, string $apiToken): void;

    /**
     * @throws ApiUnauthorizedException
     * @throws ApiException
     */
    public function broadcastCase(string $id, string $apiToken): void;

    /** @return array{active: int, review: int, resolved: int, total: int} */
    public function getStats(): array;
}