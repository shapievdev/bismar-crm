<?php

declare(strict_types=1);

namespace App\Support\Chat;

/**
 * Что известно о надиктованной записи сверх самого файла.
 *
 * Длительность и волна приходят от клиента: браузер уже разобрал запись, чтобы
 * рисовать волну во время диктовки, и взять у него готовое стоит нуля. Считать
 * то же самое на сервере значит распаковывать звук средствами PHP — то есть
 * тащить ffmpeg в развёртывание ради полоски под кнопкой.
 *
 * Врать этими числами бессмысленно: длительность видна по самой записи, а
 * подделанная волна испортит вид только тому, кто её прислал. Поэтому здесь
 * проверяется не правдивость, а вменяемость — чтобы в базу не легло ни
 * трёхчасовое «голосовое», ни огибающая на миллион столбиков.
 */
final readonly class VoiceRecording
{
    /** Сколько столбиков рисует волна. Больше в пузырь всё равно не влезет. */
    public const BARS = 56;

    /** Дольше пяти минут — это уже не реплика, а запись совещания. */
    public const MAX_SECONDS = 300;

    /**
     * @param  list<int>  $waveform  высоты столбиков, 0–100
     */
    private function __construct(
        public int $durationMs,
        public array $waveform,
    ) {}

    /**
     * @param  array<int, mixed>  $waveform  как его прислали
     */
    public static function from(int $durationMs, array $waveform): self
    {
        $bars = array_slice(
            array_map(
                static fn (mixed $height): int => max(0, min(100, (int) $height)),
                array_values($waveform),
            ),
            0,
            self::BARS,
        );

        return new self(
            durationMs: max(0, min($durationMs, self::MAX_SECONDS * 1000)),
            waveform: $bars,
        );
    }
}
