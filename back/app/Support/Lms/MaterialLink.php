<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Models\Lesson;
use App\Models\MaterialVersion;
use App\Models\Regulation;

/**
 * Как назвать материал, при котором стоит тест, и куда вести за ним.
 *
 * Спрашивают об этом в трёх разных местах — очередь проверяющего, уведомление
 * ему же и уведомление сдавшему, — а ответ один и тот же: название, откуда оно
 * (курс у урока, документ у версии) и адрес страницы внутри приложения.
 * Собирать его в каждом месте заново значило бы однажды поправить два.
 *
 * Адреса нет, когда материал остался без владельца: урок без курса, версия без
 * документа. Вести в таком случае некуда, и уведомление обходится без ссылки —
 * это честнее, чем привести в пустоту.
 */
final readonly class MaterialLink
{
    private function __construct(
        /** Вид материала: `lesson`, `document`, `handbook`. */
        public string $kind,
        public string $title,
        /** Откуда материал: курс у урока, документ у версии. У прочих пусто. */
        public ?string $context,
        public ?string $url,
    ) {}

    /**
     * Ссылка на владельца теста. Null, когда владельца нет вовсе: материал
     * удалили вместе с ним.
     */
    public static function for(mixed $owner): ?self
    {
        return match (true) {
            $owner instanceof Lesson => self::forLesson($owner),
            $owner instanceof MaterialVersion => self::forVersion($owner),
            $owner instanceof Regulation => self::forMaterial($owner),
            default => null,
        };
    }

    /**
     * Одной строкой: название и откуда оно. Такой вид нужен уведомлению, где
     * места на две строки нет.
     */
    public function caption(): string
    {
        return $this->context === null ? $this->title : $this->title.' · '.$this->context;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'course' => $this->context,
            'url' => $this->url,
        ];
    }

    private static function forLesson(Lesson $lesson): self
    {
        $course = $lesson->owningCourse();

        return new self(
            kind: 'lesson',
            title: (string) $lesson->title,
            context: $course?->title,
            url: $course === null ? null : '/lms/'.$course->slug.'/lessons/'.$lesson->getKey(),
        );
    }

    private static function forMaterial(Regulation $material): self
    {
        return new self(
            kind: $material->kind->value,
            title: (string) $material->title,
            context: null,
            url: $material->path(),
        );
    }

    /**
     * Версия живёт на своей странице внутри материала: там её текст, её файлы и
     * её проверка. Название у неё своё («Для розницы»), а откуда она — говорит
     * материал: «Для розницы · Регламент о зарплате» читается лучше, чем
     * название версии само по себе.
     */
    private static function forVersion(MaterialVersion $version): self
    {
        $owner = $version->owner();

        if ($owner instanceof Lesson) {
            $course = $owner->owningCourse();

            return new self(
                kind: 'lesson',
                title: (string) $version->name,
                context: $owner->title,
                url: $course === null
                    ? null
                    : '/lms/'.$course->slug.'/lessons/'.$owner->getKey().'/versions/'.$version->getKey(),
            );
        }

        if (! $owner instanceof Regulation) {
            return new self(
                kind: 'document',
                title: (string) $version->name,
                context: null,
                url: null,
            );
        }

        return new self(
            kind: $owner->kind->value,
            title: (string) $version->name,
            context: $owner->title,
            url: $owner->path().'/versions/'.$version->getKey(),
        );
    }
}
