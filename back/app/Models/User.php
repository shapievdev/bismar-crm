<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AccessLevel;
use App\Support\Authorization;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['last_name', 'first_name', 'middle_name', 'email', 'password', 'avatar_path'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Pins permission lookups to a single guard. Without this, spatie resolves
     * the guard from `auth.defaults.guard`, which `auth:sanctum` rewrites to
     * "sanctum" mid-request — and every permission check would miss.
     *
     * @var string
     */
    protected $guard_name = Authorization::GUARD;

    /**
     * What this person is: superadmin, administrator, or an ordinary user.
     *
     * Stored as a spatie role because Gate::before already reads roles, but it
     * is a standing rather than a job title — there are only these three.
     */
    public function accessLevel(): AccessLevel
    {
        foreach (AccessLevel::stored() as $level) {
            if ($this->hasRole($level->value)) {
                return $level;
            }
        }

        return AccessLevel::User;
    }

    /**
     * The full name, in the official Фамилия Имя Отчество order.
     *
     * Derived rather than stored: the three parts are the record, and a column
     * holding the joined form could only ever drift out of step with them.
     * It stays called `name` because that is what every screen shows and what
     * the API has always returned.
     *
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn (): string => implode(' ', array_filter(
            [$this->last_name, $this->first_name, $this->middle_name],
            static fn (?string $part): bool => $part !== null && $part !== '',
        )));
    }

    /**
     * Имя с инициалами: «Давлет К. И.».
     *
     * Полное «Курабанов Давлет Избуллаевич» в заголовке переписки не помещается
     * и обрезается многоточием на середине фамилии. В разговоре обращаются по
     * имени, поэтому оно идёт первым, а фамилия с отчеством сжимаются в буквы —
     * их дело отличить одного Давлета от другого.
     *
     * Собирается из частей записи, а не разбором готовой строки: разбор гадал
     * бы, где кончается двойная фамилия.
     *
     * @return Attribute<string, never>
     */
    protected function shortName(): Attribute
    {
        return Attribute::get(function (): string {
            $initials = array_map(
                static fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)).'.',
                array_filter(
                    [$this->last_name, $this->middle_name],
                    static fn (?string $part): bool => $part !== null && $part !== '',
                ),
            );

            // Без имени остаётся то, что есть: у записи может не быть ничего,
            // кроме фамилии, и «К. И.» лучше пустоты.
            $first = $this->first_name;

            if ($first === null || $first === '') {
                return $initials === [] ? $this->name : implode(' ', $initials);
            }

            return trim($first.' '.implode(' ', $initials));
        });
    }

    /**
     * Приватные курсы, в которые этого человека впустили.
     *
     * Своих курсов здесь нет: доступ автора следует из авторства, а не из
     * списка, — потому и снять его нельзя.
     *
     * @return BelongsToMany<Course, $this>
     */
    public function admittedCourses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_members')->withTimestamps();
    }

    /**
     * Переписки, в которых человек состоит.
     *
     * @return BelongsToMany<Conversation, $this>
     */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->using(ConversationParticipant::class)
            ->withPivot(['last_read_at', 'left_at'])
            ->withTimestamps();
    }

    /**
     * Курсы, за которые этот человек отвечает.
     *
     * @return BelongsToMany<Course, $this>
     */
    public function expertCourses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_experts')->withTimestamps();
    }

    /**
     * План обучения: что этому человеку назначили пройти и в каком порядке.
     *
     * @return HasMany<LearningPlanItem, $this>
     */
    public function planItems(): HasMany
    {
        return $this->hasMany(LearningPlanItem::class)->inOrder();
    }

    /**
     * Люди, подходящие под строку поиска, — по имени или почте.
     *
     * Сверяется с ICU: базы собраны с C-сортировкой, где lower() и ILIKE
     * складывают только латиницу, так что «иванов» иначе не нашёл бы Иванова.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeMatching(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        $query->where(function (Builder $query) use ($pattern): void {
            foreach (['last_name', 'first_name', 'middle_name', 'email'] as $column) {
                $query->orWhereRaw(sprintf('%s COLLATE "und-x-icu" ILIKE ?', $column), [$pattern]);
            }
        });
    }

    /**
     * A short-lived signed URL for the avatar, or null when none is set.
     * Generated locally, so it is cheap enough for a list of users.
     */
    public function avatarUrl(): ?string
    {
        if ($this->avatar_path === null) {
            return null;
        }

        return Storage::disk('s3')->temporaryUrl(
            $this->avatar_path,
            now()->addMinutes(config('lms.attachment_url_ttl_minutes')),
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
