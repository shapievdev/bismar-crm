<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\OnboardingTemplateStatus;
use App\Enums\ScheduleAnchor;
use App\Models\OnboardingJourney;
use App\Models\OnboardingTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Assign once on employee creation; existing personal stages always take priority. */
final readonly class AutoAssignTemplate
{
    public function __construct(private ApplyTemplate $apply, private OpenJourney $open) {}

    public function handle(User $employee, User $actor): ?OnboardingJourney
    {
        $title = $this->normalize($employee->job_title);
        if ($title === '' || $employee->isDismissed()) {
            return null;
        }

        return DB::transaction(function () use ($employee, $actor, $title): ?OnboardingJourney {
            $locked = User::query()->lockForUpdate()->findOrFail($employee->getKey());
            if ($locked->isDismissed() || $this->normalize($locked->job_title) !== $title) {
                return null;
            }
            $journey = OnboardingJourney::query()->where('user_id', $locked->getKey())->lockForUpdate()->first();
            if ($journey !== null && ! $journey->isEmpty()) {
                return null;
            }

            // A newer published version wins; drafts, archived and empty templates never do.
            $template = OnboardingTemplate::query()
                ->where('status', OnboardingTemplateStatus::Published)
                ->whereNotNull('job_title')->whereHas('stages')->latest('id')->get()
                ->first(fn (OnboardingTemplate $template): bool => $this->normalize($template->job_title) === $title);
            if ($template === null) {
                return null;
            }

            $journey ??= $this->open->handle($locked);

            return $this->apply->handle($journey, $template, [
                'anchor' => ($locked->hired_at === null ? ScheduleAnchor::Today : ScheduleAnchor::Hired)->value,
            ], $actor);
        });
    }

    private function normalize(?string $title): string
    {
        return mb_strtolower(Str::squish($title ?? ''));
    }
}
