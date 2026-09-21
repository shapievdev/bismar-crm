<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SurveyQuestionType;
use App\Models\Lesson;
use App\Models\News;
use App\Models\Regulation;
use App\Models\RegulationVersion;
use App\Models\Survey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Survey>
 */
final class SurveyFactory extends Factory
{
    protected $model = Survey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Владелец по умолчанию — урок; прочих задают явно, как и у теста.
            'surveyable_type' => 'lesson',
            'surveyable_id' => Lesson::factory(),
            'title' => 'Что скажете об уроке?',
            'description' => null,
            // По умолчанию необязательный: опрос, который держит зачёт, — это
            // решение автора, и в проверках его тоже стоит писать явно.
            'is_required' => false,
            'is_anonymous' => false,
            'closes_at' => null,
            'thanks' => null,
        ];
    }

    public function forLesson(Lesson $lesson): self
    {
        return $this->forOwner($lesson);
    }

    public function forRegulation(Regulation $regulation): self
    {
        return $this->forOwner($regulation);
    }

    public function forVersion(RegulationVersion $version): self
    {
        return $this->forOwner($version);
    }

    public function forNews(News $news): self
    {
        return $this->forOwner($news);
    }

    /** Обязательный: без него материал не зачитывается. */
    public function required(): self
    {
        return $this->state(['is_required' => true]);
    }

    /** Анонимный: ответы не связаны с человеком, отметка о прохождении есть. */
    public function anonymous(): self
    {
        return $this->state(['is_anonymous' => true]);
    }

    /** Закрытый: срок приёма ответов вышел. */
    public function closed(): self
    {
        return $this->state(['closes_at' => now()->subDay()]);
    }

    /**
     * Один вопрос с одиночным выбором и двумя вариантами — этого хватает
     * почти всякой проверке: вопрос, у которого есть что отметить.
     */
    public function withQuestion(): self
    {
        return $this->afterCreating(function (Survey $survey): void {
            $question = $survey->questions()->create([
                'text' => 'Пригодилось?',
                'type' => SurveyQuestionType::Single,
                'is_required' => true,
                'position' => 0,
            ]);

            $question->options()->create(['text' => 'Да', 'position' => 0]);
            $question->options()->create(['text' => 'Нет', 'position' => 1]);
        });
    }

    /**
     * Опрос из всех видов вопроса сразу — для проверок сводки и присланного.
     */
    public function withEveryKindOfQuestion(): self
    {
        return $this->afterCreating(function (Survey $survey): void {
            $choice = $survey->questions()->create([
                'text' => 'Что пригодилось?',
                'type' => SurveyQuestionType::Multiple,
                'is_required' => true,
                'allows_other' => true,
                'position' => 0,
            ]);

            $choice->options()->create(['text' => 'Разбор случая', 'position' => 0]);
            $choice->options()->create(['text' => 'Таблица', 'position' => 1]);

            $survey->questions()->create([
                'text' => 'Насколько понятно?',
                'type' => SurveyQuestionType::Scale,
                'is_required' => true,
                'scale_min' => 1,
                'scale_max' => 5,
                'scale_min_label' => 'Ничего не понял',
                'scale_max_label' => 'Всё ясно',
                'position' => 1,
            ]);

            $survey->questions()->create([
                'text' => 'Что улучшить?',
                'type' => SurveyQuestionType::LongText,
                'is_required' => false,
                'position' => 2,
            ]);
        });
    }

    /**
     * @param  Lesson|Regulation|RegulationVersion|News  $owner
     */
    private function forOwner($owner): self
    {
        return $this->state([
            'surveyable_type' => $owner->getMorphClass(),
            'surveyable_id' => $owner->getKey(),
        ]);
    }
}
