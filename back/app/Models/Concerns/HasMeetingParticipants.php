<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait HasMeetingParticipants
{
    /** Legacy single-person meetings work without rewriting their history. */
    public function participantIds(): array
    {
        return array_values(array_unique(array_filter(array_map(intval(...), [
            $this->with_user_id, ...($this->participant_ids ?? []),
        ]))));
    }

    public function participantPeople(): array
    {
        $people = User::query()->whereIn('id', $this->participantIds())->get()->keyBy('id');

        return collect($this->participantIds())->map(fn (int $id) => $people->get($id))
            ->filter()->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'job_title' => $user->job_title])->values()->all();
    }

    public function scopeForParticipant(Builder $query, int $id): void
    {
        $query->where(fn (Builder $query) => $query->where('with_user_id', $id)->orWhereJsonContains('participant_ids', $id));
    }
}
