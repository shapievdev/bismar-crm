<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BroadcastAudience;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Отправленная рассылка. Правке не подлежит: уведомление уже на экранах.
 */
#[Fillable([
    'author_id', 'title', 'body', 'url', 'audience', 'department_id', 'group_id',
    'recipients_count', 'devices_count', 'sent_at',
])]
class PushBroadcast extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Названные поимённо. У рассылки «всем» список не хранится: он и так весь
     * штат, а копия из сотен строк на каждую отправку — это не история.
     *
     * @return BelongsToMany<User, $this>
     */
    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'push_broadcast_recipients');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => BroadcastAudience::class,
            'sent_at' => 'datetime',
        ];
    }
}
