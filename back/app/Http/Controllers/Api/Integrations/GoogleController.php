<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\SaveGoogleSettingsRequest;
use App\Http\Resources\Integrations\GoogleSettingsResource;
use App\Models\GoogleSetting;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Связка с Google: чем открывается окно выбора файла на Диске.
 *
 * Читают эти настройки все, кто вошёл, и это не оплошность: окно Google
 * открывается в браузере сотрудника, а значит оба значения всё равно должны до
 * браузера доехать. Ограничивает их не тайна, а список разрешённых источников в
 * Google Cloud. Заводит их администратор — маршрут закрыт EnsureAdministrator.
 */
final class GoogleController extends Controller
{
    public function show(): GoogleSettingsResource
    {
        return GoogleSettingsResource::make(GoogleSetting::current()->load('editor'));
    }

    public function update(SaveGoogleSettingsRequest $request): JsonResponse
    {
        $settings = GoogleSetting::current();

        $settings->fill($request->validated());
        $settings->updated_by = $request->user()?->getKey();
        $settings->save();

        // Всегда 200: настройки одни и те же, и первое сохранение — такое же
        // изменение, как любое следующее.
        return GoogleSettingsResource::make($settings->load('editor'))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_OK);
    }
}
