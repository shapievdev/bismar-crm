<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Правка дня увольнения.
 *
 * Два предела, и оба не формальность. Будущим днём не увольняют: приложение
 * считает уволенным всякого, у кого дата стоит, и запись «уволится тридцать
 * первого» закрыла бы человеку вход сегодня, а в отчёте он числился бы в штате
 * до конца месяца. И раньше приёма — тоже: отчёт считает стаж разностью этих
 * дат, и перевёрнутая пара дала бы отрицательный стаж, который молча утянул бы
 * средние.
 *
 * Предел по дате приёма ставится, только когда она известна: в базе есть люди,
 * заведённые до того, как её начали спрашивать, и требовать от кадровика дату
 * пятилетней давности ради правки дня ухода незачем.
 */
final class UpdateDismissalRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $hired = $this->person()?->hired_at;

        return [
            'dismissed_at' => array_values(array_filter([
                'required',
                'date',
                'before_or_equal:today',
                $hired === null ? null : 'after_or_equal:'.$hired->toDateString(),
            ])),
        ];
    }

    /**
     * Отказы — по-русски и своими словами: «поле dismissed_at» кадровику не
     * говорит ничего, а речь о дне, который он только что набрал.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dismissed_at.required' => 'Укажите день увольнения.',
            'dismissed_at.date' => 'Это не похоже на дату.',
            'dismissed_at.before_or_equal' => 'Будущим днём не увольняют: человек либо уже ушёл, либо ещё работает.',
            'dismissed_at.after_or_equal' => 'Уволить раньше, чем приняли, нельзя — проверьте дату приёма в карточке.',
        ];
    }

    /**
     * Присланный день. Назван `day()`, а не `date()`: у самого `Request` метод
     * `date()` свой, с другой подписью, и переопределить его нельзя.
     */
    public function day(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('dismissed_at'));
    }

    /** Чью дату правят. */
    private function person(): ?User
    {
        $user = $this->route('user');

        return $user instanceof User ? $user : null;
    }
}
