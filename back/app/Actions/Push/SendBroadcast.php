<?php

declare(strict_types=1);

namespace App\Actions\Push;

use App\Enums\BroadcastAudience;
use App\Exceptions\ConflictException;
use App\Jobs\SendPush;
use App\Models\Department;
use App\Models\PushBroadcast;
use App\Models\PushSubscription;
use App\Models\User;
use App\Support\Push\PushMessage;
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
    /**
     * @param  array{
     *     title: string,
     *     body: string,
     *     url?: ?string,
     *     audience: BroadcastAudience,
     *     user_ids?: list<int>,
     *     department_id?: ?int
     * } $attributes
     *
     * @throws ConflictException
     */
    public function handle(array $attributes, User $author): PushBroadcast
    {
        $audience = $attributes['audience'];
        $recipients = $this->recipients($audience, $attributes['user_ids'] ?? [], $attributes['department_id'] ?? null);

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

        return $broadcast->load('author', 'department', 'recipients');
    }

    /**
     * Кому уйдёт — только работающим: уволенному платформа закрыта, и звать его
     * туда уведомлением незачем.
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    private function recipients(BroadcastAudience $audience, array $userIds, ?int $departmentId): array
    {
        $people = User::query()->employed();

        if ($audience === BroadcastAudience::Selected) {
            $people->whereIn('id', $userIds);
        }

        if ($audience === BroadcastAudience::Department) {
            $people->whereHas(
                'departments',
                fn ($query) => $query->whereIn('departments.id', $this->branch($departmentId)),
            );
        }

        return $people->pluck('id')->map(intval(...))->all();
    }

    /**
     * Отдел вместе со всем, что под ним: рассылка «складу» касается и его
     * подотделов — иначе пришлось бы перечислять их руками, а завтра появится
     * новый, и о нём забудут.
     *
     * @return list<int>
     */
    private function branch(?int $departmentId): array
    {
        if ($departmentId === null) {
            return [];
        }

        /** @var array<int, int|null> $parents */
        $parents = Department::query()->pluck('parent_id', 'id')->all();

        $branch = [$departmentId];

        // Идём сверху вниз по всему справочнику: отделов десятки, и рекурсивный
        // запрос ради такого — из пушки по воробьям.
        do {
            $added = false;

            foreach ($parents as $id => $parent) {
                if ($parent !== null && in_array($parent, $branch, true) && ! in_array((int) $id, $branch, true)) {
                    $branch[] = (int) $id;
                    $added = true;
                }
            }
        } while ($added);

        return $branch;
    }
}
