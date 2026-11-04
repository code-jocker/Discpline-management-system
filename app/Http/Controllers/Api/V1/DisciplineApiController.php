<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Core\Request;
use App\Repositories\DisciplineRepository;
use App\Repositories\UserRepository;
use App\Services\DisciplineService;
use Database;
use RuntimeException;

final class DisciplineApiController extends ApiController
{
    public function deduct(Request $request): void
    {
        $authUser = $request->attribute('auth_user');
        if (!is_array($authUser) || empty($authUser['id'])) {
            $this->error('Authentication required.', 401);
            return;
        }

        $payload = [
            'student_id' => ctype_digit((string) $request->input('student_id', ''))
                ? (int) $request->input('student_id')
                : 0,
            'class_id' => ctype_digit((string) $request->input('class_id', ''))
                ? (int) $request->input('class_id')
                : 0,
            'apply_to_class' => filter_var(
                $request->input('apply_to_class', false),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            ) ?? false,
            'action_type' => strtolower((string) $request->input('action_type', 'deduct')),
            'term' => ctype_digit((string) $request->input('term', ''))
                ? (int) $request->input('term')
                : 0,
            'marks_to_deduct' => ctype_digit((string) $request->input('marks_to_deduct', ''))
                ? (int) $request->input('marks_to_deduct')
                : 0,
            'reason' => trim((string) $request->input('reason', '')),
        ];

        $hasStudentSelection = $payload['student_id'] > 0;
        $hasClassSelection = $payload['class_id'] > 0;
        $isClassScope = $payload['apply_to_class'] === true || (!$hasStudentSelection && $hasClassSelection);

        if ($payload['apply_to_class'] && $payload['class_id'] <= 0) {
            $this->error('Please select a class before applying the action to the whole class.', 422);
            return;
        }

        if (($payload['student_id'] <= 0 && !$isClassScope) || !in_array($payload['term'], [1, 2, 3], true)) {
            $this->error('Student or class selection and term are required and must be valid.', 422);
            return;
        }

        if (!in_array($payload['action_type'], ['deduct', 'add', 'set'], true)) {
            $this->error('Action type must be deduct, add, or set.', 422);
            return;
        }

        $isSetAction = $payload['action_type'] === 'set';
        $isMarksValid = $isSetAction
            ? ($payload['marks_to_deduct'] >= 0 && $payload['marks_to_deduct'] <= 40)
            : ($payload['marks_to_deduct'] > 0 && $payload['marks_to_deduct'] <= 40);

        if (!$isMarksValid) {
            $this->error(
                $isSetAction
                    ? 'Marks value must be between 0 and 40 for set actions.'
                    : 'Marks value must be between 1 and 40 for this action.',
                422
            );
            return;
        }

        if (!$isSetAction && ($payload['reason'] === '' || mb_strlen($payload['reason']) < 5)) {
            $this->error('Reason must be at least 5 characters.', 422);
            return;
        }

        $service = new DisciplineService(
            Database::connection(),
            new DisciplineRepository(Database::connection()),
            new UserRepository(Database::connection())
        );

        if (!$service->canDeduct($authUser)) {
            $this->error('You do not have permission to deduct marks.', 403);
            return;
        }

        try {
            $result = $service->deduct($payload, $authUser, $request->ipAddress());
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage(), 422);
            return;
        } catch (\Throwable $exception) {
            Database::logError('API discipline deduction failed', ['exception' => $exception->getMessage()]);
            $this->error('We could not process the deduction right now.', 500);
            return;
        }

        $this->success($result, 'Marks updated successfully.');
    }
}
