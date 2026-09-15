<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DepartmentRole;
use App\Enums\DismissalReason;
use App\Enums\EmploymentStatus;
use App\Enums\WorkMode;
use App\Models\Department;
use App\Models\StaffTag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Демонстрационный штат: полтора года найма и увольнений.
 *
 * Аналитику движения персонала не на чем смотреть, пока в базе шесть человек и
 * ни одного уволенного: все показатели честно показывают нули, и отличить
 * «работает правильно» от «не считает вовсе» нельзя.
 *
 * Люди тут выдуманные, но кадровый год — правдоподобный: розница течёт сильно и
 * быстро, офис почти не течёт, часть новичков не доживает до конца
 * испытательного срока, кто-то ушёл на второй неделе, одна сотрудница в
 * декрете. Ровно на таких данных и видно, считает отчёт или делает вид.
 *
 * Намеренно не вызывается из DatabaseSeeder: боевой `db:seed` не должен
 * заводить три десятка выдуманных сотрудников. Запускается по имени:
 *
 *     php artisan db:seed --class=DemoStaffSeeder
 *
 * Идемпотентен: люди ищутся по телефону, повторный запуск обновляет их и ничего
 * не дублирует. Убрать всех разом — `--class=DemoStaffSeeder` с `--force` и
 * затем удалить по префиксу телефона `+7999000`.
 */
final class DemoStaffSeeder extends Seeder
{
    /** Телефоны демонстрационных людей начинаются с него — по нему их и находят. */
    private const PHONE_PREFIX = '+7999000';

    public function run(): void
    {
        $tags = $this->tags();
        $departments = $this->departments();

        $mentors = [];

        foreach ($this->people() as $index => $person) {
            $user = $this->store($person, $index);

            $this->attachDepartment($user, $departments, $person['department']);

            if (($person['mentor'] ?? false) === true) {
                $mentors[$person['department']] = $user;
            }

            foreach ($person['tags'] ?? [] as $tag) {
                $user->staffTags()->syncWithoutDetaching([$tags[$tag]->getKey()]);
            }
        }

        $this->appointMentors($mentors);

        $this->command?->info(sprintf(
            'Демо-штат: %d человек, из них уволенных %d.',
            User::query()->where('phone', 'like', self::PHONE_PREFIX.'%')->count(),
            User::query()->where('phone', 'like', self::PHONE_PREFIX.'%')->whereNotNull('dismissed_at')->count(),
        ));
    }

    /**
     * Ручные теги справочника.
     *
     * «Наставник» сюда не входит: он выводится из поля наставника в карточке, и
     * второй, ручной, разошёлся бы с ним на следующий же день.
     *
     * @return array<string, StaffTag>
     */
    private function tags(): array
    {
        $tags = [];

        foreach (['Кадровый резерв' => 1, 'Испытательный срок продлён' => 2] as $name => $position) {
            $tags[$name] = StaffTag::query()->firstOrCreate(['name' => $name], ['position' => $position]);
        }

        return $tags;
    }

    /**
     * @return array<string, Department>
     */
    private function departments(): array
    {
        return Department::query()->get()->keyBy('name')->all();
    }

