<?php

declare(strict_types=1);

namespace App\Support\Onboarding;

use App\Models\OnboardingJourney;
use App\Models\OnboardingTemplate;
use App\Models\User;
use App\Support\News\LinkedMaterial;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Материалы маршрута, которых сотрудник не увидит.
 *
 * У материалов базы знаний есть доступы: приватный курс открыт своим, у
 * документов и справочников свои права на раздел, черновик не виден никому из
 * читателей. Положить такой материал в маршрут МОЖНО — «сначала положить,
 * потом впустить» обычный порядок, — но молчать об этом нельзя: этап остался
 * бы незакрываемым, а ссылка вела бы в «не найдено».
 *
 * Спрашивают об этом дважды: до наложения шаблона («что из него человеку
 * закрыто») и у готового маршрута («что закрыто сейчас»). Правило одно и то
 * же — политика `view` через LinkedMaterial::isVisibleTo, та самая, которой
 * закрыты сами страницы материалов.
 */
final readonly class HiddenMaterials
{
    /**
     * Что закрыто сотруднику в его собственном маршруте.
     *
     * @return list<array{stage_title: string, day_offset: ?int, title: string, is_required: bool}>
     */
    public function inJourney(OnboardingJourney $journey): array
    {
        $journey->loadMissing(['stages.links.linkable', 'user']);

        $employee = $journey->user;

        if ($employee === null) {
            return [];
        }

        return $this->collect($journey->stages, $employee, fn ($stage) => [
            (string) $stage->title,
            $stage->day_offset === null ? null : (int) $stage->day_offset,
        ]);
    }

    /**
     * Что из шаблона будет закрыто этому человеку — до того, как наложили.
     *
     * @return list<array{stage_title: string, day_offset: ?int, title: string, is_required: bool}>
     */
    public function inTemplate(OnboardingTemplate $template, User $employee): array
    {
        $template->loadMissing('stages.links.linkable');

        return $this->collect($template->stages, $employee, fn ($stage) => [
            (string) $stage->title,
            (int) $stage->day_offset,
        ]);
    }

    /**
     * @param  Collection<int, Model>  $stages
     * @param  callable(Model): array{0: string, 1: ?int}  $describe
     * @return list<array{stage_title: string, day_offset: ?int, title: string, is_required: bool}>
     */
    private function collect(Collection $stages, User $employee, callable $describe): array
    {
        $hidden = [];

        foreach ($stages as $stage) {
            [$title, $day] = $describe($stage);

            foreach ($stage->links as $link) {
                $material = $link->linkable;

                if ($material instanceof Model && LinkedMaterial::isVisibleTo($material, $employee)) {
                    continue;
                }

                $hidden[] = [
                    'stage_title' => $title,
                    'day_offset' => $day,
                    // Название закрытого материала ведущему показать можно: он
                    // его сам и положил в программу.
                    'title' => $material === null ? 'Материал удалён' : LinkedMaterial::title($material),
                    'is_required' => (bool) $link->is_required,
                ];
            }
        }

        return $hidden;
    }
}
