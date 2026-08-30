<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a person is in this system.
 *
 * There are no job titles here. A superadmin runs the place, an administrator
 * can do everything except appoint or remove superadmins — other
 * administrators they may appoint — and everyone else is simply a user whose
 * permissions are ticked one by one.
 */
enum AccessLevel: string
{
    case SuperAdmin = 'super-admin';
    case Admin = 'admin';
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Суперадминистратор',
            self::Admin => 'Администратор',
            self::User => 'Пользователь',
        };
    }

    /**
     * Whether this level passes every authorisation check on its own.
     *
     * A user's permissions are the ones granted to them; the two levels above
     * carry the lot without anything being ticked.
     */
    public function grantsEverything(): bool
    {
        return $this !== self::User;
    }

    /**
     * The levels stored as a role. `User` is the absence of one, so it has
     * nothing to store.
     *
     * @return list<self>
     */
    public static function stored(): array
    {
        return [self::SuperAdmin, self::Admin];
    }

    /**
     * @return list<string>
     */
    public static function storedValues(): array
    {
        return array_map(static fn (self $level): string => $level->value, self::stored());
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $level): string => $level->value, self::cases());
    }
}
