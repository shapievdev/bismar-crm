<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\News;

use App\Actions\News\AcknowledgeNews;
use App\Exceptions\ConflictException;
use App\Http\Controllers\Controller;
use App\Http\Resources\News\NewsPersonResource;
use App\Models\News;
use App\Models\User;
use App\Support\News\Addressees;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * «Прочитал и понял» — и кто ещё этого не сделал.
 */
final class NewsAcknowledgementController extends Controller
{
    public function __construct(private readonly Addressees $addressees) {}

    /**
     * Отметиться самому.
     *
     * @throws ConflictException
     */
    public function store(Request $request, News $news, AcknowledgeNews $acknowledge): JsonResponse
    {
        Gate::authorize('acknowledge', $news);

        /** @var User $reader */
        $reader = $request->user();

        // При тесте кнопки нет вовсе: подтверждением служит сдача (решение
        // пользователя 2026-08-27). Иначе нажатие обесценивало бы проверку.
        if ($news->quiz()->exists()) {
            throw new ConflictException('К этой новости приложена проверка — ознакомление засчитывается по ней.');
        }

        $acknowledgement = $acknowledge->handle($news, $reader);

        return response()->json([
            'data' => [
                'is_acknowledged' => true,
                'acknowledged_at' => $acknowledgement->acknowledged_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Кто ознакомился и кто нет.
     *
     * Оба списка сразу: «кто не прочитал» — это и есть вопрос, ради которого
     * сюда заходят, а собирать его на клиенте вычитанием одного из другого
     * значит присылать список всех сотрудников ради десяти фамилий.
     */
    public function index(News $news): JsonResponse
    {
        Gate::authorize('viewAcknowledgements', $news);

        $audience = $this->audienceOf($news);
        $acknowledgements = $news->acknowledgements()->get()->keyBy('user_id');

        [$done, $pending] = $audience->partition(
            fn (User $person): bool => $acknowledgements->has($person->getKey()),
        );

        $done->each(function (User $person) use ($acknowledgements): void {
            $row = $acknowledgements->get($person->getKey());

            $person->setAttribute('acknowledged_at', $row?->acknowledged_at?->toIso8601String());
            $person->setAttribute('acknowledged_via', $row?->source->label());
        });

        return response()->json([
            'data' => [
                'acknowledged' => NewsPersonResource::collection($done->values())->resolve(),
                'pending' => NewsPersonResource::collection($pending->values())->resolve(),
            ],
        ]);
    }

    /**
     * Кому новость адресована.
     *
     * Для новости «всем» это все сотрудники: список тех, кто не прочитал,
     * иначе оказался бы пустым просто потому, что поимённого списка нет.
     *
     * @return Collection<int, User>
     */
    private function audienceOf(News $news): Collection
    {
        // Одним запросом по людям, а не по связи адресатов: у связи в выборку
        // попадают колонки сводной таблицы и затирают `id` человека своим —
        // список выглядит правильным, а номера в нём чужие. Отделы и группы
        // разворачиваются в тех же людей, по одному разу каждого.
        return $this->addressees->query($news)
            ->orderByRaw('COALESCE(last_name, first_name) COLLATE "und-x-icu"')
            ->orderByRaw('first_name COLLATE "und-x-icu"')
            ->get();
    }
}
