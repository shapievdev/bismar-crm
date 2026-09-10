<?php

declare(strict_types=1);

namespace App\Data\Auth;

final readonly class LoginData
{
    public function __construct(
        public string $phone,
        public string $password,
        public bool $remember = false,
    ) {}

    /**
     * @param  array{phone: string, password: string, remember?: bool}  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            phone: $validated['phone'],
            password: $validated['password'],
            remember: $validated['remember'] ?? false,
        );
    }

    /**
     * Credentials in the shape expected by the authentication guard.
     *
     * @return array{phone: string, password: string}
     */
    public function credentials(): array
    {
        return [
            'phone' => $this->phone,
            'password' => $this->password,
        ];
    }
}
