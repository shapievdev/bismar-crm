<?php

declare(strict_types=1);

namespace App\Support\Analytics;

/**
 * Склад в тех именах, которыми его знают отчёты.
 *
 * Отчёты спрашивают про `summa`, `klient_id`, `gruppa1` — и это правильные для
 * них слова: панель говорит о выручке, а не о том, из какой таблицы склада она
 * собрана. Перевод живёт здесь, одним куском, а не повторяется в каждом из
 * восемнадцати запросов: отчёты читают не таблицу, а `{sales}`.
 *
 * **Продажи берутся из сырого слоя, а не из витрины** (решение пользователя
 * 2026-10-22, причины — в `config/analytics.php`). Поэтому к переводу имён
 * здесь добавилось то, что прежде делала витрина:
 *
 *  - отбор проведённых и неудалённых документов;
 *  - отсечение заказов покупателя — заказ не продажа, витрина его не считала;
 *  - возвраты и канал продажи, выведенные из типа документа;
 *  - разметка товара и клиента из справочников `dim.item` и
 *    `dim.counterparties` — в сырье её нет.
 *
 * Меры при этом те же: `amount`, `cost` и `qty` сырья совпадают с регистровыми
 * мерами витрины до копейки на одном и том же документе — проверено перед
 * переводом. Отличия в итогах за период — это отличия в составе строк, а не в
 * деньгах.
 *
 * Сверх перевода здесь лежат три подзапроса: `{orders}`, `{customers}` и
 * `{products}`. Разметку заказов и клиентов — RFM, когорту, LTV, номер заказа в
 * жизни клиента — не отдаёт ни витрина, ни сырьё, поэтому она считается здесь,
 * по всей истории, а не на окне отчёта. Иначе «новым» в месячном срезе окажется
 * всякий, кто не покупал последний месяц, а когорта будет означать «первый раз
 * появился в выбранном периоде».
 *
 * Справочники подключаются `LEFT JOIN`, и это безопасно: ключи в них
 * уникальны — 81 029 товаров и 154 693 контрагента, по одной строке на каждый.
 * Будь иначе, соединение размножило бы строки продаж и выручка выросла бы на
 * ровном месте.
 *
 * Русские имена в SQL обязаны стоять в обратных кавычках: ClickHouse принимает
 * без них только латиницу.
 */
