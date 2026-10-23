<?php

declare(strict_types=1);

namespace App\Actions\Ai;

use App\Enums\AccessLevel;
use App\Models\AiSetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Сохраняет настройки консультанта.
 *
 * Право проверяется здесь, а не политикой: администраторы проходят Gate::before
 * и получили бы доступ к чужому платёжному ключу вместе со всеми остальными
 * правами. Настройки модели — единственное, что администратору не принадлежит.
 */
final readonly class SaveModelSettings
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws AuthorizationException
     */
    public function handle(array $attributes, User $actor): AiSetting
    {
        if ($actor->accessLevel() !== AccessLevel::SuperAdmin) {
            throw new AuthorizationException('Настройки консультанта меняет только суперадминистратор.');
        }

        $settings = AiSetting::current();

        // Ключ с чужого стенда не должен мешать сохранить новый — см.
        // AiSetting::forgetUnreadableKey. Без этого экран настроек на ноутбуке
        // с дампом боевой базы отвечал «The MAC is invalid» на любое сохранение.
        $settings->forgetUnreadableKey();

        // Пустой ключ означает «не трогать»: форма его не показывает, и
        // сохранение любой другой правки иначе стирало бы рабочий ключ.
        if (($attributes['api_key'] ?? null) === null || trim((string) $attributes['api_key']) === '') {
            unset($attributes['api_key']);
        }

        $settings->fill($attributes);
        $settings->updated_by = $actor->getKey();
        $settings->save();

        return $settings;
    }
}
