<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Ai;

use Anthropic\Core\Exceptions\APIException;
use App\Actions\Ai\SaveModelSettings;
use App\Enums\AccessLevel;
use App\Enums\AiAuthScheme;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\SaveSettingsRequest;
use App\Http\Resources\Ai\SettingsResource;
use App\Models\AiSetting;
use App\Support\Ai\ModelSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        $settings = $this->settingsUnderTest($request);

        if (! $settings->isConfigured()) {
            return response()->json(
                ['message' => 'Ключ не задан — ни в поле выше, ни в настройках, ни в переменных окружения.'],
                HttpResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $message = $settings->client()->messages->create(
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
     * Настройки, которые проверяем, — набранные в форме поверх сохранённых.
     *
     * Раньше проверялось только сохранённое, и это была ловушка: вставил свежий
     * ключ, нажал «Проверить связь» — и получил отказ по старому, потому что
     * новый ещё не сохранён. Ошибка при этом выглядела как «ключ недействителен»
     * — то есть обвиняла ровно тот ключ, который только что вставили и который
     * на самом деле хорош.
     *
     * Непришедшее поле означает «оставить как есть»: поле ключа форма никогда
     * не заполняет — она его не показывает, — и пустым оно значит «проверь тем,
     * что уже лежит в базе», а не «проверь без ключа».
     *
     * Ничего не сохраняется: это проверка, а не правка. Сохранять до того, как
     * связь подтвердилась, значило бы ломать работающего консультанта ради
     * опечатки в имени модели.
     */
    private function settingsUnderTest(Request $request): ModelSettings
    {
        $typed = $request->validate([
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'base_url' => ['sometimes', 'nullable', 'string', 'max:255'],
            'api_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'auth_scheme' => ['sometimes', 'nullable', Rule::enum(AiAuthScheme::class)],
        ]);

        $overrides = array_filter(
            array_map(static fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
                ? trim($value)
                : null, $typed),
            static fn (?string $value): bool => $value !== null,
        );

        $row = AiSetting::current();

        // Заполняем, но не сохраняем: `api_key` шифруется приведением типа, так
        // что набранный ключ читается из этого же объекта как обычная строка.
        $row->fill($overrides);

        return new ModelSettings($row);
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
