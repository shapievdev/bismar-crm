<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Sales;

use App\Enums\Permission;
use App\Mcp\Tools\BismarTool;
use App\Support\Sales\SellerDirectory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Продавцы витрины и то, чему они сопоставлены.
 *
 * Та самая связь, без которой выручку по отделам не разложить: в 1С продавец —
 * строка с приставкой, у нас — человек в структуре, и общего ключа у них нет.
 * Пока связь не заведена, отчёт по отделам пуст, и это первое, что стоит
 * проверить, когда он ничего не показывает.
 */
#[Name('sales_sellers')]
#[Title('Продавцы и отделы')]
#[Description('Имена продавцов из торговой витрины и то, какому отделу и какому сотруднику платформы каждое сопоставлено, вместе с подсказками по несопоставленным. Без этой связи отчёт по отделам и табло гонки пусты. Менять связи — sales_map_seller.')]
final class ListSellers extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ManageSalesPlans);
    }

    public function handle(Request $request, SellerDirectory $directory): ResponseFactory|Response
    {
        $this->permit($request, Permission::ManageSalesPlans);

        return $this->attempt(fn (): ResponseFactory => Response::structured($directory->toArray()));
    }
}
