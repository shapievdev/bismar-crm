<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Enums\Permission;
use App\Models\Course;
use App\Models\Department;
use App\Models\Group;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesConversations;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Забытая раскладка — во всех списках, где ищут.
 *
 * Проверка одна на весь поиск приложения намеренно: поблажка заведена в одном
 * месте (App\Support\Search\KeyboardLayout), и цена ей — ровно в том, что она
 * работает везде одинаково. Отдельные файлы проверок разошлись бы, и «документ»
 * находился бы в курсах, но не в людях.
 *
 * Идёт на настоящем Postgres: сворачивание регистра кириллицы и ICU-коллация —
 * возможности базы, и подделкой их не проверить.
 */
final class ForgottenLayoutTest extends TestCase
{
    use ActsAsSpaClient, MakesConversations, MakesUsers, RefreshDatabase;

    /** Каталог курсов: набрано «ljrevtyn», найден «Документооборот». */
    public function test_a_course_is_found_by_latin_typing(): void
    {
        Course::factory()->published()->create(['title' => 'Документооборот на складе']);
        Course::factory()->published()->create(['title' => 'Онбординг']);

        $this->actingAs($this->learner())
            ->getJson(route('lms.courses.index', ['search' => 'ljrevtyn']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Документооборот на складе');
    }

    /** Ключевые слова курса — тоже: их пишут ради тех, кто ищет не теми словами. */
    public function test_the_keywords_of_a_course_are_read_the_same_way(): void
    {
        Course::factory()->published()->create([
            'title' => 'Работа с кассой',
            'keywords' => ['ККМ'],
        ]);

        $this->actingAs($this->learner())
            ->getJson(route('lms.courses.index', ['search' => 'rrv']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Работа с кассой');
    }

    public function test_a_document_is_found_by_latin_typing(): void
    {
        Regulation::factory()->published()->create(['title' => 'Кассовая дисциплина']);
        Regulation::factory()->published()->create(['title' => 'Пропускной режим']);

        $this->actingAs($this->learner())
            // «rfcc» — это «касс», начало «Кассовой».
            ->getJson(route('lms.documents.index', ['search' => 'rfcc']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Кассовая дисциплина');
    }

    /** Люди — по фамилии: её и набирают, не глядя на раскладку. */
    public function test_a_person_is_found_by_latin_typing(): void
    {
        User::factory()->create(['last_name' => 'Иванов', 'first_name' => 'Пётр']);
        User::factory()->create(['last_name' => 'Петров', 'first_name' => 'Иван']);

        $this->actingAs($this->userWith(Permission::ViewUsers))
            // «bdfyjd» — это «иванов».
            ->getJson(route('users.index', ['search' => 'bdfyjd']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last_name', 'Иванов');
    }

    /**
     * Почта — обратным переводом: латинское имя, набранное в русской раскладке,
     * тоже должно находиться.
     */
    public function test_a_latin_name_typed_in_russian_is_read_back(): void
    {
        User::factory()->create(['last_name' => 'Сомов', 'email' => 'somov@bismar.pro']);
        User::factory()->create(['last_name' => 'Ткачёв', 'email' => 'tkachev@bismar.pro']);

        $this->actingAs($this->userWith(Permission::ViewUsers))
            // «ыщьщм» — это «somov», набранное с русской раскладкой.
            ->getJson(route('users.index', ['search' => 'ыщьщм']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last_name', 'Сомов');
    }

    public function test_a_group_is_found_by_latin_typing(): void
    {
        Group::factory()->create(['name' => 'Наставники']);
        Group::factory()->create(['name' => 'Розница']);

        $this->actingAs($this->userWith(Permission::ViewUsers))
            // «yfcnfdybrb» — это «наставники».
            ->getJson(route('groups.index', ['search' => 'yfcnfdybrb']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Наставники');
    }

    /** Мессенджер: поиск по сказанному читается двумя раскладками через «или». */
    public function test_a_message_is_found_by_latin_typing(): void
    {
        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());

        $found = $this->saidIn($conversation, $me, 'Бланки лежат в папке «Сверки»');
        $this->saidIn($conversation, $me, 'Совсем про другое');

        $this->actingAs($me)
            // «,kfyr» — это «бланк»: «б» живёт на запятой.
            ->getJson(route('chat.messages.search', $conversation).'?q='.urlencode(',kfyr'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $found->id);
    }

    /**
     * Набранное правильно ищется по-прежнему: второе чтение добавляется рядом, а
     * не подменяет первое.
     */
    public function test_a_message_typed_properly_is_still_found(): void
    {
        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());

        $this->saidIn($conversation, $me, 'Бланки лежат в папке «Сверки»');

        $this->actingAs($me)
            ->getJson(route('chat.messages.search', $conversation).'?q='.urlencode('бланк'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * Латинское слово, которое латинским и было, поиску не мешает: второе чтение
     * ищется рядом и просто ничего не находит.
     */
    public function test_a_latin_word_still_finds_itself(): void
    {
        Course::factory()->published()->create(['title' => 'Работа с PDF']);
        Course::factory()->published()->create(['title' => 'Онбординг']);

        $this->actingAs($this->learner())
            ->getJson(route('lms.courses.index', ['search' => 'pdf']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Работа с PDF');
    }

    /** Отделы — в списке адресатов и в структуре, тем же правилом. */
    public function test_a_department_is_found_by_latin_typing(): void
    {
        Department::factory()->create(['name' => 'Склад']);
        Department::factory()->create(['name' => 'Розница']);

        $this->assertSame(
            ['Склад'],
            // «crkfl» — это «склад».
            Department::query()->matching('crkfl')->pluck('name')->all(),
        );
    }
}
