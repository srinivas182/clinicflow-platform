<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

/**
 * Permission catalogue. Permissions are stored per provider (Spatie tables in
 * the provider database) so each provider can adjust its role templates.
 */
final class Permission
{
    public const PATIENTS_VIEW = 'patients.view';

    public const PATIENTS_REGISTER = 'patients.register';

    public const PATIENTS_EDIT = 'patients.edit';

    public const SCRIPTS_SIGN = 'scripts.sign';

    public const STAFF_VIEW = 'staff.view';

    public const STAFF_MANAGE = 'staff.manage';

    public const AUDIT_VIEW = 'audit.view';

    public const SETTINGS_MANAGE = 'settings.manage';

    public const APPOINTMENTS_VIEW = 'appointments.view';

    public const APPOINTMENTS_BOOK = 'appointments.book';

    public const ROSTERS_MANAGE = 'rosters.manage';

    /**
     * Only prescribers (doctor, locum doctor) may ever hold these.
     */
    public const RESTRICTED_TO_PRESCRIBERS = [self::SCRIPTS_SIGN];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::PATIENTS_VIEW, self::PATIENTS_REGISTER, self::PATIENTS_EDIT, self::SCRIPTS_SIGN,
            self::STAFF_VIEW, self::STAFF_MANAGE, self::AUDIT_VIEW, self::SETTINGS_MANAGE,
            self::APPOINTMENTS_VIEW, self::APPOINTMENTS_BOOK, self::ROSTERS_MANAGE,
        ];
    }
}
