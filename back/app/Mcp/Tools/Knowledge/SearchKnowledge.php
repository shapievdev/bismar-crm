<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Knowledge;

use App\Mcp\Tools\BismarTool;
use App\Support\Search\Everywhere;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Поиск по всей платформе — тот же, что в шапке приложения.
 *
 * Тем же классом (App\Support\Search\Everywhere), а не своим запросом, и это
 * здесь главное: отбор по правам, закрытость приватных курсов, невидимость
 * черновиков и адресность новостей живут в нём. Свой запрос пришлось бы
 * снабдить теми же условиями — и он разошёлся бы с экраном в первый же день.
 *
 * Права на входе не спрашиваются ни одного, и `shouldRegister` здесь тоже нет:
 * разделы отбираются внутри, по правам спрашивающего, а новости читает всякий,
 * кто вошёл, — так что пустой выдачи «по правам» не бывает. Так же устроен и
 * адрес `api/search`.
 */
#[Name('knowledge_search')]
#[Title('Поиск по платформе')]
#[Description('Ищет по названиям во всей платформе разом: курсы, уроки, документы, справочники, новости и сотрудники. Отвечает разделами — в каждом до пяти находок с номером, названием и адресом страницы. Показывает только то, что открыто этому сотруднику. Чтобы прочитать найденное целиком, позовите knowledge_read с номером и видом.')]
final class SearchKnowledge extends BismarTool
{
    public function handle(Request $request, Everywhere $everywhere): ResponseFactory|Response
    {
        $actor = $this->permitAnyone($request);

        $input = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:255'],
        ]);

        $sections = $everywhere->find((string) $input['query'], $actor);

        if ($sections === []) {
            return Response::structured([
                'found' => 0,
                'sections' => [],
                'hint' => 'Ничего не нашлось. Поиск идёт по названиям, а не по текстам статей: попробуйте другое слово или спросите сотрудника, в каком разделе искать.',
            ]);
        }

        return Response::structured([
            'found' => array_sum(array_map(static fn (array $section): int => count($section['items']), $sections)),
            'sections' => $sections,
            // Какие виды бывают — чтобы следующий вызов не угадывал.
            'readable_kinds' => ['course', 'lesson', 'document', 'handbook', 'news'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->required()
                ->min(2)
                ->description('Что искать — слово или часть названия, не короче двух знаков.'),
        ];
    }
}
