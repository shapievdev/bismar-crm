<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Events\Chat\MessagePinned;
use App\Models\Message;
use App\Models\User;
use App\Support\Chat\Announcement;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Поднимает реплику наверх переписки — и снимает оттуда.
 *
 * Закрепляют то, к чему возвращаются: время сверки, ссылку на бланк, состав
 * смены. В группе, где за день сотня реплик, иначе это ищут прокруткой, и ищут
 * каждый по отдельности.
 *
 * Закрепление — общее, а не личное: разговор один на всех, и полоса над лентой
 * должна показывать всем одно и то же. Поэтому и право на него не у всякого —
 * см. MessagePolicy::pin().
 */
final readonly class PinMessage
{
    public function __construct(private SayInConversation $say) {}

    /**
     * @throws AuthorizationException если закрепляют системную отметку или удалённое
     */
    public function pin(Message $message, User $actor): Message
    {
        if ($message->isSystem() || $message->trashed()) {
            throw new AuthorizationException('Это сообщение закрепить нельзя.');
        }

        if ($message->isPinned()) {
            return $message;
        }

        $message->forceFill([
            'pinned_at' => now(),
            'pinned_by_id' => $actor->getKey(),
        ])->save();

        /*
         * Отметка в ленте — как у правки состава, и по той же причине: наверху
         * молча появляется полоса, и объяснить, откуда она, иначе нечем. При
         * снятии такой отметки нет: исчезнувшая полоса никого не удивляет.
         */
        $this->say->system($message->conversation, sprintf('%s закрепил сообщение', $actor->name));

        Announcement::attempt(new MessagePinned($message));

        return $message;
    }

    public function unpin(Message $message): Message
    {
        if (! $message->isPinned()) {
            return $message;
        }

        $message->forceFill(['pinned_at' => null, 'pinned_by_id' => null])->save();

        Announcement::attempt(new MessagePinned($message));

        return $message;
    }
}
