<?php

declare(strict_types=1);

namespace App\Data\User;

final readonly class NewUserData
{
    public function __construct(
        public string $lastName,
        public string $firstName,
        public ?string $middleName,
        public string $email,
        public ?string $phone,
        public ?string $jobTitle,
        public string $password,
    ) {}

    /**
     * @param  array{last_name: string, first_name: string, middle_name?: string|null, email: string, phone?: string|null, job_title?: string|null, password: string}  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            lastName: $validated['last_name'],
            firstName: $validated['first_name'],
            middleName: $validated['middle_name'] ?? null,
            email: $validated['email'],
            phone: $validated['phone'] ?? null,
            jobTitle: $validated['job_title'] ?? null,
            password: $validated['password'],
        );
    }
}
