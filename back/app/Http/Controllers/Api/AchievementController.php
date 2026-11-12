<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Achievements\StoreAchievementImage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Achievements\SaveAchievementRequest;
use App\Http\Requests\Achievements\StoreAchievementImageRequest;
use App\Http\Resources\AchievementResource;
use App\Models\Achievement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Каталог достижений.
 *
 * Читать его может всякий, кто вошёл, — по той же причине, по какой открыт
 * справочник тегов: значок стоит в чужом профиле и в этапе маршрута, и без
 * названий оба превратились бы в наборы номеров. Правит каталог
 * `achievements.manage`.
 *
 * Выдачи здесь нет: достижение не вручают, его зарабатывают закрытым шагом
 * (см. App\Actions\Onboarding\AwardStageAchievements). Нет и отзыва — потому
 * удаление мягкое: значок убирают из выбора, а у тех, кто его получил, он
 * остаётся.
 */
final class AchievementController extends Controller
{
    /**
     * Весь каталог.
     *
     * Спрятанные приходят только по просьбе (`?hidden=1`) — это экран
     * администратора, который хочет вернуть убранное; в выборе при шаге их
     * быть не должно.
     */
    public function index(Request $request): array
    {
        $achievements = Achievement::query()
            ->when(
                $request->boolean('hidden'),
                fn ($query) => $query->onlyTrashed(),
            )
            ->withCount('awards')
            ->ordered()
            ->get();

        return ['data' => AchievementResource::collection($achievements)->resolve()];
    }

    public function store(SaveAchievementRequest $request): AchievementResource
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var array<string, mixed> $attributes */
        $attributes = $request->validated();

        $achievement = Achievement::query()->create([
            ...$attributes,
            'created_by' => $actor->getKey(),
        ]);

        return AchievementResource::make($achievement->loadCount('awards'));
    }

    public function update(SaveAchievementRequest $request, Achievement $achievement): AchievementResource
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $request->validated();

        $achievement->update($attributes);

        return AchievementResource::make($achievement->loadCount('awards'));
    }

    /**
     * Картинка значка — отдельным адресом, как аватарка: форма шлёт её
     * файлом, а остальные поля — обычным JSON.
     */
    public function storeImage(
        StoreAchievementImageRequest $request,
        Achievement $achievement,
        StoreAchievementImage $store,
    ): AchievementResource {
        return AchievementResource::make(
            $store->handle($achievement, $request->file('image'))->loadCount('awards'),
        );
    }

    public function destroyImage(Achievement $achievement, StoreAchievementImage $store): AchievementResource
    {
        return AchievementResource::make($store->remove($achievement)->loadCount('awards'));
    }

    /**
     * Прячет значок: из выбора при шаге он пропадает, из профилей — нет.
     *
     * Тег сотрудника при удалении снимается со всех, и это верно для ярлыка.
     * Достижение — заслуга, и отменить её правкой справочника нельзя: шаги,
     * на которых оно висело, перестанут его выдавать, а полученное останется.
     */
    public function destroy(Achievement $achievement): Response
    {
        $achievement->delete();

        return response()->noContent();
    }

    public function restore(int $achievement): AchievementResource
    {
        $hidden = Achievement::onlyTrashed()->findOrFail($achievement);

        $hidden->restore();

        return AchievementResource::make($hidden->loadCount('awards'));
    }
}
