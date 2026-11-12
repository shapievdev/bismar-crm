<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every capability the CRM can grant.
 *
 * Permission names live in code rather than only in the database: they are
 * referenced by policies and routes, so they must be reviewable, greppable and
 * safe to rename. The database rows are synced from this enum by the seeder.
 */
enum Permission: string
{
    case ViewContacts = 'contacts.view';
    case CreateContacts = 'contacts.create';
    case UpdateContacts = 'contacts.update';
    case DeleteContacts = 'contacts.delete';

    case ViewCompanies = 'companies.view';
    case CreateCompanies = 'companies.create';
    case UpdateCompanies = 'companies.update';
    case DeleteCompanies = 'companies.delete';

    case ViewDeals = 'deals.view';
    case CreateDeals = 'deals.create';
    case UpdateDeals = 'deals.update';
    case DeleteDeals = 'deals.delete';

    case ViewCourses = 'courses.view';
    case CreateCourses = 'courses.create';
    case UpdateCourses = 'courses.update';
    case DeleteCourses = 'courses.delete';
    case PublishCourses = 'courses.publish';
    case ManageEnrollments = 'enrollments.manage';

    /*
     * Документы и справочники — свои права у каждого раздела (решение
     * пользователя 2026-09-11; до этого оба раздела отвечали правам на курсы).
     *
     * Разные потому, что разное и назначение: правила компании ведёт тот, кто
     * за них отвечает, а справочник для зала правит старший смены, которому в
     * регламентах делать нечего. Пока право было одно, «дать вести справочник»
     * означало «дать переписать все правила».
     *
     * Публикации среди них нет намеренно: у курса она отдельна, потому что курс
     * собирают месяцами и выпускают разом, а документ пишут и выпускают одним
     * движением — кто правит, тот и публикует.
     *
     * Какое право за каким действием, знает App\Enums\MaterialKind: раздел
     * ходит по приложению видом, а не строкой, и третий вид добавляется здесь
     * и там, а не в двадцати местах.
     */
    case ViewDocuments = 'documents.view';
    case CreateDocuments = 'documents.create';
    case UpdateDocuments = 'documents.update';
    case DeleteDocuments = 'documents.delete';

    case ViewHandbooks = 'handbooks.view';
    case CreateHandbooks = 'handbooks.create';
    case UpdateHandbooks = 'handbooks.update';
    case DeleteHandbooks = 'handbooks.delete';

    /**
     * Новости: писать, публиковать, решать кому видно и смотреть, кто
     * ознакомился.
     *
     * Права на чтение нет намеренно: новости живут на главной странице, и
     * человек, вошедший в систему, видит адресованное ему по определению.
     */
    case ManageNews = 'news.manage';

    case ViewUsers = 'users.view';
    case ManageUsers = 'users.manage';

    /**
     * Торговая аналитика: выручка, прибыль, долги, остатки.
     *
     * Одно право на весь раздел, а не на каждую панель. Дробить его нечем:
     * цифры приходят из одной выгрузки, и человек, которому показали выручку,
     * увидит её же в разрезе менеджеров — прятать от него вкладку смысла нет.
     */
    case ViewAnalytics = 'analytics.view';

    /**
     * Аналитика штата: движение персонала, стаж, текучесть.
     *
     * Два права, а не одно, и дробление здесь не прихоть — это персональные
     * данные. Кадровик и директор видят одно и то же движение, но по-разному
     * широко: одному нужна вся компания, второму — своё направление, и дать
     * второму первое значит показать зарплатную кухню соседних отделов.
     *
     * Область считается не этим правом, а местом человека в структуре
     * (App\Support\Structure\DepartmentReach): право отвечает «пускать ли»,
     * структура — «куда».
     */
    case ViewStaffReport = 'staff-report.view';

    case ViewWholeStaffReport = 'staff-report.view-all';

    /** Ручные теги сотрудников: справочник и кому что повешено. */
    case ManageStaffTags = 'staff-tags.manage';

    /**
     * Отделы продаж и планы: кто из витрины в каком отделе и сколько с него
     * ждут за месяц.
     *
     * Отдельно от просмотра аналитики, потому что это не чтение цифр, а
     * решение о них: сопоставление задаёт, чья выручка чья, а план — мерку, по
     * которой отдел потом оценят. Коммерческому директору это нужно,
     * администратором ради этого он быть не обязан.
     */
    case ManageSalesPlans = 'sales-plans.manage';

    /**
     * Экзамены: собрать, назначить людям, увидеть результаты всех.
     *
     * Одно право, а не четыре, как у разделов базы знаний: экзамен не читают —
     * его сдают, и сдают по назначению, без всякого права. Делить «создать» и
     * «назначить» не из чего: назначение несобранного экзамена бессмысленно, и
     * тот, кто отвечает за экзамены, делает и то и другое.
     *
     * Сдающему права не нужно намеренно: доступ даёт назначение, как у очереди
     * аттестаций, — чужой экзамен так не открыть, отбор идёт по вошедшему.
     */
    case ManageExams = 'exams.manage';

