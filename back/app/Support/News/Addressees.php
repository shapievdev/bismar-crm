<?php

declare(strict_types=1);

namespace App\Support\News;

use App\Enums\NewsAudience;
use App\Models\News;
use App\Models\User;
use App\Support\Structure\DepartmentReach;
use Illuminate\Database\Eloquent\Builder;

/**
 * Кому адресована новость.
 *
 * Адресатов у неё три вида и они складываются: названные поимённо, отделы
 * вместе с подотделами и группы. Состав отдела и группы читается на каждом
 * обращении, а не замораживается при публикации, — пришедший в отдел завтра
 * увидит адресованное отделу вчера, и это ровно то, ради чего адресуют отделу,
 * а не двадцати фамилиям.
 *
 * Вопрос один, но задают его с двух концов, и потому здесь два метода:
 * «кому эта новость» (уведомление, знаменатель ознакомлений, список
 * непрочитавших) и «какие новости этому человеку» (лента). Держать их врозь
 * значило бы однажды расширить один и забыть про другой — и показать в ленте
 * то, чего в знаменателе нет.
 */
final readonly class Addressees
{
    public function __construct(private DepartmentReach $reach) {}

    /**
     * Люди, которых новость называет.
     *
     * Уволенные не отсеиваются: одному вызывающему нужны те, кому шлют
     * уведомление, другому — знаменатель ознакомлений, и сужать это за них
     * значит решать чужой вопрос.
     *
     * @return Builder<User>
     */
    public function query(News $news): Builder
    {
        $people = User::query();

        if (! $news->audience->isSelected()) {
            return $people;
        }

        $named = $news->recipients()->pluck('users.id')->map(intval(...))->all();
        $branch = $this->reach->branch(
            $news->departments()->pluck('departments.id')->map(intval(...))->all(),
        );
        $groups = $news->groups()->pluck('groups.id')->map(intval(...))->all();

        // Пустой список даёт `0 = 1`, а не «все»: новость, у которой не выбрано
        // ни одного адресата, не адресована никому.
        return $people->where(fn (Builder $query): Builder => $query
            ->whereIn('users.id', $named)
            ->orWhereHas('departments', fn (Builder $inner) => $inner->whereIn('departments.id', $branch))
            ->orWhereHas('groups', fn (Builder $inner) => $inner->whereIn('groups.id', $groups)));
    }

    public function includes(News $news, User $user): bool
    {
        return $this->query($news)->whereKey($user->getKey())->exists();
    }

    /**
     * Сужает список новостей до адресованных этому человеку.
     *
     * Считается с его стороны: отделов у человека единицы, и «мои отделы и все,
     * что над ними» вычисляется один раз, — тогда как разбор куста у каждой
     * новости пришлось бы делать для всей ленты.
     *
     * @param  Builder<News>  $query
     */
    public function restrictToReader(Builder $query, User $reader): void
    {
        $reaching = $this->reach->reaching($reader);
        $groups = $reader->groups()->pluck('groups.id')->map(intval(...))->all();

        $query->where(fn (Builder $inner) => $inner
            ->where('audience', NewsAudience::Everyone)
            ->orWhereHas('recipients', fn (Builder $people) => $people->whereKey($reader->getKey()))
            ->orWhereHas('departments', fn (Builder $units) => $units->whereIn('departments.id', $reaching))
            ->orWhereHas('groups', fn (Builder $units) => $units->whereIn('groups.id', $groups)));
    }
}
