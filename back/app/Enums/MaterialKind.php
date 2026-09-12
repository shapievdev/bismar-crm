<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Чем является страница, устроенная как документ: правилом или справкой.
 *
 * Оба вида — одна и та же вещь по устройству: статья, файлы, ответственные,
 * проверка, отметка «ознакомлен», соседи «рядом по теме». Разнести их по двум
 * таблицам значило бы вести две копии всего этого и однажды поправить одну.
 *
 * Разное в них — назначение, и оно видно на экране. **Документ** отвечает на
 * вопрос «по какому правилу мы работаем»: его читают целиком и отмечаются.
 * **Справочник** отвечает на вопрос «что делать прямо сейчас»: в него заходят
 * посреди разговора с клиентом, за одним ответом. Поэтому у них свои разделы,
 * свои деревья категорий и свои списки — искать правило среди справок и
 * наоборот значит каждый раз отсеивать половину.
 */
enum MaterialKind: string
{
    case Document = 'document';
    case Handbook = 'handbook';

    /** Под этим ключом вид лежит в запросе — кладёт EnsureMaterialKind. */
    public const REQUEST_KEY = 'material_kind';

    /**
     * Вид раздела, в котором пришёл запрос.
     *
     * Документ — не «по умолчанию правильный ответ», а самый безопасный: этот
     * вид был единственным до появления справочников, и запрос мимо
     * EnsureMaterialKind означает старый маршрут.
     */
    public static function of(Request $request): self
    {
        $kind = $request->attributes->get(self::REQUEST_KEY);

        return $kind instanceof self ? $kind : self::Document;
    }

    public function label(): string
    {
        return match ($this) {
            self::Document => 'Документ',
            self::Handbook => 'Справочник',
        };
    }

    public function plural(): string
    {
        return match ($this) {
            self::Document => 'Документы',
            self::Handbook => 'Справочники',
        };
    }

    /**
     * Раздел, в котором вид живёт, — и в адресах приложения, и в адресах API.
     *
     * Одно место на оба: ссылку на материал собирают и на сервере (источники
     * консультанта, новости, аттестации), и на экране, и разойтись им нельзя.
     */
    public function section(): string
    {
        return match ($this) {
            self::Document => 'documents',
            self::Handbook => 'handbooks',
        };
    }

    /**
     * Права раздела — все четыре в одном месте.
     *
     * У каждого вида они свои (решение пользователя 2026-09-11): правила
     * компании и справочник для зала ведут разные люди. Собраны здесь, а не
     * расписаны по маршрутам и политикам, ровно затем же, зачем и section():
     * третий вид должен добавляться одним случаем перечисления, а не обходом
     * двадцати мест, каждое из которых можно пропустить.
     *
     * Публикации среди них нет: документ пишут и выпускают одним движением —
     * см. App\Enums\Permission.
     */
    public function viewPermission(): Permission
    {
        return match ($this) {
            self::Document => Permission::ViewDocuments,
            self::Handbook => Permission::ViewHandbooks,
        };
    }

    public function createPermission(): Permission
    {
        return match ($this) {
            self::Document => Permission::CreateDocuments,
            self::Handbook => Permission::CreateHandbooks,
        };
    }

    public function updatePermission(): Permission
    {
        return match ($this) {
            self::Document => Permission::UpdateDocuments,
            self::Handbook => Permission::UpdateHandbooks,
        };
    }

    public function deletePermission(): Permission
    {
        return match ($this) {
            self::Document => Permission::DeleteDocuments,
            self::Handbook => Permission::DeleteHandbooks,
        };
    }

    /**
     * Виды, опубликованное в которых этот человек вправе читать.
     *
     * Нужно там, где материалы разных разделов лежат вперемешку и отобрать их
     * маршрутом нельзя: соседи «рядом по теме», частые вопросы, приложенное к
     * уроку, корзина, корпус консультанта. Пустой список — честный ответ «ни
     * одного», и запрос по нему не должен вернуть ничего.
     *
     * @return list<self>
     */
    public static function viewableBy(User $user): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $kind): bool => $user->can($kind->viewPermission()->value),
        ));
    }

    /**
     * Виды, которые этот человек вправе править, — а значит, видит и в
     * черновиках.
     *
     * @return list<self>
     */
    public static function editableBy(User $user): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $kind): bool => $user->can($kind->updatePermission()->value),
        ));
    }

    /**
     * Значения видов — для условий `whereIn`, которым нужны строки.
     *
     * @param  list<self>  $kinds
     * @return list<string>
     */
    public static function valuesOf(array $kinds): array
    {
        return array_map(static fn (self $kind): string => $kind->value, $kinds);
    }
}
