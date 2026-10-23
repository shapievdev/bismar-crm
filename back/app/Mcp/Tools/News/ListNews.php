<?php

declare(strict_types=1);

namespace App\Mcp\Tools\News;

use App\Enums\Permission;
use App\Mcp\Tools\BismarTool;
use App\Models\News;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Новости глазами того, кто их ведёт.
 *
 * Не лента: лента отбирается по адресату и показывает читателю только его
 * новости, а здесь нужны все — включая черновики и те, что адресованы другим
 * отделам. Поэтому и право своё, `news.manage`.
 *
 * Сколько человек ознакомилось — рядом с каждой: ради этого числа ведение
 * новостей и существует.
 */
#[Name('news_list')]
#[Title('Новости компании')]
#[Description('Все новости компании глазами ведущего: черновики и опубликованные, кому адресованы, закреплена ли, требуется ли ознакомление и сколько человек уже отметилось. Страницами по 15. Текст новости целиком читается через knowledge_read с kind=news.')]
final class ListNews extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ManageNews);
    }

    public function handle(Request $request): ResponseFactory|Response
    {
        $this->permit($request, Permission::ManageNews);

        $input = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $news = News::query()
            ->with('author')
            ->withCount('acknowledgements')
            ->inFeedOrder()
            ->paginate(perPage: 15, page: (int) ($input['page'] ?? 1));

        return Response::structured([
            'news' => array_map(static fn (News $one): array => [
                'id' => $one->getKey(),
                'title' => $one->title,
                'status' => $one->status->value,
                'audience' => $one->audience->value,
                'is_pinned' => (bool) $one->is_pinned,
                'requires_acknowledgement' => (bool) $one->requires_acknowledgement,
                'acknowledgements' => (int) ($one->acknowledgements_count ?? 0),
                'published_at' => $one->published_at?->toDateString(),
                'author' => $one->author?->name,
            ], $news->items()),
            'page' => $news->currentPage(),
            'pages' => $news->lastPage(),
            'total' => $news->total(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->min(1)->description('Страница списка, по 15 новостей.'),
        ];
    }
}
