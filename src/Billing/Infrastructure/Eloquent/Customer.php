<?php

declare(strict_types=1);

namespace Metered\Billing\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Read model.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $reference
 * @property string $name
 * @property DateTimeImmutable $created_at
 */
final class Customer extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'customers';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
