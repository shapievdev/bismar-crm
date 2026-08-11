<?php

declare(strict_types=1);

namespace App\Providers;

use Anthropic\Client;
use App\Models\Course;
use App\Models\User;
use App\Support\Ai\Embedder;
use App\Support\Ai\ModelSettings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bound as a singleton so tests can swap it for a double. Built from
        // the stored settings, which a superadmin edits from the interface and
        // which fall back to the environment field by field.
        $this->app->singleton(Client::class, static fn (): Client => ModelSettings::current()->client());

        $this->app->bind(ModelSettings::class, static fn (): ModelSettings => ModelSettings::current());

        // Один на запрос: его спрашивают «настроен ли смысловой поиск» в цикле
        // по строкам таблицы, а каждый новый экземпляр читает настройки из базы
        // заново. Настройки в пределах запроса не меняются — кроме экрана, где
        // их и правят, а он эмбеддер не трогает.
        $this->app->singleton(Embedder::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerAdministratorBypass();
    }

    /**
     * Administrators pass every authorisation check.
     *
     * This keeps a newly added permission from silently locking administrators
     * out before the seeder has run. Returning null for everyone else lets the
     * normal gates and policies decide.
     *
     * Note: this bypasses policies wholesale, so a rule that must hold even for
     * administrators cannot live in a policy — enforce it in the action itself.
     * The one thing an administrator may not do — appoint other administrators
     * — is guarded in SyncUserAccess for exactly that reason.
     *
     * Проверки о конкретном курсе — исключение: решает их CoursePolicy, и
     * пропуск для неё снят. Приватный курс закрыт и от администратора, а
     * приватность, которую отменяет должность, приватностью не является.
     * Права как таковые должность по-прежнему даёт: «редактировать курсы»
     * спрашивают без курса в аргументах, и такая проверка проходит.
     */
    private function registerAdministratorBypass(): void
    {
        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            if (! $user->accessLevel()->grantsEverything()) {
                return null;
            }

            return $this->concernsACourse($arguments) ? null : true;
        });
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function concernsACourse(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof Course) {
                return true;
            }
        }

        return false;
    }
}
