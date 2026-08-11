<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Строка участия: человек в переписке.
 *
 * Отдельным классом ради приведения типов. Связующая таблица сама по себе
 * отдаёт время строкой, и `last_read_at` в ней — не дата, а набор символов;
 * первое же обращение к нему как к дате роняет выдачу списка.
 */
class ConversationParticipant extends Pivot
{
    protected $table = 'conversation_participants';

    public $incrementing = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }
}
