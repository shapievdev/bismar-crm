<?php

declare(strict_types=1);

namespace App\Data\Auth;

final readonly class LoginData
{
    public function __construct(
        public string $email,
        public string $password,
        public bool $remember = false,
    ) {}

    /**
     * @param  array{email: string, password: string, remember?: bool}  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            email: $validated['email'],
            password: $validated['password'],
            remember: $validated['remember'] ?? false,
        );
    }

    /**
     * Credentials in the shape expected by the authentication guard.
     *
     * @return array{email: string, password: string}
     */
    public function credentials(): array
    {
        return [
            'email' => $this->email,
            'password' => $this->password,
        ];
    }
}
