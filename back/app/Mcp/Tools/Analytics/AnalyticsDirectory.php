<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Analytics;

use App\Enums\Permission;
use App\Mcp\Tools\BismarTool;
use App\Support\Analytics\Directory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Из чего собирается отбор и по какое число доехали данные.
 *
 * Спрашивать это стоит до первого отчёта: названия складов, каналов и
 * менеджеров приходят из витрины, а не из платформы, и угадать их нельзя —
 * «Основной склад» там может зваться иначе. Отбор по несуществующему значению
 * не ошибка, а пустая панель, и отличить её от «ничего не продали» нечем.
 *
 * Свежесть — вторая половина ответа, и не менее важная: витрина обновляется
 * выгрузкой, и сегодняшних продаж в ней может ещё не быть. Без этого числа
 * «за сегодня ноль» читалось бы как провал в торговле.
 */
#[Name('analytics_directory')]
#[Title('Справочник аналитики')]
#[Description('Значения, по которым можно отбирать торговую аналитику — каналы продаж, склады и магазины, менеджеры, сегменты клиентов, — и по какое число доехали данные в витрине. Позовите это прежде первого отчёта: отбор по выдуманному значению даст пустой ответ, а не ошибку, и сегодняшние продажи могут быть ещё не загружены.')]
final class AnalyticsDirectory extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ViewAnalytics);
    }

    public function handle(Request $request, Directory $directory): ResponseFactory|Response
    {
        $this->permit($request, Permission::ViewAnalytics);

        return $this->attempt(fn (): ResponseFactory => Response::structured($directory->options()));
    }
}
