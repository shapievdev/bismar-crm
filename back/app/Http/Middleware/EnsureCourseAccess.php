<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Contracts\PartOfCourse;
use App\Support\Lms\CourseAccess;
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

        foreach ($request->route()?->parameters() ?? [] as $parameter) {
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
