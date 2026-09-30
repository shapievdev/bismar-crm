<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\Impersonation as Record;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Работа от чужого имени — одно правило на всё приложение.
 *
 * Держится сессией: в ней лежит номер того, кто вошёл **на самом деле**, а
 * `Auth` при этом отдаёт сотрудника, под которым работают. Отсюда два свойства,
 * ради которых всё и затевалось: приложение целиком ведёт себя как для
 * сотрудника (права, план, закрытые материалы — всё его), а вернуться к себе
 * можно в одно нажатие, потому что свой вход никуда не девался.
 *
 * Токеном это сделать нельзя: приложение ходит сессией Sanctum, и второй
 * «вход» рядом означал бы второй набор cookie, который браузер прислал бы
 * вперемешку.
 */
final class Impersonation
{
    /** Под этим ключом в сессии лежит тот, кто вошёл на самом деле. */
    public const ACTOR = 'impersonator_id';

    /** Номер открытой строки журнала — её закрывают на возврате. */
    public const RECORD = 'impersonation_id';

    public function __construct(private readonly Request $request) {}

    public function isActive(): bool
    {
        return $this->actorId() !== null;
    }

    /** Кто сидит за экраном на самом деле. */
    public function actorId(): ?int
    {
        $id = $this->request->session()->get(self::ACTOR);

        return is_numeric($id) ? (int) $id : null;
    }

    public function actor(): ?User
    {
        $id = $this->actorId();

        return $id === null ? null : User::query()->find($id);
    }

    /**
     * Запомнить, кто вошёл, и открыть строку журнала.
     *
     * Строка открывается здесь, а не в действии, потому что закрывать её
     * приходится из двух мест — с возврата и с выхода из приложения, — и знать
     * о ней должен один класс.
     */
    public function begin(User $actor, User $target): Record
    {
        $record = Record::query()->create([
            'impersonator_id' => $actor->getKey(),
            'user_id' => $target->getKey(),
            'ip' => $this->request->ip(),
            'started_at' => now(),
        ]);

        $this->request->session()->put(self::ACTOR, $actor->getKey());
        $this->request->session()->put(self::RECORD, $record->getKey());

        return $record;
    }

    /**
     * Закрыть окно: строку журнала и память сессии.
     *
     * Зовут отсюда и возврат к себе, и выход из приложения: незакрытая строка
     * означала бы окно, которое длится вечно, — а по этим окнам потом разбирают,
     * кто что сделал.
     */
    public function finish(): void
    {
        $id = $this->request->session()->get(self::RECORD);

        if (is_numeric($id)) {
            Record::query()->whereKey((int) $id)->open()->update(['ended_at' => now()]);
        }

        $this->request->session()->forget([self::ACTOR, self::RECORD]);
    }
}
