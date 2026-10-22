<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Enums\AccessLevel;
use App\Enums\DismissalReason;
use App\Exceptions\ConflictException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Увольнение, правка его даты и возвращение в строй.
 *
 * Уволенный не удаляется: запись остаётся со всем, что за ней числится, —
 * авторством курсов, сообщениями в переписках, вопросами к консультанту, — но
 * платформа для него закрыта. Войти он не может, в списках, из которых
 * выбирают человека, не появляется, а открытые сессии обрываются в тот же миг.
 *
 * Все три действия живут в одном классе, потому что правило «кого можно
 * тронуть» у них общее: вернуть в строй и переписать день ухода может ровно
 * тот, кто мог уволить.
 *
 * Правила проверяются здесь, а не в политике, по той же причине, что и в
 * SyncUserAccess: сдерживают они как раз тех, кого Gate::before пропускает
 * не глядя, — и до политики дело бы не дошло.
 */
final readonly class ChangeEmployment
{
    /**
     * @param  DismissalReason|null  $reason  почему ушёл: без неё доли в отчёте
     *                                        не сложатся, но и требовать её от
     *                                        старых увольнений задним числом
     *                                        нечем
     *
     * @throws ConflictException
     */
    public function dismiss(User $user, User $actor, ?DismissalReason $reason = null): User
    {
        if ($user->isDismissed()) {
            throw new ConflictException('Сотрудник уже уволен.');
        }

        // Иначе увольняющий закрыл бы дверь с той стороны, где стоит сам:
        // отменить это было бы уже нечем.
        if ($user->is($actor)) {
            throw new ConflictException('Нельзя уволить самого себя.');
        }

        $this->ensureStandingMayBeTouchedBy($user, $actor);
        $this->ensureAnEmployedSuperAdminRemains($user);

        $user->forceFill([
            'dismissed_at' => now(),
            'dismissed_by_id' => $actor->getKey(),
            'dismissal_reason' => $reason,
        ])->save();

        $this->signOutEverywhere($user);

        // Уведомления тоже: звать в закрытую платформу незачем, а подписка
        // жила бы на телефоне ушедшего, пока он сам её не отключит.
        $user->pushSubscriptions()->delete();

        return $user->load('roles', 'permissions');
    }

    /**
     * Поправить день увольнения.
     *
     * Кнопка «уволить» ставит сегодняшний день, а оформляют уход обычно позже:
     * человек ушёл пятнадцатого, кадровик дошёл до записи двадцатого. Без правки
     * эти пять дней остались бы в численности, в стаже ушедшего и в месяце, на
     * который списали увольнение, — отчёт о движении персонала считает по датам,
     * а не по тому, когда нажали кнопку.
     *
     * Правит, стало быть, прошлое — и это здесь не беда, а цель: отчёт обязан
     * показывать, как было, а не как записали. Поэтому и проверки те же, что у
     * самого увольнения: кого нельзя уволить, тому нельзя и переписать дату.
     *
     * Время дня — конец: день увольнения человек ещё числится в штате, как и при
     * увольнении кнопкой, когда дата приходит с часами. Иначе та же дата,
     * поставленная руками, вычла бы из численности лишний день.
     *
     * @throws ConflictException
     */
    public function correctDismissalDate(User $user, User $actor, CarbonImmutable $date): User
    {
        if (! $user->isDismissed()) {
            throw new ConflictException('Сотрудник работает — даты увольнения у него нет.');
        }

        $this->ensureStandingMayBeTouchedBy($user, $actor);

        $user->forceFill(['dismissed_at' => $date->endOfDay()])->save();

        return $user->load('roles', 'permissions');
    }

    /**
     * @throws ConflictException
     */
    public function reinstate(User $user, User $actor): User
    {
        if (! $user->isDismissed()) {
            throw new ConflictException('Сотрудник и так работает.');
        }

        $this->ensureStandingMayBeTouchedBy($user, $actor);

        // Уровень доступа и отмеченные права возвращаются те же: увольнение их
        // не снимало, и вернувшийся продолжает с того места, где остановился.
        $user->forceFill([
            'dismissed_at' => null,
            'dismissed_by_id' => null,

            // Причина уходит вместе с увольнением: вернувшийся не «ушедший по
            // собственному, который передумал», а работающий сотрудник.
            'dismissal_reason' => null,
        ])->save();

        return $user->load('roles', 'permissions');
    }

    /**
     * Кого этот человек вправе уволить.
     *
     * Та же лестница, что и у назначений: администратор распоряжается
     * обыкновенными сотрудниками и другими администраторами, а суперадминистратор
     * — дело одного суперадминистратора. Ниже администратора не увольняет никто:
     * право «управлять пользователями» позволяет заводить людей и отмечать им
     * права, но не закрывать вход тому, кто выше.
     *
     * @throws ConflictException
     */
    private function ensureStandingMayBeTouchedBy(User $user, User $actor): void
    {
        $actorLevel = $actor->accessLevel();

        if ($actorLevel === AccessLevel::SuperAdmin) {
            return;
        }

        if ($actorLevel !== AccessLevel::Admin) {
            throw new ConflictException('Увольнять и возвращать сотрудников может только администратор.');
        }

        if ($user->accessLevel() === AccessLevel::SuperAdmin) {
            throw new ConflictException('Увольнять суперадминистраторов может только суперадминистратор.');
        }
    }

    /**
     * Оставляет в строю хотя бы одного суперадминистратора: уволив последнего,
     * назначить нового было бы уже некому — и то, что открыто только ему,
     * настройки консультанта в том числе, закрылось бы навсегда.
     *
     * Сегодня сюда не дойти: суперадминистратора увольняет только
     * суперадминистратор, а себя не увольняет никто, — значит один работающий
     * всегда остаётся, и это он и есть. Проверка стоит на случай, если правило
     * выше однажды смягчат, — как и такая же в SyncUserAccess.
     *
     * @throws ConflictException
     */
    private function ensureAnEmployedSuperAdminRemains(User $user): void
    {
        if ($user->accessLevel() !== AccessLevel::SuperAdmin) {
            return;
        }

        $others = User::query()
            ->role(AccessLevel::SuperAdmin->value)
            ->employed()
            ->whereKeyNot($user->getKey())
            ->exists();

        if (! $others) {
            throw new ConflictException('В системе должен остаться хотя бы один работающий суперадминистратор.');
        }
    }

    /**
     * Обрывает открытые сессии уволенного.
     *
     * Без этого страница, оставшаяся открытой, дожила бы до конца своей сессии:
     * EnsureEmployed вернул бы 401 на следующем запросе, но `/broadcasting/auth`
     * и сокет мессенджера идут своей дорогой, и подпись им выдала бы та же
     * сессия. Строки сессий лежат в базе — драйвер проверяется, чтобы на
     * стенде с другим хранилищем увольнение не падало на пустом месте.
     */
    private function signOutEverywhere(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->delete();
    }
}
