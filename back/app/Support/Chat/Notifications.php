<?php

declare(strict_types=1);

namespace App\Support\Chat;

use App\Actions\Chat\SayInConversation;
use App\Enums\ConversationKind;
use App\Jobs\SendPush;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\Push\PushMessage;

/**
 * «Уведомления» — переписка сотрудника с приложением.
 *
 * Мессенджером, а не отдельным разделом, потому что отвечает на тот же вопрос,
 * что и переписка: «что нового, пока меня не было». Здесь это бесплатно —
 * непрочитанное, значок на рельсе, поиск, push, живая доставка через Reverb
 * уже написаны и работают с любой перепиской. Отдельный раздел означал бы
 * второй счётчик, второй список и второе место, куда надо не забыть заглянуть.
 *
 * Переписка на одного: участник в ней один — тот, кому уведомления. Говорит в
 * ней только приложение, системными отметками (`MessageKind::System`), и поля
 * ввода у неё нет: отвечать некому.
 *
 * Заводится лениво, с первым уведомлением. Пустые «Уведомления» в списке у
 * каждого, кому ещё ничего не приходило, — строчка, обещающая содержимое,
 * которого нет.
 */
final readonly class Notifications
{
    public const TITLE = 'Уведомления';

    public function __construct(private SayInConversation $say) {}

    /**
     * Шлёт уведомление: строкой в ленту и уведомлением на устройство.
     *
     * @param  array<string, mixed>|null  $about  карточка под текстом — см.
     *                                            App\Support\Chat\AchievementCard
     */
    public function send(User $to, string $text, ?array $about = null): Message
    {
        $conversation = $this->conversationFor($to);

        $message = $this->say->system($conversation, $text, $about);

        /*
         * Push отсюда, а не от того, кто уведомление шлёт: иначе одно событие
         * приходило бы человеку дважды — отметкой в ленте и отдельным
         * уведомлением мимо неё, и вели бы они в разные места.
         *
         * Приглушивший «Уведомления» получает их молча — ровно как в любой
         * другой переписке: приглушают звук, а не сам разговор.
         */
        if ($conversation->membershipOf($to)?->muted_at === null) {
            SendPush::dispatch([(int) $to->getKey()], new PushMessage(
                title: self::TITLE,
                body: PushMessage::shorten($text),
                url: '/messenger?id='.$conversation->getKey(),
                // Одна метка на всю ленту уведомлений: пять подряд заменяют
                // друг друга, а не выстраиваются столбиком на весь экран.
                tag: 'notifications-'.$conversation->getKey(),
            ));
        }

        return $message;
    }

    /**
     * Лента уведомлений этого человека. Заводится при первом обращении.
     *
     * Владелец записан в `created_by_id` — по нему же стоит уникальный
     * индекс: вторая лента у одного человека означала бы, что половина
     * уведомлений приходит в ту, которую он не открывает.
     */
    public function conversationFor(User $user): Conversation
    {
        $conversation = Conversation::query()->firstOrCreate(
            ['kind' => ConversationKind::System, 'created_by_id' => $user->getKey()],
            ['title' => self::TITLE],
        );

        if (! $conversation->includes($user)) {
            $conversation->participants()->syncWithoutDetaching([$user->getKey() => ['left_at' => null]]);
        }

        return $conversation;
    }
}
