<?php

declare(strict_types=1);

namespace App\Support\Ai;

/**
 * Человек, к которому консультант отправляет с вопросом, оставшимся без ответа.
 *
 * Не источник: цитировать его нельзя, и в промпт он не идёт вовсе. Модель не
 * должна знать имён — назвав человека, она назовёт и того, кого выдумала, а
 * сотрудник напишет по выдуманному адресу. Имена приставляет приложение, из
 * списка ответственных за те курсы, материал которых оказался ближе всего.
 */
final readonly class CourseExpert
{
    public function __construct(
        public int $userId,
        public string $name,
        public string $email,
        public ?string $avatarUrl,
        public string $courseTitle,
        public string $courseSlug,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatarUrl,
            'course_title' => $this->courseTitle,
            'course_slug' => $this->courseSlug,
        ];
    }
}
