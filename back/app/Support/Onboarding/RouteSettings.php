<?php

declare(strict_types=1);

namespace App\Support\Onboarding;

final class RouteSettings
{
    public static function defaults(): array
    {
        return [
            'notifications_enabled' => true,
            'passed_message' => 'Тест сдан! Продолжайте маршрут.',
            'failed_message' => 'Ничего страшного: повторите материал и попробуйте ещё раз.',
            'completed_message' => 'Вся программа адаптации позади. Добро пожаловать в команду!',
            'stage_notification' => 'Наступил новый этап адаптации.',
            'status_labels' => [
                'locked' => 'Закрыт', 'available' => 'Доступен', 'in_progress' => 'В процессе',
                'completed' => 'Пройден', 'quiz_failed' => 'Нужно повторить тест',
            ],
        ];
    }

    public static function of(?array $settings): array
    {
        return array_replace_recursive(self::defaults(), $settings ?? []);
    }

    public static function rules(): array
    {
        $rules = [
            'settings' => ['sometimes', 'array:notifications_enabled,passed_message,failed_message,completed_message,stage_notification,status_labels'],
            'settings.notifications_enabled' => ['sometimes', 'boolean'],
            'settings.status_labels' => ['sometimes', 'array:locked,available,in_progress,completed,quiz_failed'],
        ];
        foreach (['passed_message', 'failed_message', 'completed_message', 'stage_notification'] as $key) {
            $rules['settings.'.$key] = ['sometimes', 'required', 'string', 'max:500'];
        }
        foreach (array_keys(self::defaults()['status_labels']) as $status) {
            $rules['settings.status_labels.'.$status] = ['sometimes', 'required', 'string', 'max:80'];
        }

        return $rules;
    }
}
