<?php

declare(strict_types=1);

namespace App\Support\Chat;

use App\Enums\ConversationKind;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Общий чат компании: один на всех, и в нём все.
 *
 * Единственное место, которое знает о нём хоть что-то. Всё остальное —
 * список, непрочитанное, поиск, вложения, сокеты — работает с ним как с
 * обычной групповой перепиской, и ради этого состав **материализован**:
 * у каждого сотрудника есть настоящая строка участия. Подмени её правилом
 * «в общий чат входят все», и пришлось бы переписать и `scopeOf`, и `Unread`,
 * и проверку канала — четыре места вместо одного, и каждое со своим «кроме
 * общего».
 *
 * Состав ведёт приложение, а не человек: сотрудника вносит сюда его заведение
 * (CreateUser), а список переписок при каждом открытии проверяет, что
 * вошедший на месте. Вторая проверка не лишняя — учётные записи появляются и
 * мимо действия: сидером, выгрузкой, руками в консоли, — и без неё такой
 * человек остался бы единственным в компании, кто общего чата не видит.
 *
 * Уволенных не вычёркиваем: в приложение их всё равно не пускает
 * EnsureEmployed, а строка участия нужна, чтобы подписать сказанное ими
 * прежде.
 */
final class GeneralChat
{
    public const TITLE = 'Общий чат';

    /**
     * Та самая переписка. Заводится при первом обращении — на случай, если
     * база поднята без сидера.
     */
    public function conversation(): Conversation
    {
        return Conversation::query()->firstOrCreate(
            ['kind' => ConversationKind::General],
            ['title' => self::TITLE],
        );
    }

    /**
     * Вносит человека в общий чат, если его там ещё нет.
     *
     * Возвращённый на место `left_at` — не случайность: из общего чата не
     * выходят, и если строка кем-то помечена ушедшей, это ошибка, которую
     * надо исправить, а не состояние, которое надо уважать.
     */
    public function include(User $user): void
    {
        $this->attach($this->conversation(), [(int) $user->getKey()]);
    }

    /**
     * Вносит всех работающих. Зовётся из миграции и команды `chat:general`.
     *
     * @return int сколько человек внесено или возвращено
     */
    public function includeEveryone(): int
    {
        $conversation = $this->conversation();

        $ids = User::query()
            ->whereNull('deleted_at')
            ->whereNull('dismissed_at')
            ->pluck('id')
            ->map(intval(...))
            ->all();

        if ($ids === []) {
            return 0;
        }

        $this->attach($conversation, $ids);

        return count($ids);
    }

    /**
     * @param  list<int>  $userIds
     */
    private function attach(Conversation $conversation, array $userIds): void
    {
        $now = now();

        /*
         * Одним upsert, а не проверкой «есть ли» на каждого: в компании
         * сотни людей, и миграция иначе превратилась бы в сотни запросов.
         * Уникальный ключ пары стоит в базе с первого дня мессенджера — он и
         * решает, вставить строку или обновить.
         */
        DB::table('conversation_participants')->upsert(
            array_map(static fn (int $id): array => [
                'conversation_id' => $conversation->getKey(),
                'user_id' => $id,
                'left_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ], $userIds),
            ['conversation_id', 'user_id'],
            // Обновляем только признак ухода: отметки читателя — до какого
            // места дочитал, приглушил ли, поднял ли наверх — принадлежат ему.
            ['left_at', 'updated_at'],
        );
    }
}
