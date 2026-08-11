<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use App\Actions\Ai\SaveModelSettings;
use App\Enums\AccessLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\SaveSettingsRequest;
use App\Http\Resources\Ai\SettingsResource;
use App\Models\AiSetting;
use App\Support\Ai\ModelSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Модель, адрес и ключ консультанта.
 *
 * Всё здесь — только для суперадминистратора, и проверяется это явно, а не
 * политикой: администраторы проходят Gate::before и иначе получили бы доступ к
 * платёжному ключу.
 */
final class SettingsController extends Controller
{
    public function show(Request $request): SettingsResource
    {
        $this->authoriseSuperAdmin($request);

        return SettingsResource::make(AiSetting::current()->load('editor'));
    }

    public function update(SaveSettingsRequest $request, SaveModelSettings $save): JsonResponse
    {
        $settings = $save->handle($request->settings(), $request->user());

        // Всегда 200: настройки одни и те же, и первое сохранение — такое же
        // изменение, как любое следующее. Ресурс сам по себе ответил бы 201,
        // потому что строка создалась только что.
        return SettingsResource::make($settings->load('editor'))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_OK);
    }

    /**
     * Спрашивает модель на два слова, чтобы настройку можно было проверить
     * сразу, а не по жалобе сотрудника через неделю.
     */
    public function test(Request $request): JsonResponse
    {
        $this->authoriseSuperAdmin($request);

        $settings = ModelSettings::current();

        if (! $settings->isConfigured()) {
            return response()->json(
                ['message' => 'Ключ не задан — ни в настройках, ни в переменных окружения.'],
                HttpResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $message = app(Client::class)->messages->create(
                model: $settings->model(),
                maxTokens: 32,
                messages: [['role' => 'user', 'content' => 'Ответь одним словом: связь.']],
            );
        } catch (APIException $exception) {
            return response()->json([
                'message' => 'Модель не ответила: '.$exception->getMessage(),
            ], HttpResponse::HTTP_SERVICE_UNAVAILABLE);
        }

        $reply = '';

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $reply .= $block->text;
            }
        }

        return response()->json([
            'message' => sprintf('Связь есть. Модель %s ответила: «%s»', $settings->model(), trim($reply)),
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    private function authoriseSuperAdmin(Request $request): void
    {
        if ($request->user()?->accessLevel() !== AccessLevel::SuperAdmin) {
            throw new AuthorizationException('Настройки консультанта доступны только суперадминистратору.');
        }
    }
}
