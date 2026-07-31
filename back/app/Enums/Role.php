<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The roles the CRM ships with.
 *
 * Administrators may create further roles at runtime; these are the defaults
 * the seeder guarantees to exist, so code can rely on them.
 */
enum Role: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Sales = 'sales';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Администратор',
            self::Manager => 'Руководитель',
            self::Sales => 'Менеджер по продажам',
            self::Viewer => 'Наблюдатель',
        };
    }

    /**
     * Permissions granted to this role out of the box.
     *
     * Admin is deliberately absent from the explicit grants: it is handled by a
     * Gate::before hook so a new permission is never accidentally withheld from
     * administrators.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),

            self::Manager => [
                Permission::ViewContacts,
                Permission::CreateContacts,
                Permission::UpdateContacts,
                Permission::DeleteContacts,
                Permission::ViewCompanies,
                Permission::CreateCompanies,
                Permission::UpdateCompanies,
                Permission::DeleteCompanies,
                Permission::ViewDeals,
                Permission::CreateDeals,
                Permission::UpdateDeals,
                Permission::DeleteDeals,
                Permission::ViewKnowledge,
                Permission::CreateKnowledge,
                Permission::UpdateKnowledge,
                Permission::DeleteKnowledge,
                Permission::PublishKnowledge,
                Permission::ViewUsers,
            ],

            self::Sales => [
                Permission::ViewContacts,
                Permission::CreateContacts,
                Permission::UpdateContacts,
                Permission::ViewCompanies,
                Permission::CreateCompanies,
                Permission::UpdateCompanies,
                Permission::ViewDeals,
                Permission::CreateDeals,
                Permission::UpdateDeals,
                Permission::ViewKnowledge,
                Permission::CreateKnowledge,
                Permission::UpdateKnowledge,
            ],

            self::Viewer => [
                Permission::ViewContacts,
                Permission::ViewCompanies,
                Permission::ViewDeals,
                Permission::ViewKnowledge,
            ],
        };
    }

    /**
     * @return list<string>
     */
    public function permissionValues(): array
    {
        return array_map(
            static fn (Permission $permission): string => $permission->value,
            $this->permissions(),
        );
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