final readonly class Mart
{
    /**
     * Строка продаж в именах отчётов.
     *
     * Меры — из сырья как есть: `amount`, `cost` и `qty` совпадают с
     * регистровыми мерами витрины на одном и том же документе. Прибыль берётся
     * готовой (`gross_profit`), а не считается разностью: в сырье она уже
     * сведена с поздними корректировками себестоимости.
     *
     * Отбор повторяет то, что делала витрина. Непроведённые и помеченные на
     * удаление документы — не продажа, их в деньгах быть не должно. Заказ
     * покупателя — тоже: это обещание купить, и витрина его не считала.
     * Возвраты оставлены, суммы у них отрицательные и уменьшают выручку, как и
     * прежде.
     *
     * Канал и признак возврата выводятся из типа документа — в сырье отдельных
     * полей для них нет. Названия каналов оставлены теми же, какие показывала
     * витрина: «ККМ» для розницы, «Расходная накладная» для накладных,
     * «Возврат без основания» для приходных, — иначе сравнение с прошлыми
     * снимками экрана рассыпалось бы на именах.
     */
    public static function sales(): string
    {
        $sales = self::source('sales');
        $items = self::source('items');
        $counterparties = self::source('counterparties');

        return <<<SQL
            (
                SELECT
                    s.period                                    AS data,
                    s.amount                                    AS summa,
                    s.cost                                      AS sebestoimost,
                    s.gross_profit                              AS pribyl,
                    s.qty                                       AS kolvo,
                    toString(s.doc_id)                          AS zakaz_uid,
                    s.doc_type IN ('ПриходнаяНакладная', 'ЧекККМВозврат') AS vozvrat,
                    -- Доля выручки, для которой себестоимости нет: по ней видно,
                    -- насколько верить марже.
                    s.cost = 0                                  AS bez_sebestoimosti,
                    multiIf(
                        s.doc_type IN ('ОтчетОРозничныхПродажах', 'ЧекККМ'),   'ККМ',
                        s.doc_type = 'РасходнаяНакладная',                     'Расходная накладная',
                        s.doc_type IN ('ПриходнаяНакладная', 'ЧекККМВозврат'), 'Возврат без основания',
                        'Прочая расходная'
                    )                                           AS kanal,
                    s.warehouse_name                            AS sklad,
                    s.manager_name                              AS menedzher,
                    ifNull(s.seller_name, '')                   AS prodavec,
                    s.org_name                                  AS organizaciya,
                    -- Кто продал. В чеке ККМ это продавец, в накладной —
                    -- ответственный, и поодиночке ни одно поле не покрывает обе
                    -- половины торговли. Вместе они закрывают почти всю выручку.
                    if(
                        empty(ifNull(s.seller_name, '')),
                        s.manager_name,
                        ifNull(s.seller_name, '')
                    )                                           AS sotrudnik,
                    ifNull(i.`товар_направление_деятельности`, '') AS napravlenie,
                    s.item_name                                 AS tovar,
                    toString(s.item_id)                         AS sku,
                    ifNull(i.`товар_бренд`, '')                 AS brend,
                    ifNull(i.`товар_группа_уровень_1`, '')      AS gruppa1,
                    ifNull(i.`товар_слив_сегмент`, '')          AS segment_tovara,
                    -- Метка стоит на товаре, а не на продаже: строка здесь
                    -- означает «продажа помеченного», а не «залежавшийся запас».
                    ifNull(i.`товар_сегмент_товара`, '')        AS nelikvid,
                    s.customer_name                             AS klient,
                    ifNull(c.`контрагент_тип`, '')              AS tip_klienta,
                    -- Сегмент клиента — второе звено пути группы:
                    -- «Клиенты (Коммерческий директор) / КОРП / Мастера» → КОРП.
                    -- Имена групп в 1С кое-где с хвостовым пробелом.
                    trimBoth(splitByString(' / ', ifNull(c.`контрагент_путь_группы`, ''))[2]) AS segment_klienta,
                    -- Покупки без карты остаются без контрагента. В выручке эти
                    -- деньги настоящие, но клиентом строка не является — в
                    -- подсчётах клиентов за ней стояли бы десятки тысяч разных
                    -- людей как один.
                    empty(s.customer_name)                      AS is_anonymous,
                    -- Ключ клиента: идентификатор, а когда 1С его не отдала —
                    -- имя. Безыдентификаторные иначе слились бы в одного
                    -- клиента с пустым ключом. Нулевой UUID — это и есть «не
                    -- отдала»: им помечены покупки без карты.
                    if(
                        s.customer_id = toUUID('00000000-0000-0000-0000-000000000000'),
                        s.customer_name,
                        toString(s.customer_id)
                    )                                           AS klient_id
                FROM {$sales} AS s
                LEFT JOIN {$items} AS i ON i.`товар_ид` = toString(s.item_id)
                LEFT JOIN {$counterparties} AS c ON c.`контрагент_ид_справочника` = toString(s.customer_id)
                WHERE s.doc_posted = 1
                  AND s.doc_deleted = 0
                  AND s.doc_type != 'ЗаказПокупателя'
            )
            SQL;
    }

    /**
     * Заказ как событие в жизни клиента: когда случился и был ли предыдущий.
     *
     * Строится по всей истории, потому что отвечает на вопрос «первый ли это
     * заказ» — на окне отчёта первым выглядел бы любой. Отбор по периоду
     * накладывается уже на результат.
     *
     * Документ, а не строка: в накладной из сорока позиций сорок строк, и
     * считать заказы строками значило бы считать позиции.
     */
    public static function orders(): string
    {
        return <<<'SQL'
            (
                SELECT
                    klient_id,
                    zakaz_uid,
                    order_date,
                    is_return,
                    revenue,
                    prev_order_date,
                    -- Нулевая дата — это «предыдущего заказа не было». Отчёты
                    -- спрашивают про первый заказ и про паузу, а не про то,
                    -- чем ClickHouse заполняет пустое окно.
                    prev_order_date = toDate('1970-01-01')  AS is_first,
                    if(
                        prev_order_date = toDate('1970-01-01'),
                        -1,
                        dateDiff('day', prev_order_date, order_date)
                    )                                       AS days_since_prev
                FROM (
                    SELECT
                        klient_id,
                        zakaz_uid,
                        order_date,
                        is_return,
                        revenue,
                        lagInFrame(order_date, 1) OVER (
                            PARTITION BY klient_id ORDER BY order_date ASC, zakaz_uid ASC
                            ROWS BETWEEN 1 PRECEDING AND CURRENT ROW
                        ) AS prev_order_date
                    FROM (
                        SELECT
                            klient_id,
                            zakaz_uid,
                            min(data)       AS order_date,
                            max(vozvrat)    AS is_return,
                            sum(summa)      AS revenue
                        FROM {sales}
                        WHERE is_anonymous = 0
                        GROUP BY klient_id, zakaz_uid
                    )
                )
            )
            SQL;
    }

    /**
     * Клиент целиком: когорта, LTV, давность и RFM-сегмент.
     *
     * Всё — по всей истории выгрузки. Пороги сегментов берутся из настроек:
     * «часто» и «много» для розничного магазина и для оптовика — разные числа,
     * и спорить о них должен администратор, а не запрос.
     *
     * Когорта считается надёжной, когда первая покупка клиента случилась не в
     * первый месяц выгрузки. У тех, кто покупал до её начала, «первый заказ» —
     * артефакт обрезанной истории: в когорту августа 2024 попал бы всякий, кто
     * покупает с 2019-го.
     */
    public static function customers(): string
    {
        $rfm = self::rfm();
        $sales = self::source('sales');

        return <<<SQL
            (
                SELECT
                    klient_id,
                    klient,
                    first_order_date,
                    lifetime_orders,
                    ltv,
                    formatDateTime(first_order_date, '%Y-%m')   AS kogorta,
                    first_order_date >= (
                        SELECT addMonths(toStartOfMonth(min(period)), 1) FROM {$sales}
                    )                                           AS kogorta_nadezhna,
                    dateDiff('day', last_order_date, today())   AS dney_s_pokupki,
                    dateDiff('day', first_order_date, today())  AS vozrast_klienta,
                    {$rfm}                                      AS rfm_segment
                FROM (
                    SELECT
                        klient_id,
                        any(klient)         AS klient,
                        min(data)           AS first_order_date,
                        max(data)           AS last_order_date,
                        uniqExact(zakaz_uid) AS lifetime_orders,
                        sum(summa)          AS ltv
                    FROM {sales}
                    WHERE is_anonymous = 0
                    GROUP BY klient_id
                )
            )
            SQL;
    }

    /**
     * RFM по двум осям вместо трёх.
     *
     * Давность — своя ось: она отвечает, жив ли клиент. Частота и деньги
     * сведены в одну — «ценность», и достаточно любой из них: оптовик с
     * четырьмя накладными на миллион и мастер с сорока визитами на ту же сумму
     * оба ценны, просто по-разному, и разводить их по разным сегментам значило
     * бы делить список по форме покупки, а не по важности клиента.
     *
     * Сегменты пронумерованы: отчёты сортируют их по имени, и номер выстраивает
     * список от лучших к потерянным сам.
     */
    private static function rfm(): string
    {
        $fresh = self::setting('rfm.fresh_days');
        $warm = self::setting('rfm.warm_days');
        $cold = self::setting('rfm.cold_days');
        $newcomer = self::setting('rfm.newcomer_days');
        $often = self::setting('rfm.frequent_orders');
        $regular = self::setting('rfm.regular_orders');
        $large = self::setting('rfm.large_money');
        $medium = self::setting('rfm.medium_money');

        return <<<SQL
            multiIf(
                dney_s_pokupki <= {$fresh} AND (lifetime_orders >= {$often}   OR ltv >= {$large}),  '1. Лучшие',
                dney_s_pokupki <= {$warm}  AND (lifetime_orders >= {$regular} OR ltv >= {$medium}), '2. Постоянные',
                dney_s_pokupki <= {$warm}  AND vozrast_klienta <= {$newcomer},                      '3. Новые',
                dney_s_pokupki <= {$warm},                                                          '4. Разовые',
                dney_s_pokupki <= {$cold}  AND (lifetime_orders >= {$regular} OR ltv >= {$medium}), '5. Под угрозой',
                '6. Потерянные'
            )
            SQL;
    }

    /**
     * Товар с разметкой ABC/XYZ.
     *
     * Разметка лежит в `mart.product_period` — отдельной таблице со снимком на
     * дату расчёта, в трёх окнах: 30, 90 и 365 дней. Берётся годовое: XYZ — про
     * ровность спроса, и на отрезке короче года «ровно берут» не означает
     * ничего.
     *
     * Снимок сделан по товару и складу сразу, а рейтинг нужен по товару.
     * Поэтому класс берётся у склада, через который прошла основная выручка:
     * товар, который на главном складе — A, остаётся A, даже если в дальнем
     * филиале его продали дважды.
     */
    public static function products(): string
    {
        $marks = self::source('product_marks');

        return <<<SQL
            (
                SELECT
                    `товар_ид`                          AS sku,
                    argMax(`абц`, `выручка`)            AS abc,
                    argMax(`икс_игрек_зет`, `выручка`)  AS xyz
                FROM {$marks}
                WHERE `период` = '365 дней'
                GROUP BY sku
            )
            SQL;
    }

    private static function setting(string $key): int
    {
        return (int) config("analytics.{$key}");
    }

    /**
     * Имя таблицы склада из настроек — с базой, потому что источники теперь
     * лежат в разных слоях: продажи в сыром, справочники в `dim`, снимок
     * ABC/XYZ в витринном.
     *
     * Значение подставляется в SQL, а не передаётся параметром: ClickHouse не
     * принимает имя таблицы плейсхолдером. Поэтому оно обязано приходить из
     * настроек администратора и никогда — от пользователя.
     */
    private static function source(string $key): string
    {
        /** @var string $table */
        $table = config("analytics.sources.{$key}");

        return $table;
    }
}
