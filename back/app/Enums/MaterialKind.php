<?php

declare(strict_types=1);

namespace App\Enums;

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
}
