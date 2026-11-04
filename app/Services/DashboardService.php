<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\DashboardRepository;

final class DashboardService
{
    private DashboardRepository $repository;

    public function __construct(DashboardRepository $repository)
    {
        $this->repository = $repository;
    }

    public function metrics(?int $term = null): array
    {
        return [
            'overview' => $this->repository->overview(),
            'risk_distribution' => $this->repository->riskDistribution(),
            'top_offenders' => $this->repository->topOffenders(5, $term),
            'class_comparison' => $this->repository->classComparison(),
        ];
    }
}
