<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Contracts\PartOfCourse;
use App\Models\Contracts\PartOfRegulation;
use App\Models\Course;
use App\Models\Regulation;
use App\Support\Lms\LearningPlan;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Материал базы знаний открывается по очереди плана обучения, а не когда
 * вздумается.
 *
 * Проверка стоит на входе во всю группу, как и доступ к курсу
 * (EnsureCourseAccess), и по той же причине: маршрутов, ведущих к материалу,
 * два десятка — урок, тест, попытка, вложение, отметка об ознакомлении, — и
 * проверять очередь в каждом значит однажды завести двадцать первый и забыть.
 * Само правило живёт в LearningPlan, здесь только место, где его спрашивают.
 *
 * Отказ — 403 с объяснением, а не 404: материал существует и человеку виден,
 * он лежит в каталоге под замком, и ответ «не найдено» соврал бы читателю о
 * том, что он видит своими глазами. Сообщение уходит наружу как есть — его и
 * показывает экран.
 */
final class EnsureLearningPlanOrder
{
    /**
     * Очередь не касается проверки чужих работ.
     *
     * Аттестацию проверяет тот, кого назначили экзаменатором, — обычный
     * сотрудник, у которого может быть и свой неоконченный план. Закрыть ему
     * присланную работу значит остановить чужое обучение из-за его
     * собственного.
     */
    private const EXEMPT = 'lms.attestations.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! LearningPlan::restrains($user)) {
            return $next($request);
        }

        $name = $request->route()?->getName() ?? '';

        if (str_starts_with($name, self::EXEMPT)) {
            return $next($request);
        }

        $materials = $this->materialsOf($request);

        if ($materials === []) {
            return $next($request);
        }

        $plan = LearningPlan::of($user);

        foreach ($materials as $material) {
            abort_if(! $plan->allows($material), Response::HTTP_FORBIDDEN, $plan->refusal($material));
        }

        return $next($request);
    }

    /**
     * Материалы, которых касается запрос, — они сами или то, что им
     * принадлежит.
     *
     * Часть без владельца пропускается молча: его удалили, и об этом уже
     * сказал EnsureCourseAccess ответом «не найдено».
     *
     * @return list<Course|Regulation>
     */
    private function materialsOf(Request $request): array
    {
        $materials = [];

        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Course || $parameter instanceof Regulation) {
                $materials[] = $parameter;

                continue;
            }

            // Оба вопроса, а не первый подошедший: попытка теста — часть и
            // курса, и документа, и на «не своём» вопросе честно отвечает
            // пустотой (см. QuizAttempt::owningCourse).
            if ($parameter instanceof PartOfCourse && ($course = $parameter->owningCourse()) !== null) {
                $materials[] = $course;
            }

            if ($parameter instanceof PartOfRegulation && ($document = $parameter->owningRegulation()) !== null) {
                $materials[] = $document;
            }
        }

        return $materials;
    }
}
