<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

use App\Domains\Platform\Enums\ProviderType;

/**
 * Staff roles inside a provider workspace. Each provider gets these as editable
 * role templates; Permission::RESTRICTED_TO_PRESCRIBERS can never be overridden.
 */
enum StaffRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case PracticeAdmin = 'practice_admin';
    case Receptionist = 'receptionist';
    case Nurse = 'nurse';
    case Doctor = 'doctor';
    case LocumDoctor = 'locum_doctor';
    case Pharmacist = 'pharmacist';
    case Dispatch = 'dispatch';
    case BillingClerk = 'billing_clerk';
    case LabTechnician = 'lab_technician';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Manager => 'Clinic manager',
            self::PracticeAdmin => 'Practice admin',
            self::Receptionist => 'Receptionist',
            self::Nurse => 'Nurse',
            self::Doctor => 'Doctor',
            self::LocumDoctor => 'Locum doctor',
            self::Pharmacist => 'Pharmacist',
            self::Dispatch => 'Dispatch',
            self::BillingClerk => 'Billing clerk',
            self::LabTechnician => 'Lab technician',
        };
    }

    /**
     * Roles offered to each provider type.
     *
     * @return list<self>
     */
    public static function forProviderType(ProviderType $type): array
    {
        return match ($type) {
            ProviderType::Clinic => self::cases(),
            ProviderType::IndependentDoctor => [self::Owner, self::PracticeAdmin, self::Receptionist, self::Nurse, self::Doctor, self::LocumDoctor, self::BillingClerk],
            ProviderType::Pharmacy => [self::Owner, self::Manager, self::Pharmacist, self::Dispatch, self::BillingClerk],
            ProviderType::Lab => [self::Owner, self::Manager, self::LabTechnician, self::Receptionist, self::BillingClerk],
        };
    }

    /**
     * Default permissions for the role template.
     *
     * @return list<string>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::Owner => array_values(array_diff(Permission::all(), Permission::RESTRICTED_TO_PRESCRIBERS)),
            self::Manager => [Permission::PATIENTS_VIEW, Permission::PATIENTS_REGISTER, Permission::STAFF_VIEW, Permission::AUDIT_VIEW, Permission::APPOINTMENTS_VIEW, Permission::APPOINTMENTS_BOOK, Permission::ROSTERS_MANAGE, Permission::VISITS_MANAGE, Permission::BILLING_COLLECT, Permission::BILLING_REFUND, Permission::CLAIMS_MANAGE, Permission::DISCHARGE_OVERRIDE],
            self::PracticeAdmin => [Permission::PATIENTS_VIEW, Permission::STAFF_VIEW, Permission::STAFF_MANAGE, Permission::AUDIT_VIEW, Permission::SETTINGS_MANAGE, Permission::APPOINTMENTS_VIEW, Permission::ROSTERS_MANAGE],
            self::Receptionist => [Permission::PATIENTS_VIEW, Permission::PATIENTS_REGISTER, Permission::PATIENTS_EDIT, Permission::APPOINTMENTS_VIEW, Permission::APPOINTMENTS_BOOK, Permission::VISITS_MANAGE, Permission::BILLING_COLLECT],
            self::Nurse => [Permission::PATIENTS_VIEW, Permission::PATIENTS_EDIT, Permission::APPOINTMENTS_VIEW, Permission::VISITS_MANAGE, Permission::TRIAGE_RECORD],
            self::Doctor, self::LocumDoctor => [Permission::PATIENTS_VIEW, Permission::PATIENTS_EDIT, Permission::SCRIPTS_SIGN, Permission::APPOINTMENTS_VIEW, Permission::VISITS_MANAGE, Permission::TRIAGE_RECORD, Permission::CONSULTS_WRITE],
            self::Pharmacist => [Permission::PATIENTS_VIEW, Permission::VISITS_MANAGE, Permission::PHARMACY_DISPENSE],
            self::Dispatch => [Permission::PATIENTS_VIEW, Permission::VISITS_MANAGE],
            self::LabTechnician => [Permission::PATIENTS_VIEW],
            self::BillingClerk => [Permission::PATIENTS_VIEW, Permission::BILLING_COLLECT, Permission::BILLING_REFUND, Permission::CLAIMS_MANAGE],
        };
    }

    public function isPrescriber(): bool
    {
        return in_array($this, [self::Doctor, self::LocumDoctor], true);
    }
}
