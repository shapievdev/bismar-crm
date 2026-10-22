<?php

declare(strict_types=1);

namespace App\Support\Analytics;

/**
 * Витрина в тех именах, которыми её знают отчёты.
 *
 * Столбцы `mart.sales_full` названы по-русски и подробно: `выручка_регистра`,
 * `ид_контрагента_документа`, `товар_группа_уровень_1`. Отчёты же спрашивают
 * про `summa`, `klient_id`, `gruppa1` — и это правильные для них слова: панель
 * говорит о выручке, а не о том, из какого регистра витрина её собрала.
 *
 * Поэтому перевод живёт здесь, одним куском, а не повторяется в каждом из
 * восемнадцати запросов. Отчёты читают не таблицу, а `{sales}` — подзапрос,
 * который переименовывает столбцы и ничего больше не делает. ClickHouse
 * протаскивает условие внутрь такого подзапроса сам, поэтому обёртка ничего не
 * стоит: отбор по периоду и фильтрам отсекает строки до чтения.
 *
 * Сверх перевода здесь лежат три подзапроса, которых витрина не содержит:
 * `{orders}`, `{customers}` и `{products}`. Прошлая витрина размечала заказы и
 * клиентов сама — в каждой строке продажи стояли RFM-сегмент, когорта, LTV и
 * номер заказа в жизни клиента. Нынешняя этого не делает, поэтому разметка
 * считается здесь — но по-прежнему на всей истории, а не на окне отчёта. Иначе
 * «новым» в месячном срезе окажется всякий, кто не покупал последний месяц, а
 * когорта будет означать «первый раз появился в выбранном периоде».
 *
 * Русские имена в SQL обязаны стоять в обратных кавычках: ClickHouse принимает
 * без них только латиницу.
 */
final readonly class Mart
{
    /**
     * Строка продаж в именах отчётов.
     *
     * Меры берутся из регистра, а не из документа: `сумма_строки` живёт только
     * у строк документа, а себестоимость приходит ещё и поздней корректировкой,
     * отдельным движением без строки. Регистровые `выручка_регистра` и
     * `себестоимость_регистра` учитывают оба случая, и `валовая_прибыль_регистра`
     * сходится с их разностью до копейки.
     *
     * Возвраты витрина помечает `это_возврат`, суммы у них отрицательные —
     * в выручку они входят как есть и уменьшают её.
     */
    public static function sales(): string
    {
        return <<<'SQL'
            (
                SELECT
                    `дата_отчета`                                               AS data,
                    `выручка_регистра`                                          AS summa,
                    `себестоимость_регистра`                                    AS sebestoimost,
                    `валовая_прибыль_регистра`                                  AS pribyl,
                    `количество_регистра`                                       AS kolvo,
                    `ид_документа`                                              AS zakaz_uid,
                    `это_возврат`                                               AS vozvrat,
                    -- Доля выручки, для которой витрина себестоимости не знает:
                    -- по ней видно, насколько верить марже.
                    `статус_себестоимости` = 'себестоимость_в_raw_равна_нулю'    AS bez_sebestoimosti,
                    `канал_продажи`                                             AS kanal,
                    `склад_документа`                                           AS sklad,
                    `ответственный_документа`                                   AS menedzher,
                    ifNull(`продавец`, '')                                      AS prodavec,
                    `организация`                                               AS organizaciya,
                    -- Кто продал. В чеке ККМ это продавец, в накладной —
                    -- ответственный, и поодиночке ни одно поле не покрывает обе
                    -- половины торговли: по ответственному видно 70 млн
                    -- накладных и 1 млн розницы, по продавцу — наоборот.
                    -- Вместе они закрывают 99 процентов выручки.
                    if(
                        empty(ifNull(`продавец`, '')),
                        `ответственный_документа`,
                        ifNull(`продавец`, '')
                    )                                                           AS sotrudnik,
                    ifNull(`товар_направление_деятельности`, '')                AS napravlenie,
                    `номенклатура_документа`                                    AS tovar,
                    ifNull(`товар_ид`, '')                                      AS sku,
                    ifNull(`товар_бренд`, '')                                   AS brend,
                    ifNull(`товар_группа_уровень_1`, '')                         AS gruppa1,
                    ifNull(`товар_слив_сегмент`, '')                            AS segment_tovara,
                    -- Метка стоит на товаре, а не на продаже: строка здесь
                    -- означает «продажа помеченного», а не «залежавшийся запас».
                    ifNull(`товар_сегмент_товара`, '')                           AS nelikvid,
                    `контрагент_документа`                                      AS klient,
                    ifNull(`контрагент_тип`, '')                                 AS tip_klienta,
                    -- Сегмент клиента — второе звено пути группы:
                    -- «Клиенты (Коммерческий директор) / КОРП / Мастера» → КОРП.
                    -- Имена групп в 1С кое-где с хвостовым пробелом.
                    trimBoth(splitByString(' / ', ifNull(`контрагент_путь_группы`, ''))[2]) AS segment_klienta,
                    -- Покупки без карты витрина оставляет без контрагента: имя
                    -- пустое, связь `not_found`. В выручке эти деньги настоящие,
                    -- но клиентом строка не является — в подсчётах клиентов за
                    -- ней стояли бы десятки тысяч разных людей как один.
                    empty(`контрагент_документа`)                                AS is_anonymous,
                    -- Ключ клиента: идентификатор, а когда 1С его не отдала —
                    -- имя. Иначе все безыдентификаторные клиенты сольются в
                    -- одного с пустым ключом.
                    ifNull(nullIf(`ид_контрагента_документа`, ''), `контрагент_документа`) AS klient_id
                FROM {db}.sales_full
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
                        SELECT addMonths(toStartOfMonth(min(`дата_отчета`)), 1) FROM {db}.sales_full
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
        return <<<'SQL'
            (
                SELECT
                    `товар_ид`                          AS sku,
                    argMax(`абц`, `выручка`)            AS abc,
                    argMax(`икс_игрек_зет`, `выручка`)  AS xyz
                FROM {db}.product_period
                WHERE `период` = '365 дней'
                GROUP BY sku
            )
            SQL;
    }

    private static function setting(string $key): int
    {
        return (int) config("analytics.{$key}");
    }
}
