<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Regulation;
use App\Models\User;
use App\Support\Lms\Favourites;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Избранное: что сотрудник отложил себе сам.
 *
 * Своё и только своё — чужого избранного в приложении нет нигде, и номера
 * человека в адресах поэтому тоже нет: отвечают всегда про того, кто спросил.
 *
 * Право на маршруте — любое из трёх разделов базы знаний, как у корзины: иначе
 * продавец, которому открыты одни справочники, не увидел бы собственных
 * закладок. А что именно ему можно отложить, решает не маршрут, а видимость
 * самого материала (см. App\Support\Lms\Favourites).
 */
final class FavouriteController extends Controller
{
    /**
     * Виды в адресах — словами, которыми материал зовут на экране.
     *
     * «materials» на документы и справочники одно: таблица у них общая, а
     * раздел берётся из самого материала (см. App\Enums\MaterialKind). Слова
     * «regulation» в адресах нет намеренно — оно осталось в именах таблиц и
     * классов, где ничего не обещает читателю.
     */
    private const MODELS = [
        'courses' => Course::class,
        'lessons' => Lesson::class,
        'materials' => Regulation::class,
    ];

    /** Для `whereIn` в маршрутах: один список на адреса и на разбор. */
    public static function kinds(): array
    {
        return array_keys(self::MODELS);
    }

    /**
     * Всё отложенное — одним списком, курсы и материалы вперемешку.
     *
     * Вместе, а не по разделам: вопрос у человека один — «что я откладывал», — и
     * помнить, курсом это было или справочником, чтобы найти своё, ему незачем.
     * Разложить список обратно по видам можно прямо на странице.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $reader */
        $reader = $request->user();

        return response()->json(['data' => Favourites::of($reader)->list()->all()]);
    }

    public function store(Request $request, string $kind, int $id): JsonResponse
    {
        /** @var User $reader */
        $reader = $request->user();

        $favourites = Favourites::of($reader);
        $material = $this->material($favourites, $kind, $id);

        $favourites->keep($material);

        return response()->json(['data' => ['is_favourite' => true]]);
    }

    public function destroy(Request $request, string $kind, int $id): JsonResponse
    {
        /** @var User $reader */
        $reader = $request->user();

        $favourites = Favourites::of($reader);

        $favourites->forget($this->material($favourites, $kind, $id));

        return response()->json(['data' => ['is_favourite' => false]]);
    }

    /**
     * Материал, который человек просит отложить или забыть.
     *
     * Невидимый отвечает «не найдено», а не «нельзя»: для этого человека такого
     * материала не существует — то же, что и везде в базе знаний. Выброшенный в
     * корзину сюда не попадает вовсе: связь ищет среди живых.
     */
    private function material(Favourites $favourites, string $kind, int $id): Model
    {
        $class = self::MODELS[$kind] ?? null;

        abort_if($class === null, HttpResponse::HTTP_NOT_FOUND);

        /** @var Model|null $material */
        $material = $class::query()->whereKey($id)->first();

        abort_if($material === null, HttpResponse::HTTP_NOT_FOUND);
        abort_unless($favourites->mayKeep($material), HttpResponse::HTTP_NOT_FOUND);

        return $material;
    }
}
