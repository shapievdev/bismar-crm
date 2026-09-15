<?php

declare(strict_types=1);

namespace App\Data\User;

final readonly class NewUserData
{
    public function __construct(
        public string $lastName,
        public string $firstName,
        public ?string $middleName,
        /** Способ связи, а не логин: адрес есть не у каждого. */
        public ?string $email,
        /** Логин: с него сотрудник входит, поэтому обязателен. */
        public string $phone,
        public ?string $jobTitle,
        /**
         * День приёма.
         *
         * Спрашивается сразу при заведении, хотя и необязателен: заполненная
         * потом, задним числом, она не заполняется почти никогда — а без неё
         * человек не попадает ни в стаж, ни в текучесть, ни в онбординг.
         */
        public ?string $hiredAt,
        public ?string $workMode,
        public string $password,
    ) {}

    /**
     * @param  array{last_name: string, first_name: string, middle_name?: string|null, email?: string|null, phone: string, job_title?: string|null, hired_at?: string|null, work_mode?: string|null, password: string}  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            lastName: $validated['last_name'],
            firstName: $validated['first_name'],
            middleName: $validated['middle_name'] ?? null,
            email: $validated['email'] ?? null,
            phone: $validated['phone'],
            jobTitle: $validated['job_title'] ?? null,
            hiredAt: $validated['hired_at'] ?? null,
            workMode: $validated['work_mode'] ?? null,
            password: $validated['password'],
        );
    }
}