    /**
     * Адаптация: шаблоны маршрутов, назначение, переносы, выпуск.
     *
     * Маршрут назначают любому сотруднику, а не только новичку: та же карта
     * этапов служит и переводу на другую должность, и вводу в новый магазин.
     *
     * Одно право по той же логике, что у экзаменов: проходящему право не
     * нужно — его пускает назначенный маршрут, — а наставнику и ответственным
     * встреч и заданий доступ даёт назначение на этап. Право держит только
     * тот, кто ведёт адаптацию: HR и менеджер по обучению.
     */
    case ManageOnboarding = 'onboarding.manage';

    /**
     * Достижения: каталог значков, которые шаги адаптации раздают сами.
     *
     * Отдельно от ведения адаптации по той же причине, по какой теги
     * сотрудников отделены от управления людьми: заводить значки и строить
     * маршруты — разные работы. Наполнять каталог («Первый выход в зал» с
     * картинкой) будет тот, кто рисует, а выбирать из готового в этапе —
     * `onboarding.manage`: выбор из справочника права на справочник не
     * требует, иначе каждому методисту пришлось бы дать и правку каталога.
     *
     * Выдачи здесь нет намеренно: достижение не вручают — его зарабатывают
     * закрытым шагом, и выдаёт его пересчёт маршрута, а не человек.
     */
    case ManageAchievements = 'achievements.manage';

    /**
     * Human-readable label for permission management screens.
     */
    public function label(): string
    {
        return match ($this) {
            self::ViewContacts => 'Просмотр контактов',
            self::CreateContacts => 'Создание контактов',
            self::UpdateContacts => 'Редактирование контактов',
            self::DeleteContacts => 'Удаление контактов',
            self::ViewCompanies => 'Просмотр компаний',
            self::CreateCompanies => 'Создание компаний',
            self::UpdateCompanies => 'Редактирование компаний',
            self::DeleteCompanies => 'Удаление компаний',
            self::ViewDeals => 'Просмотр сделок',
            self::CreateDeals => 'Создание сделок',
            self::UpdateDeals => 'Редактирование сделок',
            self::DeleteDeals => 'Удаление сделок',
            self::ViewCourses => 'Просмотр курсов',
            self::CreateCourses => 'Создание курсов',
            self::UpdateCourses => 'Редактирование курсов',
            self::DeleteCourses => 'Удаление курсов',
            self::PublishCourses => 'Публикация курсов',
            self::ManageEnrollments => 'Управление записью на курсы',
            self::ViewDocuments => 'Просмотр документов',
            self::CreateDocuments => 'Создание документов',
            self::UpdateDocuments => 'Редактирование документов',
            self::DeleteDocuments => 'Удаление документов',
            self::ViewHandbooks => 'Просмотр справочников',
            self::CreateHandbooks => 'Создание справочников',
            self::UpdateHandbooks => 'Редактирование справочников',
            self::DeleteHandbooks => 'Удаление справочников',
            self::ManageNews => 'Ведение новостей',
            self::ViewUsers => 'Просмотр пользователей',
            self::ManageUsers => 'Управление пользователями',
            self::ViewAnalytics => 'Просмотр аналитики',
            self::ViewStaffReport => 'Аналитика штата по своим подразделениям',
            self::ViewWholeStaffReport => 'Аналитика штата по всей компании',
            self::ManageStaffTags => 'Ведение тегов сотрудников',
            self::ManageSalesPlans => 'Отделы продаж и планы',
            self::ManageExams => 'Ведение экзаменов',
            self::ManageOnboarding => 'Ведение адаптации',
            self::ManageAchievements => 'Ведение достижений',
        };
    }

    /** The area this permission belongs to, as the access editor groups them. */
    public function group(): string
    {
        return explode('.', $this->value)[0];
    }

    /**
     * A heading a person can read. The prefix alone is a machine name — the
     * editor is for people choosing what a colleague may do.
     */
    public function groupLabel(): string
    {
        return match ($this->group()) {
            'contacts' => 'Контакты',
            'companies' => 'Компании',
            'deals' => 'Сделки',
            'courses' => 'База знаний',
            'enrollments' => 'Запись на курсы',
            'documents' => 'Документы',
            'handbooks' => 'Справочники',
            'news' => 'Новости',
            'users' => 'Пользователи',
            'analytics' => 'Аналитика',
            'staff-report' => 'Аналитика штата',
            'staff-tags' => 'Теги сотрудников',
            'sales-plans' => 'Отделы продаж и планы',
            'exams' => 'Экзамены',
            'onboarding' => 'Адаптация',
            'achievements' => 'Достижения',
            default => $this->group(),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
