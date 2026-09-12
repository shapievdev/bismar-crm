<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Support\Authorization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Документы и справочники получают свои права — и тех, у кого они уже были.
 *
 * До сих пор оба раздела отвечали правам на курсы: кто правил курсы, правил и
 * правила компании. С разделением (решение пользователя 2026-09-11) новые
 * права существуют, но не выданы никому, и без переноса выкат тихо закрыл бы
 * документы у всех, кроме администраторов, — а те бы этого не заметили, потому
 * что их пропускает Gate::before.
 *
 * Поэтому каждому, у кого отмечено право на курсы, проставляется такое же в
 * обоих новых разделах: в день выката человек видит ровно то же, что видел
 * накануне. Дальше их разводят руками на экране сотрудника — в этом и смысл
 * разделения.
 *
 * Строки самих прав заводятся здесь же, а не оставляются сидеру: миграции
 * накатывают раньше, и выдавать право, которого ещё нет в таблице, нечем.
 * Повторный прогон ничего не портит — и права, и выдачи ставятся только те,
 * которых нет.
 */
return new class extends Migration
{
    /**
     * Откуда и куда переносится право.
     *
     * @var array<string, list<string>>
     */
    private const CARRIED_OVER = [
        Permission::ViewCourses->value => [
            Permission::ViewDocuments->value,
            Permission::ViewHandbooks->value,
        ],
        Permission::CreateCourses->value => [
            Permission::CreateDocuments->value,
            Permission::CreateHandbooks->value,
        ],
        Permission::UpdateCourses->value => [
            Permission::UpdateDocuments->value,
            Permission::UpdateHandbooks->value,
        ],
        Permission::DeleteCourses->value => [
            Permission::DeleteDocuments->value,
            Permission::DeleteHandbooks->value,
        ],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (self::CARRIED_OVER as $from => $granted) {
                foreach ($granted as $to) {
                    $this->carryOver($from, $this->permissionId($to));
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Новые права снимаются вместе с тем, что по ним выдано.
     *
     * Возврат честный: до этой миграции разделы отвечали правам на курсы, и
     * права на курсы у людей остаются нетронутыми — снимать здесь нечего.
     */
    public function down(): void
    {
        $names = array_merge(...array_values(self::CARRIED_OVER));

        DB::transaction(function () use ($names): void {
            $ids = DB::table($this->table('permissions'))
                ->whereIn('name', $names)
                ->where('guard_name', Authorization::GUARD)
                ->pluck('id');

            DB::table($this->table('model_has_permissions'))->whereIn('permission_id', $ids)->delete();
            DB::table($this->table('permissions'))->whereIn('id', $ids)->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Номер права, заведённого при необходимости.
     */
    private function permissionId(string $name): int
    {
        $permissions = $this->table('permissions');

        $existing = DB::table($permissions)
            ->where('name', $name)
            ->where('guard_name', Authorization::GUARD)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table($permissions)->insertGetId([
            'name' => $name,
            'guard_name' => Authorization::GUARD,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Выдаёт новое право всем, у кого отмечено прежнее.
     *
     * Одним запросом, а не обходом людей: выдач в таблице столько же, сколько
     * отмеченных галочек, и вычитывать их в приложение ради вставки обратно
     * незачем.
     */
    private function carryOver(string $from, int $to): void
    {
        $grants = $this->table('model_has_permissions');

        $source = DB::table($this->table('permissions'))
            ->where('name', $from)
            ->where('guard_name', Authorization::GUARD)
            ->value('id');

        if ($source === null) {
            return;
        }

        $holders = DB::table($grants)
            ->where('permission_id', $source)
            // Кому уже выдано новое — пропускаем: миграцию могли прогнать
            // дважды, и вторая вставка упёрлась бы в первичный ключ.
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from($grants.' as granted')
                ->where('granted.permission_id', $to)
                ->whereColumn('granted.model_type', $grants.'.model_type')
                ->whereColumn('granted.model_id', $grants.'.model_id'))
            ->get(['model_type', 'model_id']);

        foreach ($holders->chunk(500) as $chunk) {
            DB::table($grants)->insert($chunk->map(fn (object $holder): array => [
                'permission_id' => $to,
                'model_type' => $holder->model_type,
                'model_id' => $holder->model_id,
            ])->all());
        }
    }

    private function table(string $name): string
    {
        /** @var array<string, string> $tables */
        $tables = config('permission.table_names');

        return $tables[$name];
    }
};
