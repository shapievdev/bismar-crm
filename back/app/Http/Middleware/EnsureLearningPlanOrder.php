<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Contracts\PartOfCourse;
use App\Models\Course;
use App\Support\Lms\LearningPlan;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Курс открывается по очереди плана обучения, а не когда вздумается.
 *
 * Проверка стоит на входе во всю группу, как и доступ к курсу
 * (EnsureCourseAccess), и по той же причине: маршрутов, ведущих к курсу, два
 * десятка — урок, тест, попытка, вложение, — и проверять очередь в каждом
 * значит однажды завести двадцать первый и забыть. Само правило живёт в
 * LearningPlan, здесь только место, где его спрашивают.
 *
 * Отказ — 403 с объяснением, а не 404: курс существует и человеку виден, он
 * лежит в каталоге под замком, и ответ «не найдено» соврал бы читателю о том,
 * что он видит своими глазами. Сообщение уходит наружу как есть — его и
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

        $courses = $this->coursesOf($request);

        if ($courses === []) {
            return $next($request);
        }

        $plan = LearningPlan::of($user);

        foreach ($courses as $course) {
            abort_if(! $plan->allows($course), Response::HTTP_FORBIDDEN, $plan->refusal());
        }

        return $next($request);
    }

    /**
     * Курсы, которых касается запрос, — сам курс или то, что ему принадлежит.
     *
     * Документы и справочники здесь не спрашиваются вовсе: очередь их не
     * держит (решение пользователя 2026-09-07), и правило это выражено самим
     * отсутствием проверки, а не ветвлением внутри неё.
     *
     * Часть без курса пропускается молча: её курс удалён, и об этом уже сказал
     * EnsureCourseAccess ответом «не найдено».
     *
     * @return list<Course>
     */
    private function coursesOf(Request $request): array
    {
        $courses = [];

        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            $course = match (true) {
                $parameter instanceof Course => $parameter,
                $parameter instanceof PartOfCourse => $parameter->owningCourse(),
                default => null,
            };

            if ($course !== null) {
                $courses[] = $course;
            }
        }

        return $courses;
    }
}
