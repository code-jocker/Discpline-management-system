<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Core\Request;
use App\Repositories\DashboardRepository;
use App\Services\DashboardService;
use Database;

final class DashboardApiController extends ApiController
{
    public function index(Request $request): void
    {
        $service = new DashboardService(new DashboardRepository(Database::connection()));
        $term = $request->query('term');
        $selectedTerm = is_numeric($term) ? (int) $term : null;
        if ($selectedTerm !== null && !in_array($selectedTerm, [1, 2, 3], true)) {
            $selectedTerm = null;
        }

        $this->success($service->metrics($selectedTerm), 'Dashboard metrics fetched successfully.');
    }
}
