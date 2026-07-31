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

    case ViewUsers = 'users.view';
    case ManageUsers = 'users.manage';
    case ManageRoles = 'roles.manage';

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
            self::ViewUsers => 'Просмотр пользователей',
            self::ManageUsers => 'Управление пользователями',
            self::ManageRoles => 'Управление ролями',
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
