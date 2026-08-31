<?php

declare(strict_types=1);

namespace App\Actions\Push;

use App\Enums\BroadcastAudience;
use App\Exceptions\ConflictException;
use App\Jobs\SendPush;
use App\Models\PushBroadcast;
use App\Models\PushSubscription;
use App\Models\User;
use App\Support\Push\PushMessage;
use App\Support\Structure\DepartmentReach;
use Illuminate\Support\Facades\DB;

/**
 * Рассылка уведомлений: написали — ушло на устройства.
 *
 * Отправку берёт на себя очередь (SendPush), а здесь — кому именно и запись в
 * историю: уведомление нельзя открыть заново, и без записи никто потом не
 * скажет, что именно разослали.
 *
 * Автор из адресатов не вычитается. Своё уведомление на своём телефоне — самое
 * простое доказательство, что рассылка дошла; узнать текст он и так знает.
 */
final readonly class SendBroadcast
{
    public function __construct(private DepartmentReach $reach) {}

    /**
     * @param  array{
     *     title: string,
     *     body: string,
     *     url?: ?string,
     *     audience: BroadcastAudience,
     *     user_ids?: list<int>,
     *     department_id?: ?int,
     *     group_id?: ?int
     * } $attributes
     *
     * @throws ConflictException
     */
    public function handle(array $attributes, User $author): PushBroadcast
    {
        $audience = $attributes['audience'];
        $recipients = $this->recipients($audience, $attributes);

        if ($recipients === []) {
            throw new ConflictException('Некому отправлять: среди выбранных нет работающих сотрудников.');
        }

        $broadcast = DB::transaction(function () use ($attributes, $audience, $author, $recipients): PushBroadcast {
            $broadcast = PushBroadcast::query()->create([
                'author_id' => $author->getKey(),
                'title' => $attributes['title'],
                'body' => $attributes['body'],
                'url' => $attributes['url'] ?? null,
                'audience' => $audience,
                'department_id' => $audience === BroadcastAudience::Department
                    ? $attributes['department_id']
                    : null,
                'group_id' => $audience === BroadcastAudience::Group
                    ? $attributes['group_id']
                    : null,
                'recipients_count' => count($recipients),
                // Устройств меньше, чем людей: подписался не каждый и не на
                // каждом своём телефоне. Число говорит, до скольких дошло.
                'devices_count' => PushSubscription::query()->whereIn('user_id', $recipients)->count(),
                'sent_at' => now(),
            ]);

            if ($audience === BroadcastAudience::Selected) {
                $broadcast->recipients()->attach($recipients);
            }

            return $broadcast;
        });

        SendPush::dispatch($recipients, new PushMessage(
            title: $broadcast->title,
            body: $broadcast->body,
            url: $broadcast->url ?: '/',
            // Своё имя у каждой рассылки: две отправленные подряд не должны
            // заменять одна другую на экране.
            tag: 'broadcast-'.$broadcast->getKey(),
        ));

        return $broadcast->load('author', 'department', 'group', 'recipients');
    }

    /**
     * Кому уйдёт — только работающим: уволенному платформа закрыта, и звать его
     * туда уведомлением незачем.
     *
     * @param  array{user_ids?: list<int>, department_id?: ?int, group_id?: ?int}  $attributes
     * @return list<int>
     */
    private function recipients(BroadcastAudience $audience, array $attributes): array
    {
        $people = User::query()->employed();

        if ($audience === BroadcastAudience::Selected) {
            $people->whereIn('id', $attributes['user_ids'] ?? []);
        }

        if ($audience === BroadcastAudience::Department) {
            // Отдел вместе со всем, что под ним: рассылка «складу» касается и
            // его подотделов — см. DepartmentReach.
            $people->whereHas('departments', fn ($query) => $query->whereIn(
                'departments.id',
                $this->reach->branch(array_filter([$attributes['department_id'] ?? null])),
            ));
        }

        if ($audience === BroadcastAudience::Group) {
            // Группа — ровно те, кого в неё внесли: вложенности у групп нет.
            $people->whereHas('groups', fn ($query) => $query->whereKey($attributes['group_id'] ?? 0));
        }

        return $people->pluck('id')->map(intval(...))->all();
    }
}
