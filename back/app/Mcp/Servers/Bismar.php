<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\Analytics\AnalyticsDirectory;
use App\Mcp\Tools\Analytics\CustomerReportTool;
use App\Mcp\Tools\Analytics\DepartmentPlan;
use App\Mcp\Tools\Analytics\EmployeeSales;
use App\Mcp\Tools\Analytics\ProductReportTool;
use App\Mcp\Tools\Analytics\SalesBreakdown;
use App\Mcp\Tools\Analytics\SalesReportTool;
use App\Mcp\Tools\Analytics\StaffReportTool;
use App\Mcp\Tools\Knowledge\ReadKnowledge;
use App\Mcp\Tools\Knowledge\SearchKnowledge;
use App\Mcp\Tools\Learning\PlannableMaterials;
use App\Mcp\Tools\Learning\SetLearningPlan;
use App\Mcp\Tools\Learning\ShowLearningPlan;
use App\Mcp\Tools\News\ListNews;
use App\Mcp\Tools\News\PublishNews;
use App\Mcp\Tools\Sales\ListSellers;
use App\Mcp\Tools\Sales\MapSeller;
use App\Mcp\Tools\Sales\SalesPlans;
use App\Mcp\Tools\Sales\SetSalesPlan;
use App\Mcp\Tools\Staff\AssignStaffTags;
use App\Mcp\Tools\Staff\CreateStaff;
use App\Mcp\Tools\Staff\DeleteStaff;
use App\Mcp\Tools\Staff\DismissStaff;
use App\Mcp\Tools\Staff\ListStaffTags;
use App\Mcp\Tools\Staff\ReinstateStaff;
use App\Mcp\Tools\Staff\RestoreStaff;
use App\Mcp\Tools\Staff\SearchStaff;
use App\Mcp\Tools\Staff\SetDismissalDate;
use App\Mcp\Tools\Staff\SetStaffAccess;
use App\Mcp\Tools\Staff\ShowStaff;
use App\Mcp\Tools\Staff\UpdateStaff;
use App\Mcp\Tools\Whoami;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Платформа BISMAR, открытая коннектору claude.ai.
 *
 * Сервер не заводит своих правил и своих запросов к базе: каждый инструмент
 * спрашивает то же право, которым закрыт соответствующий адрес HTTP-API, и
 * зовёт те же действия и отчёты, что и экран (см. App\Mcp\Tools\BismarTool).
 * Поэтому «полный доступ по роли» здесь буквален: администратор через коннектор
 * делает всё, что делает руками, продавец — только то, что открыто продавцу, а
 * уволенный не делает ничего (EnsureEmployed на маршруте).
 *
 * Инструменты, которые человеку недоступны, он и не видит: `shouldRegister`
 * каждого спрашивает право до выдачи списка.
 */
#[Name('BISMAR CRM')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
    Это внутренняя платформа компании BISMAR (crm.bismar.pro): база знаний
    (курсы, документы, справочники), учёт сотрудников, новости, планы обучения,
    торговая аналитика из ClickHouse и планы продаж по отделам.

    Все действия выполняются от имени сотрудника, который подключил коннектор, и
    ограничены его правами. Начни с инструмента `whoami`, чтобы узнать, кто это
    и что ему доступно: список инструментов уже отфильтрован по его правам, но
    отдельные действия (например удаление сотрудника) доступны ещё более узкому
    кругу и ответят отказом.

    Отказ в праве — это не сбой: передай его сотруднику как есть и не пробуй
    добиться того же другим инструментом.

    Изменения в платформе видны всей компании немедленно и журналируются. Прежде
    чем заводить, править, увольнять или удалять, перескажи сотруднику, что
    именно собираешься сделать, и дождись подтверждения. Даты пиши как
    ГГГГ-ММ-ДД, денежные суммы — в рублях.
    TEXT)]
final class Bismar extends Server
{
    /**
     * @var array<int, class-string>
     */
    protected array $tools = [
        Whoami::class,

        /* Сотрудники: кто есть, кого завести, кому что можно, кто ушёл. */
        SearchStaff::class,
        ShowStaff::class,
        CreateStaff::class,
        UpdateStaff::class,
        SetStaffAccess::class,
        DismissStaff::class,
        SetDismissalDate::class,
        ReinstateStaff::class,
        DeleteStaff::class,
        RestoreStaff::class,
        ListStaffTags::class,
        AssignStaffTags::class,

        /* База знаний: найти и прочитать. */
        SearchKnowledge::class,
        ReadKnowledge::class,

        /* Планы обучения: читает доверенный обучению, правит администратор. */
        ShowLearningPlan::class,
        PlannableMaterials::class,
        SetLearningPlan::class,

        /* Новости компании. */
        ListNews::class,
        PublishNews::class,

        /* Торговая аналитика — только чтение, и всё из ClickHouse. */
        AnalyticsDirectory::class,
        SalesReportTool::class,
        SalesBreakdown::class,
        ProductReportTool::class,
        CustomerReportTool::class,
        EmployeeSales::class,
        DepartmentPlan::class,

        /* Аналитика штата — своё право и своя область видимости. */
        StaffReportTool::class,

        /* Отделы продаж и планы. */
        ListSellers::class,
        MapSeller::class,
        SalesPlans::class,
        SetSalesPlan::class,
    ];

    /**
     * @var array<int, class-string>
     */
    protected array $resources = [];

    /**
     * @var array<int, class-string>
     */
    protected array $prompts = [];
}
