<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Staff;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Mcp\Support\StaffCard;
use App\Mcp\Tools\BismarTool;
use App\Models\User;
use App\Support\Staff\JobTitles;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Список сотрудников — тот же, что на экране «Сотрудники».
 *
 * Отбор идёт в базу, а не по загруженной странице, и постранично: людей в
 * компании больше, чем стоит присылать разом, и «найди Курабанова» не должно
 * означать «пришли всех и поищи сам».
 *
 * Должности едут вместе со страницей — списком того, что проставлено. Без них
 * отбор по должности был бы угадыванием: это не справочник, а строка, которую
 * пишет кадровик, и «Продавец-консультант» от «продавца» здесь отличается.
 */
#[Name('staff_search')]
#[Title('Найти сотрудников')]
#[Description('Список сотрудников с поиском по фамилии, имени и почте, отбором по должности, отделу и положению (работает, уволен). Отвечает страницами по 25 человек и присылает заодно все проставленные должности, чтобы отбор по должности был точным.')]
final class SearchStaff extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ViewUsers);
    }

    public function handle(Request $request, JobTitles $titles): ResponseFactory|Response
    {
        $actor = $this->permit($request, Permission::ViewUsers);

        $input = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:working,dismissed,any'],
            'deleted' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $deleted = (bool) ($input['deleted'] ?? false);

        // Удалённых показывают одному суперадминистратору — он их удаляет и
        // возвращает, а для остальных удалённого в приложении нет.
        if ($deleted && $actor->accessLevel() !== AccessLevel::SuperAdmin) {
            return Response::error('Удалённые записи видит только суперадминистратор.');
        }

        $status = (string) ($input['status'] ?? 'any');
        $jobTitle = trim((string) ($input['job_title'] ?? ''));
        $department = $input['department_id'] ?? null;

        $people = User::query()
            ->when($deleted, fn (Builder $query) => $query->onlyTrashed()->with('remover'))
            ->with(['departments' => fn (BelongsToMany $query) => $query
                ->orderByRaw('departments.name COLLATE "und-x-icu"')])
            ->matching($input['search'] ?? null)
            ->when($jobTitle !== '', fn (Builder $query) => $query->where('job_title', $jobTitle))
            ->when($department !== null, fn (Builder $query) => $query
                ->whereHas('departments', fn (Builder $inner) => $inner->whereKey($department)))
            ->when($status === 'working', fn (Builder $query) => $query->whereNull('dismissed_at'))
            ->when($status === 'dismissed', fn (Builder $query) => $query->whereNotNull('dismissed_at'))
            // Порядок тот же, что на экране: уволенные в конце, остальные по
            // фамилии с коллацией — базы живут в C, и без неё «Ёлкин» уехал бы
            // за «Яковлева».
            ->when($deleted, fn (Builder $query) => $query->orderByDesc('deleted_at'))
            ->when(! $deleted, fn (Builder $query) => $query->orderByRaw('dismissed_at IS NOT NULL'))
            ->orderByRaw('COALESCE(last_name, first_name) COLLATE "und-x-icu"')
            ->orderByRaw('first_name COLLATE "und-x-icu"')
            ->paginate(perPage: 25, page: (int) ($input['page'] ?? 1));

        return Response::structured([
            'people' => array_map(StaffCard::brief(...), $people->items()),
            'page' => $people->currentPage(),
            'pages' => $people->lastPage(),
            'total' => $people->total(),
            // Справочник отбора: то, что реально проставлено людям.
            'job_titles' => $titles->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()
                ->description('Строка поиска: часть фамилии, имени, отчества или почты.'),
            'job_title' => $schema->string()
                ->description('Должность — точным совпадением с одной из присланных в job_titles.'),
            'department_id' => $schema->integer()
                ->description('Номер отдела: только его сотрудники.'),
            'status' => $schema->string()
                ->enum(['working', 'dismissed', 'any'])
                ->description('Кого показать: работающих, уволенных или всех. По умолчанию всех.'),
            'deleted' => $schema->boolean()
                ->description('Вместо живых записей — удалённые. Доступно только суперадминистратору.'),
            'page' => $schema->integer()->description('Страница списка, по 25 человек. По умолчанию первая.'),
        ];
    }
}
