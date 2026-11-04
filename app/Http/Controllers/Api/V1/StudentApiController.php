<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Core\Request;
use App\Repositories\StudentRepository;
use Database;

final class StudentApiController extends ApiController
{
    public function index(Request $request): void
    {
        $filters = [
            'class_id' => ctype_digit((string) $request->query('class_id', '')) ? (int) $request->query('class_id') : null,
            'category_id' => ctype_digit((string) $request->query('category_id', '')) ? (int) $request->query('category_id') : null,
        ];

        $page = max(1, (int) $request->query('page', 1));
        $limit = max(1, min((int) $request->query('limit', 20), 100));
        $offset = ($page - 1) * $limit;

        $repository = new StudentRepository(Database::connection());
        $students = $repository->paginate($filters, $limit, $offset);
        $total = $repository->count($filters);

        $this->success([
            'items' => $students,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => max(1, (int) ceil($total / $limit)),
            ],
        ], 'Students fetched successfully.');
    }
}