    /**
     * Кадровый год: кого приняли, кто ушёл и почему.
     *
     * Даты расставлены руками, а не случайно: отчёт проверяют на конкретных
     * числах, и «двенадцать принятых в марте» должно быть воспроизводимым.
     *
     * @return list<array<string, mixed>>
     */
    private function people(): array
    {
        return [
            // Костяк: приняты давно, работают.
            $this->person('Ковалёв', 'Игорь', 'Петрович', 'Розничный', 'Директор розницы', '2021-04-12', WorkMode::Office, mentor: true),
            $this->person('Астахова', 'Марина', 'Сергеевна', 'Корпоративный', 'Руководитель отдела', '2022-02-01', WorkMode::Office, mentor: true),
            $this->person('Гаджиев', 'Руслан', 'Маратович', 'Склад', 'Начальник склада', '2022-09-15', WorkMode::Shift, mentor: true),
            $this->person('Ильин', 'Сергей', 'Олегович', 'Логистика', 'Логист', '2023-01-10', WorkMode::Office),
            $this->person('Пахомова', 'Юлия', 'Андреевна', 'Оптовый', 'Менеджер', '2023-05-22', WorkMode::Office, tags: ['Кадровый резерв']),

            // Год назад: часть уже ушла.
            $this->person('Сорокин', 'Артём', 'Дмитриевич', 'Розничный', 'Продавец', '2025-03-03', WorkMode::Shift),
            $this->person('Белова', 'Ксения', 'Ивановна', 'Розничный', 'Продавец', '2025-03-17', WorkMode::Shift, left: '2025-11-28', why: DismissalReason::Own),
            $this->person('Мамедов', 'Тимур', 'Эльдарович', 'Склад', 'Кладовщик', '2025-04-07', WorkMode::Shift),
            $this->person('Зуева', 'Алина', 'Романовна', 'Корпоративный', 'Менеджер', '2025-05-19', WorkMode::Office, status: EmploymentStatus::ParentalLeave),
            $this->person('Рощин', 'Павел', 'Викторович', 'Водители', 'Водитель', '2025-06-02', WorkMode::Shift),
            $this->person('Титова', 'Дарья', 'Максимовна', 'Розничный', 'Продавец', '2025-07-14', WorkMode::Shift, left: '2025-09-01', why: DismissalReason::ProbationFailed),
            $this->person('Ермаков', 'Никита', 'Сергеевич', 'Курьеры', 'Курьер', '2025-08-04', WorkMode::Shift, left: '2025-08-18', why: DismissalReason::NoShow),

            // Зима и весна: набор в розницу, из него мало кто остался.
            $this->person('Литвинов', 'Олег', 'Юрьевич', 'Розничный', 'Продавец', '2026-01-13', WorkMode::Shift),
            $this->person('Кузьмина', 'Вера', 'Павловна', 'Розничный', 'Продавец', '2026-01-27', WorkMode::Shift, left: '2026-04-10', why: DismissalReason::Own),
            $this->person('Дорохов', 'Антон', 'Игоревич', 'Склад', 'Кладовщик', '2026-02-09', WorkMode::Shift),
            $this->person('Савельева', 'Полина', 'Олеговна', 'Оптовый', 'Менеджер', '2026-02-24', WorkMode::Office),
            $this->person('Шматов', 'Денис', 'Алексеевич', 'Розничный', 'Продавец', '2026-03-02', WorkMode::Shift, left: '2026-03-30', why: DismissalReason::ProbationFailed),
            $this->person('Носова', 'Ирина', 'Валерьевна', 'Корпоративный', 'Менеджер', '2026-03-16', WorkMode::Office),
            $this->person('Байрамов', 'Эмин', 'Ровшанович', 'Доставщики', 'Доставщик', '2026-03-23', WorkMode::Shift, left: '2026-06-15', why: DismissalReason::Employer),

            // Лето: основной набор, по нему и считают онбординг.
            $this->person('Фомина', 'Елена', 'Андреевна', 'Розничный', 'Продавец', '2026-06-01', WorkMode::Shift),
            $this->person('Глебов', 'Максим', 'Русланович', 'Розничный', 'Продавец', '2026-06-15', WorkMode::Shift, left: '2026-07-02', why: DismissalReason::NoShow),
            $this->person('Юсупова', 'Камила', 'Тимуровна', 'Сервис', 'Мастер', '2026-06-22', WorkMode::Shift),
            $this->person('Панкратов', 'Илья', 'Вадимович', 'Склад', 'Кладовщик', '2026-07-06', WorkMode::Shift, tags: ['Испытательный срок продлён']),
            $this->person('Мельник', 'Ольга', 'Дмитриевна', 'Оптовый', 'Менеджер', '2026-07-20', WorkMode::Office),
            $this->person('Хасанов', 'Ренат', 'Ильдарович', 'Розничный', 'Продавец', '2026-08-03', WorkMode::Shift, left: '2026-08-29', why: DismissalReason::Agreement),
            $this->person('Ларина', 'Наталья', 'Сергеевна', 'Розничный', 'Продавец', '2026-08-10', WorkMode::Shift),
            $this->person('Абрамов', 'Кирилл', 'Егорович', 'Курьеры', 'Курьер', '2026-08-17', WorkMode::Shift),

            // Только что: испытательный срок ещё идёт.
            $this->person('Тимофеева', 'Софья', 'Артёмовна', 'Корпоративный', 'Менеджер', '2026-09-01', WorkMode::Office),
            $this->person('Круглов', 'Егор', 'Станиславович', 'Розничный', 'Продавец', '2026-09-08', WorkMode::Shift),

            // Заведён до того, как дату приёма начали спрашивать: отчёт обязан
            // назвать его отдельно, а не считать принятым сегодня.
            $this->person('Сухарев', 'Валентин', 'Львович', 'Логистика', 'Диспетчер', null, WorkMode::Office),
        ];
    }

