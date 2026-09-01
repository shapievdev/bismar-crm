<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Contracts\PartOfCourse;
use App\Models\Contracts\PartOfRegulation;
use App\Support\Lms\CourseAccess;
use App\Support\Lms\RegulationAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ни один маршрут базы знаний не работает с курсом, который человеку закрыт.
 *
 * Права на маршрутах отвечают на вопрос «что этот человек умеет делать»:
 * «редактировать курсы» — одно на все курсы сразу, и о том, какой именно курс
 * открыт, оно не знает ничего. Раньше этого хватало, потому что открыты были
 * все. Проверять доступ отдельно в каждом из двух десятков действий — значит
 * однажды завести двадцать первое и забыть, поэтому проверка стоит на входе,
 * общая для всей группы.
 *
 * То же и с документами: частей у них до недавнего не было вовсе, а с
 * появлением проверки появилась первая — попытка её сдачи.
 *
 * Отказ — 404, а не 403: приватный курс для постороннего не существует, и
 * ответ «нельзя» рассказал бы, что он есть.
 */
final class EnsureCourseAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $access = CourseAccess::of($user);
        $documents = RegulationAccess::of($user);

        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            // Часть документа — попытка сдачи приложенной к нему проверки:
            // её доступ решает сам документ, а не курс.
            if ($parameter instanceof PartOfRegulation) {
                $document = $parameter->owningRegulation();

                if ($document !== null) {
                    abort_unless($documents->allows($document), Response::HTTP_NOT_FOUND);

                    continue;
                }
            }

            if (! $parameter instanceof PartOfCourse) {
                continue;
            }

            // Часть материала, которая не может назвать свой курс, — часть
            // удалённого курса: его удалили мягко, ради прогресса учеников, а
            // читать его больше нельзя, приватный он был или нет.
            $course = $parameter->owningCourse();

            if ($course === null || ! $access->allows($course)) {
                abort(Response::HTTP_NOT_FOUND);
            }
        }

        return $next($request);
    }
}
