<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\DepartmentRole;
use App\Enums\QuestionType;
use App\Models\Department;
use App\Models\Group;
use App\Models\Quiz;
use App\Models\Regulation;
use App\Models\RegulationAcknowledgement;
use App\Models\RegulationVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Версии документа и справочника (решение пользователя 2026-09-12).
 *
 * Одно правило, написанное для разных людей по-разному: «как считается
 * зарплата» у розницы и у офиса — разный текст, разный бланк и разная проверка,
 * но документ один. Общая версия — сам документ: у кого групп не совпало, тот
 * читает его так же, как читал до всякого разделения.
 */
final class MaterialVersionTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /* ---------- Какая версия открывается ---------- */

    public function test_a_version_opens_first_for_the_group_it_was_written_for(): void
    {
        $document = Regulation::factory()->published()->create();

        [$retail, $salesman] = $this->groupWithPerson('Розница');

        $version = $this->version($document, 'Для розницы', [$retail], text: 'Розничный расчёт');

        $response = $this->actingAs($salesman)
            ->getJson(route('lms.documents.show', $document))
            ->assertOk();

        $this->assertSame($version->id, $response->json('data.version.id'));
        $this->assertSame('Для розницы', $response->json('data.version.name'));
        $this->assertTrue($response->json('data.version.is_mine'));

        // Текст версии приходит вместе с ней, а статья документа остаётся
        // общей: по ней работает редактор.
        $this->assertSame('Розничный расчёт', $this->textOf($response->json('data.version.content_json')));

        // Кому ни одна версия не адресована — читает общую, и «version» ему не
        // присылают вовсе.
        $stranger = $this->learner();

        $this->actingAs($stranger)
            ->getJson(route('lms.documents.show', $document))
            ->assertOk()
            ->assertJsonMissingPath('data.version')
            ->assertJsonCount(1, 'data.versions');
    }

    /** Спор двух версий решает порядок, который задал автор. */
    public function test_the_order_set_by_the_author_settles_a_tie(): void
    {
        $document = Regulation::factory()->published()->create();

        [$first, $person] = $this->groupWithPerson('Наставники');
        $second = Group::factory()->create();
        $second->members()->attach($person);

        $mentors = $this->version($document, 'Для наставников', [$first]);
        $shift = $this->version($document, 'Для смены', [$second]);

        $this->actingAs($person)
            ->getJson(route('lms.documents.show', $document))
            ->assertOk()
            ->assertJsonPath('data.version.id', $mentors->id);

        // Порядок переставили — и спор решился иначе.
        $this->actingAs($this->author())
            ->putJson(route('lms.documents.versions.order', $document), [
                'versions' => [$shift->id, $mentors->id],
            ])
            ->assertOk();

        $this->actingAs($person)
            ->getJson(route('lms.documents.show', $document))
            ->assertOk()
            ->assertJsonPath('data.version.id', $shift->id);
    }

    /** Открытую версию видно всем: посмотреть, как считают у соседей, не запрещено. */
    public function test_an_open_version_stays_in_the_switcher_for_everyone(): void
    {
        $document = Regulation::factory()->published()->create();
        [$retail] = $this->groupWithPerson('Розница');

        $version = $this->version($document, 'Для розницы', [$retail]);

        $response = $this->actingAs($this->learner())
            ->getJson(route('lms.documents.show', $document))
            ->assertOk();

        $this->assertSame([$version->id], array_column($response->json('data.versions'), 'id'));
        $this->assertFalse($response->json('data.versions.0.is_mine'));

        // И открыть её можно — за этим в переключатель и ходят.
        $this->actingAs($this->learner())
            ->getJson(route('lms.documents.versions.show', [$document, $version]))
            ->assertOk()
            ->assertJsonPath('data.id', $version->id);
    }

    public function test_asking_for_another_version_opens_it_instead_of_mine(): void
    {
        $document = Regulation::factory()->published()->create();

        [$retail, $salesman] = $this->groupWithPerson('Розница');
        $office = Group::factory()->create();

        $mine = $this->version($document, 'Для розницы', [$retail]);
        $other = $this->version($document, 'Для офиса', [$office]);

        $this->actingAs($salesman)
            ->getJson(route('lms.documents.show', $document).'?version='.$other->id)
            ->assertOk()
            ->assertJsonPath('data.version.id', $other->id)
            // Чужая версия открыта, но своей от этого не стала.
            ->assertJsonPath('data.version.is_mine', false);

        $this->assertSame($mine->id, $mine->id);
    }

    /* ---------- Отдел рядом с группой (2026-09-12) ---------- */

    public function test_a_version_can_be_written_for_a_department(): void
    {
        $document = Regulation::factory()->published()->create();

        [$warehouse, $storeman] = $this->departmentWithPerson('Склад');

        $version = $this->version($document, 'Для склада', [], text: 'Складской расчёт');
        $version->departments()->sync([$warehouse->id]);

        $this->actingAs($storeman)
            ->getJson(route('lms.documents.show', $document))
            ->assertOk()
            ->assertJsonPath('data.version.id', $version->id)
            ->assertJsonPath('data.version.is_mine', true);

        // Кто в отделе не числится — читает общую.
        $this->actingAs($this->learner())
            ->getJson(route('lms.documents.show', $document))
            ->assertOk()
            ->assertJsonMissingPath('data.version');
    }

    /** Отдел охватывает и свои подотделы — как у новостей и рассылок. */
    public function test_a_department_version_reaches_the_sub_departments(): void
    {
        $document = Regulation::factory()->published()->create();

        [$sales] = $this->departmentWithPerson('Продажи');
        $shift = Department::factory()->create(['name' => 'Вторая смена', 'parent_id' => $sales->id]);

        $person = $this->learner();
        $shift->people()->attach($person, ['role' => DepartmentRole::Member->value]);

        $version = $this->version($document, 'Для продаж', []);
        $version->departments()->sync([$sales->id]);

        $this->actingAs($person)
            ->getJson(route('lms.documents.show', $document))
            ->assertOk()
            ->assertJsonPath('data.version.id', $version->id);
    }

    /** Группа и отдел складываются: совпало хоть что-то — версия его. */
    public function test_a_group_and_a_department_add_up_in_one_version(): void
    {
        $document = Regulation::factory()->published()->create();

        [$group, $inGroup] = $this->groupWithPerson('Наставники');
        [$warehouse, $storeman] = $this->departmentWithPerson('Склад');

        $version = $this->version($document, 'Для своих', [$group]);
        $version->departments()->sync([$warehouse->id]);

        foreach ([$inGroup, $storeman] as $reader) {
            $this->actingAs($reader)
                ->getJson(route('lms.documents.show', $document))
                ->assertOk()
                ->assertJsonPath('data.version.id', $version->id);
        }
    }

    /** Отделом версию заводят из редактора так же, как группой. */
    public function test_the_editor_writes_a_version_for_a_department(): void
    {
        $document = Regulation::factory()->published()->create();
        $department = Department::factory()->create(['name' => 'Склад']);

        $this->actingAs($this->author())
            ->postJson(route('lms.documents.versions.store', $document), [
                'name' => 'Для склада',
                'groups' => [],
                'departments' => [$department->id],
            ])
            ->assertCreated()
            ->assertJsonCount(0, 'data.groups')
            ->assertJsonPath('data.departments.0.name', 'Склад');
    }

    /* ---------- Закрытая версия ---------- */

    public function test_a_private_version_is_invisible_to_everyone_else(): void
    {
        $document = Regulation::factory()->published()->create();

        [$chiefs, $chief] = $this->groupWithPerson('Руководители');
        $version = $this->version($document, 'Для руководителей', [$chiefs], private: true);

        // Свой видит её и читает.
        $this->actingAs($chief)
            ->getJson(route('lms.documents.show', $document))
            ->assertOk()
            ->assertJsonCount(1, 'data.versions')
            ->assertJsonPath('data.version.id', $version->id);

        $stranger = $this->learner();

        // Посторонний не видит её даже названием: оно выдало бы её не хуже
        // текста.
        $this->actingAs($stranger)
            ->getJson(route('lms.documents.show', $document))
            ->assertOk()
            ->assertJsonCount(0, 'data.versions');

        $this->actingAs($stranger)
            ->getJson(route('lms.documents.versions.show', [$document, $version]))
            ->assertNotFound();

        // Прямая просьба открыть её тоже ничего не даёт.
        $this->actingAs($stranger)
            ->getJson(route('lms.documents.show', $document).'?version='.$version->id)
            ->assertOk()
            ->assertJsonMissingPath('data.version');
    }

    /**
     * Автор и руководство видят все версии — по тому же рассуждению, по какому
     * им открыт и закрытый документ.
     */
    public function test_the_author_and_an_administrator_see_a_private_version(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->published()->create(['author_id' => $author->id]);

        [$chiefs] = $this->groupWithPerson('Руководители');
        $this->version($document, 'Для руководителей', [$chiefs], private: true);

        foreach ([$author, $this->administrator()] as $reader) {
            $this->actingAs($reader)
                ->getJson(route('lms.documents.show', $document))
                ->assertOk()
                ->assertJsonCount(1, 'data.versions');
        }
    }

    /* ---------- Ведение ---------- */

    public function test_the_editor_keeps_the_versions(): void
    {
        $document = Regulation::factory()->published()->create();
        $group = Group::factory()->create(['name' => 'Розница']);

        $created = $this->actingAs($this->author())
            ->postJson(route('lms.documents.versions.store', $document), [
                'name' => 'Для розницы',
                'is_private' => false,
                'groups' => [$group->id],
                'departments' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Для розницы')
            ->assertJsonPath('data.groups.0.name', 'Розница')
            ->json('data.id');

        $this->actingAs($this->author())
            ->putJson(route('lms.documents.versions.update', [$document, $created]), [
                'name' => 'Для розницы и склада',
                'is_private' => true,
                'groups' => [$group->id],
                'departments' => [],
                'content_json' => $this->article('Считаем так'),
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Для розницы и склада')
            ->assertJsonPath('data.is_private', true);

        $this->actingAs($this->author())
            ->deleteJson(route('lms.documents.versions.destroy', [$document, $created]))
            ->assertNoContent();

        $this->assertSame(0, $document->versions()->count());
    }

    /** Версия без адресата ничья: она никому не откроется первой. */
    public function test_a_version_needs_an_audience(): void
    {
        $document = Regulation::factory()->published()->create();

        $this->actingAs($this->author())
            ->postJson(route('lms.documents.versions.store', $document), [
                'name' => 'Ничья',
                'groups' => [],
                'departments' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('groups');
    }

    public function test_a_reader_does_not_keep_the_versions(): void
    {
        $document = Regulation::factory()->published()->create();
        $group = Group::factory()->create();

        $this->actingAs($this->learner())
            ->postJson(route('lms.documents.versions.store', $document), [
                'name' => 'Своя',
                'groups' => [$group->id],
                'departments' => [],
            ])
            ->assertForbidden();

        $this->actingAs($this->learner())
            ->getJson(route('lms.documents.versions.index', $document))
            ->assertForbidden();
    }

    public function test_a_version_of_another_document_is_not_found(): void
    {
        $document = Regulation::factory()->published()->create();
        $other = Regulation::factory()->published()->create();

        [$group] = $this->groupWithPerson('Розница');
        $version = $this->version($other, 'Чужая', [$group]);

        $this->actingAs($this->author())
            ->deleteJson(route('lms.documents.versions.destroy', [$document, $version]))
            ->assertNotFound();
    }

    /* ---------- Файлы и проверка версии ---------- */

    /** Бланк розницы не появляется у офиса просто потому, что документ один. */
    public function test_files_of_a_version_stay_with_it(): void
    {
        $document = Regulation::factory()->published()->create();
        [$retail, $salesman] = $this->groupWithPerson('Розница');

        $version = $this->version($document, 'Для розницы', [$retail]);

        $document->allAttachments()->create([
            'version_id' => $version->id,
            'disk' => 's3', 'path' => 'x/retail.pdf', 'name' => 'Бланк розницы',
        ]);
        $document->allAttachments()->create([
            'disk' => 's3', 'path' => 'x/common.pdf', 'name' => 'Общий бланк',
        ]);

        $response = $this->actingAs($salesman)
            ->getJson(route('lms.documents.show', $document))
            ->assertOk();

        $this->assertSame(['Общий бланк'], array_column($response->json('data.attachments'), 'name'));
        $this->assertSame(['Бланк розницы'], array_column($response->json('data.version.attachments'), 'name'));
    }

    /**
     * Сдал проверку своей версии — ознакомился с документом, а версия осталась
     * пометкой на отметке.
     */
    public function test_passing_the_check_of_a_version_acknowledges_the_document(): void
    {
        $document = Regulation::factory()->published()->create();
        [$retail, $salesman] = $this->groupWithPerson('Розница');

        $version = $this->version($document, 'Для розницы', [$retail]);

        $this->actingAs($this->author())
            ->putJson(route('lms.documents.versions.quiz.save', [$document, $version]), [
                'title' => 'Проверка для розницы',
                'passing_score' => 100,
                'questions' => [[
                    'text' => 'Как считается надбавка?',
                    'type' => QuestionType::Single->value,
                    'points' => 1,
                    'options' => [
                        ['text' => 'От выручки смены', 'is_correct' => true],
                        ['text' => 'От оклада', 'is_correct' => false],
                    ],
                ]],
            ])
            ->assertCreated();

        $quiz = Quiz::query()->sole();

        $this->actingAs($salesman)
            ->postJson(route('lms.documents.versions.quiz.submit', [$document, $version]), [
                'answers' => $this->answers($quiz),
            ])
            ->assertCreated()
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.is_acknowledged', true);

        $acknowledgement = RegulationAcknowledgement::query()->sole();

        $this->assertSame($document->id, $acknowledgement->regulation_id);
        $this->assertSame($version->id, $acknowledgement->version_id);

        // Отметка одна на документ: версия у человека одна, и требовать
        // ознакомления со всеми значило бы требовать прочитать чужие правила.
        $this->assertSame(1, RegulationAcknowledgement::query()->count());
    }

    /** Проверку чужой закрытой версии не сдать: её текста тебе не показывали. */
    public function test_the_check_of_a_private_version_is_closed_too(): void
    {
        $document = Regulation::factory()->published()->create();
        [$chiefs] = $this->groupWithPerson('Руководители');

        $version = $this->version($document, 'Для руководителей', [$chiefs], private: true);

        $this->actingAs($this->author())
            ->putJson(route('lms.documents.versions.quiz.save', [$document, $version]), [
                'title' => 'Проверка',
                'passing_score' => 100,
                'questions' => [[
                    'text' => 'Вопрос',
                    'type' => QuestionType::Single->value,
                    'points' => 1,
                    'options' => [
                        ['text' => 'Да', 'is_correct' => true],
                        ['text' => 'Нет', 'is_correct' => false],
                    ],
                ]],
            ])
            ->assertCreated();

        $quiz = Quiz::query()->sole();

        $this->actingAs($this->learner())
            ->postJson(route('lms.documents.versions.quiz.submit', [$document, $version]), [
                'answers' => $this->answers($quiz),
            ])
            ->assertNotFound();
    }

    /** Раздел справочников чужой версии не открывает — как и чужого документа. */
    public function test_a_handbook_address_does_not_open_a_document_version(): void
    {
        $document = Regulation::factory()->published()->create();
        [$group] = $this->groupWithPerson('Розница');

        $version = $this->version($document, 'Для розницы', [$group]);

        $this->actingAs($this->learner())
            ->getJson(route('lms.handbooks.versions.show', [$document, $version]))
            ->assertNotFound();
    }

    /* ---------- Заготовки ---------- */

    /**
     * @return array{0: Department, 1: User}
     */
    private function departmentWithPerson(string $name): array
    {
        $department = Department::factory()->create(['name' => $name]);
        $person = $this->learner();

        $department->people()->attach($person, ['role' => DepartmentRole::Member->value]);

        return [$department, $person];
    }

    /**
     * @return array{0: Group, 1: User}
     */
    private function groupWithPerson(string $name): array
    {
        $group = Group::factory()->create(['name' => $name]);
        $person = $this->learner();

        $group->members()->attach($person);

        return [$group, $person];
    }

    /**
     * @param  list<Group>  $groups
     */
    private function version(
        Regulation $regulation,
        string $name,
        array $groups,
        bool $private = false,
        ?string $text = null,
    ): RegulationVersion {
        /** @var RegulationVersion $version */
        $version = $regulation->versions()->create([
            'name' => $name,
            'is_private' => $private,
            'position' => $regulation->versions()->count() + 1,
            'content_json' => $text === null ? null : $this->article($text),
        ]);

        $version->groups()->sync(array_map(static fn (Group $group): int => (int) $group->id, $groups));

        return $version;
    }

    /**
     * @return array<string, mixed>
     */
    private function article(string $text): array
    {
        return [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $text]],
            ]],
        ];
    }

    /**
     * @param  ?array<string, mixed>  $document
     */
    private function textOf(?array $document): string
    {
        return (string) ($document['content'][0]['content'][0]['text'] ?? '');
    }

    /**
     * @return array<int, list<int>>
     */
    private function answers(Quiz $quiz): array
    {
        return $quiz->questions()->with('options')->get()
            ->mapWithKeys(fn ($question): array => [
                $question->id => [$question->options->firstWhere('is_correct', true)->id],
            ])->all();
    }
}