    /**
     * @param  list<string>  $tags
     * @return array<string, mixed>
     */
    private function person(
        string $last,
        string $first,
        string $middle,
        string $department,
        string $title,
        ?string $hired,
        WorkMode $mode,
        ?string $left = null,
        ?DismissalReason $why = null,
        EmploymentStatus $status = EmploymentStatus::Working,
        bool $mentor = false,
        array $tags = [],
    ): array {
        return [
            'last' => $last, 'first' => $first, 'middle' => $middle,
            'department' => $department, 'title' => $title,
            'hired' => $hired, 'mode' => $mode,
            'left' => $left, 'why' => $why, 'status' => $status,
            'mentor' => $mentor, 'tags' => $tags,
        ];
    }

    /**
     * @param  array<string, mixed>  $person
     */
    private function store(array $person, int $index): User
    {
        // Телефон по порядковому номеру: так сид идемпотентен и так же
        // очевидно, кто из базы демонстрационный.
        $phone = self::PHONE_PREFIX.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);

        $user = User::query()->firstOrNew(['phone' => $phone]);

        $user->forceFill([
            'last_name' => $person['last'],
            'first_name' => $person['first'],
            'middle_name' => $person['middle'],
            'job_title' => $person['title'],
            'hired_at' => $person['hired'],
            'work_mode' => $person['mode'],
            'employment_status' => $person['status'],
            'dismissed_at' => $person['left'] === null ? null : CarbonImmutable::parse($person['left'])->setTime(12, 0),
            'dismissal_reason' => $person['why'],
        ]);

        if (! $user->exists) {
            $user->password = Hash::make('Demo-Passw0rd!');
        }

        $user->save();

        return $user;
    }

    /**
     * @param  array<string, Department>  $departments
     */
    private function attachDepartment(User $user, array $departments, string $name): void
    {
        $department = $departments[$name] ?? null;

        if ($department === null) {
            return;
        }

        $user->departments()->syncWithoutDetaching([
            $department->getKey() => ['role' => DepartmentRole::Member->value],
        ]);
    }

    /**
     * Наставник — руководитель своего подразделения.
     *
     * Ставится вторым проходом: назначать наставником того, кого ещё не завели,
     * нечем.
     *
     * @param  array<string, User>  $mentors
     */
    private function appointMentors(array $mentors): void
    {
        foreach ($mentors as $department => $mentor) {
            User::query()
                ->where('phone', 'like', self::PHONE_PREFIX.'%')
                ->whereKeyNot($mentor->getKey())
                ->whereHas('departments', fn ($query) => $query->where('departments.name', $department))
                ->update(['mentor_id' => $mentor->getKey()]);
        }
    }
}
