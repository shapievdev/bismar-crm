<?php

declare(strict_types=1);

namespace App\Mcp\Tools\News;

use App\Actions\News\SaveNews;
use App\Enums\NewsAudience;
use App\Enums\NewsStatus;
use App\Enums\Permission;
use App\Mcp\Support\PlainArticle;
use App\Mcp\Tools\BismarTool;
use App\Models\News;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Завести новость или переписать заведённую.
 *
 * Публикация будит компанию: опубликованная новость уходит в ленту всем, кому
 * адресована, и — если так решили — уведомлением на телефон. Поэтому черновик
 * здесь умолчание: новость сперва пишут, а выпускают отдельным решением.
 *
 * Правка уже опубликованной второй раз никого не будит — это считает сам
 * SaveNews, и здесь не повторяется.
 *
 * Адресная новость обязана выйти к кому-то: людям, отделам или группам. Три
 * пустых списка — новость, которую не увидит никто, и это проверяется.
 */
#[Name('news_publish')]
#[Title('Завести или выпустить новость')]
#[Description('Создаёт новость или правит существующую (по news_id). Статья пишется обычным текстом: пустая строка делит его на абзацы. По умолчанию заводится черновиком — чтобы выпустить к людям, передайте status: published; опубликованная новость уходит в ленту и уведомлением, поэтому подтвердите выпуск у сотрудника. Адресная новость (audience: selected) должна выйти хотя бы к одному сотруднику, отделу или группе.')]
final class PublishNews extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ManageNews);
    }

    public function handle(Request $request, SaveNews $saveNews): ResponseFactory|Response
    {
        $author = $this->permit($request, Permission::ManageNews);

        $input = Validator::make($request->all(), [
            'news_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'article' => ['nullable', 'string'],
            'status' => ['nullable', Rule::enum(NewsStatus::class)],
            'audience' => ['nullable', Rule::enum(NewsAudience::class)],
            'is_pinned' => ['nullable', 'boolean'],
            'requires_acknowledgement' => ['nullable', 'boolean'],

            'recipients' => ['nullable', 'array'],
            'recipients.*' => ['integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer', 'exists:groups,id'],
        ], [
            'title.required' => 'У новости должен быть заголовок.',
            'recipients.*.integer' => 'Адресаты передаются номерами сотрудников.',
            'department_ids.*.exists' => 'Такого отдела в структуре нет.',
            'group_ids.*.exists' => 'Такой группы нет.',
        ])->validate();

        $news = null;

        if (($input['news_id'] ?? null) !== null) {
            $news = News::query()->find((int) $input['news_id']);

            if (! $news instanceof News) {
                return Response::error("Новости №{$input['news_id']} нет.");
            }
        }

        $audience = NewsAudience::from((string) ($input['audience'] ?? $news?->audience->value ?? NewsAudience::Everyone->value));

        $recipients = array_values(array_map(intval(...), $input['recipients'] ?? []));
        $departments = array_values(array_map(intval(...), $input['department_ids'] ?? []));
        $groups = array_values(array_map(intval(...), $input['group_ids'] ?? []));

        // Адресная новость выходит к кому-то. Требуется это не раньше
        // публикации: новость заводят с решения «это не всем», а кому именно,
        // выясняют, пока она черновик. То же правило, что в SaveNewsRequest.
        $status = NewsStatus::from((string) ($input['status'] ?? NewsStatus::Draft->value));

        if ($audience === NewsAudience::Selected
            && $status->isVisibleToReaders()
            && $recipients === [] && $departments === [] && $groups === []) {
            return Response::error(
                'Адресную новость нельзя выпустить, не назвав адресатов: укажите сотрудников, отделы или группы.',
            );
        }

        $saved = $saveNews->handle([
            'title' => (string) $input['title'],
            'excerpt' => $input['excerpt'] ?? null,
            'content_json' => PlainArticle::toDocument($input['article'] ?? null) ?? $news?->content_json,
            'status' => $status->value,
            'is_pinned' => (bool) ($input['is_pinned'] ?? $news?->is_pinned ?? false),
            'audience' => $audience->value,
            'requires_acknowledgement' => (bool) ($input['requires_acknowledgement'] ?? $news?->requires_acknowledgement ?? false),
            'recipients' => $recipients,
            'department_ids' => $departments,
            'group_ids' => $groups,
            'links' => [],
        ], $author, $news);

        return Response::structured([
            'id' => $saved->getKey(),
            'title' => $saved->title,
            'status' => $saved->status->value,
            'audience' => $saved->audience->value,
            'published_at' => $saved->published_at?->toDateString(),
            'url' => '/news/'.$saved->slug,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'news_id' => $schema->integer()->description('Номер новости, если её правят. Без него заводится новая.'),
            'title' => $schema->string()->required()->description('Заголовок новости.'),
            'excerpt' => $schema->string()->description('Строка для карточки в ленте. Необязательна: у короткой новости и заголовка довольно.'),
            'article' => $schema->string()->description('Текст новости. Пустая строка между абзацами делит его на абзацы. При правке непришедший текст остаётся прежним.'),
            'status' => $schema->string()
                ->enum(NewsStatus::class)
                ->description('draft — черновик (по умолчанию), published — выпустить к людям. Выпуск уходит в ленту и уведомлением.'),
            'audience' => $schema->string()
                ->enum(NewsAudience::class)
                ->description('everyone — всей компании, selected — названным адресатам.'),
            'is_pinned' => $schema->boolean()->description('Закрепить наверху ленты.'),
            'requires_acknowledgement' => $schema->boolean()->description('Требовать отметку «ознакомлен».'),
            'recipients' => $schema->array()->items($schema->integer())->description('Номера сотрудников-адресатов.'),
            'department_ids' => $schema->array()->items($schema->integer())->description('Номера отделов-адресатов.'),
            'group_ids' => $schema->array()->items($schema->integer())->description('Номера групп-адресатов.'),
        ];
    }
}
