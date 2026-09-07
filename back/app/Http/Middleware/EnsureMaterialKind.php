<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\MaterialKind;
use App\Models\Regulation;
use App\Models\RegulationCategory;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * В каком разделе мы находимся: в документах или в справочниках.
 *
 * Оба вида ведут одни и те же контроллеры — устроены они одинаково, и разница
 * только в назначении (см. App\Enums\MaterialKind). Вид приходит с маршрута
 * и кладётся в запрос: списку он говорит, что показывать, созданию — что
 * заводить.
 *
 * Здесь же он и сторожит. Адрес материала уникален по всей таблице, поэтому
 * страница документа открылась бы и по адресу справочника: тот же материал, но
 * крошки, соседи и кнопка «назад» — из чужого раздела. Адреса, которого в этом
 * разделе нет, быть не должно — и ответ на него «не найдено».
 *
 * Стоит после подстановки моделей: сравнивать вид надо уже с найденной
 * строкой, а не с её адресом.
 */
final class EnsureMaterialKind
{
    public function handle(Request $request, Closure $next, string $kind): Response
    {
        $wanted = MaterialKind::from($kind);

        $request->attributes->set(MaterialKind::REQUEST_KEY, $wanted);

        $material = $request->route('regulation');
        $category = $request->route('category');

        if ($material instanceof Regulation && $material->kind !== $wanted) {
            abort(Response::HTTP_NOT_FOUND);
        }

        if ($category instanceof RegulationCategory && $category->kind !== $wanted) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $next($request);
    }
}
