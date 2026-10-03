<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $vat_number
 * @property bool $active
 */
class Supplier extends Model
{
    protected $guarded = ['id'];
}
