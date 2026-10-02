<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Traits\HasRoles;

/**
 * A staff member as seen by one provider (provider database). The id is the
 * Platform user id. Roles and permissions are held here, so each provider
 * controls its own role templates.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $role
 * @property string|null $professional_number
 */
class Staff extends Model
{
    use HasRoles;

    protected $table = 'staff';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected string $guard_name = 'web';
}
