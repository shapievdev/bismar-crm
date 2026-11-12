<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Lms\AcknowledgeRegulation;
use App\Actions\Lms\CompleteLesson;
use App\Actions\Lms\EnrollLearner;
use App\Actions\Lms\SaveCourse;
use App\Actions\Lms\SaveQuiz;
use App\Actions\Lms\SaveRegulation;
use App\Actions\Lms\SyncCourseAccess;
use App\Actions\Lms\SyncRegulationPeople;
use App\Actions\Onboarding\ApplyTemplate;
use App\Actions\Onboarding\ChangeTemplateStatus;
use App\Actions\Onboarding\ConfirmGraduation;
use App\Actions\Onboarding\ConfirmMeeting;
use App\Actions\Onboarding\ConfirmTask;
use App\Actions\Onboarding\DuplicateTemplate;
use App\Actions\Onboarding\MoveStageOpening;
use App\Actions\Onboarding\OpenJourney;
use App\Actions\Onboarding\PauseJourney;
use App\Actions\Onboarding\RefreshJourneyProgress;
use App\Actions\Onboarding\ResumeJourney;
use App\Actions\Onboarding\SaveStage;
use App\Actions\Onboarding\SaveTemplate;
use App\Actions\Onboarding\ScheduleMeeting;
use App\Actions\Onboarding\SubmitStageQuiz;
use App\Actions\Onboarding\SubmitTask;
use App\Actions\User\CreateUser;
use App\Data\User\NewUserData;
use App\Enums\MaterialKind;
use App\Enums\OnboardingTemplateStatus;
use App\Exceptions\ConflictException;
use App\Jobs\SendPush;
use App\Models\Achievement;
use App\Models\Department;
use App\Models\OnboardingJourney;
use App\Models\OnboardingJourneyStage;
use App\Models\OnboardingTemplate;
use App\Models\User;
use App\Support\Analytics\OnboardingReport;
use App\Support\Onboarding\JourneyPresenter;
use App\Support\Onboarding\RouteSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/** Локальная демонстрация через бизнес-операции; реальные доставки перехватываются. */
final class DemonstrateOnboarding extends Command
{
    protected $signature = 'onboarding:demo {--batch=example : Имя набора примеров}';

    protected $description = 'Создать демонстрационную программу, пройти её и сохранить доказательства';

    private array $evidence = [];

    private array $snapshots = [];

    private array $users = [];

    private array $materials = [];

    private array $badges = [];

    private User $actor;

    private string $directory;

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Демонстрация разрешена только в local/testing.');

            return self::FAILURE;
        }
        $batch = (string) $this->option('batch');
        if (! preg_match('/^[a-z0-9-]{1,40}$/', $batch)) {
            $this->error('Имя набора: латинские буквы, цифры и дефис.');

            return self::FAILURE;
        }
        $this->directory = storage_path('app/onboarding-demo/'.$batch);
        $manifest = $this->directory.'/evidence.json';
        if (File::exists($manifest)) {
            $this->line($manifest);
            $this->info('Демонстрация уже существует: исходные снимки сохранены, данные не перезаписаны.');

            return self::SUCCESS;
        }
        if (User::where('email', 'onboarding-demo-'.$this->option('batch').'-graduate@example.test')->exists()) {
            $this->error('Найдены прежние демоданные без отчёта. Не перезаписываю их.');

            return self::FAILURE;
        }
        File::ensureDirectoryExists($this->directory);
        Queue::fake();
        config(['scout.driver' => null]);
        try {
            DB::transaction(function (): void {
                $this->at('2026-11-02 09:00');
                $this->createPeople();
                $this->createMaterials();
                $template = $this->createProgram();
                $graduate = $this->apply($this->users['graduate'], $template, '2026-11-02');
                $this->simulateGraduate($graduate);
                $this->simulateInProgress($template);
                $this->versionProof($template, $graduate);
                $this->at('2026-11-12 12:00');
                Auth::login($this->users['hr']);
                $this->evidence['report'] = app(OnboardingReport::class)->build(['template_id' => $template->id, 'store' => 'ДЕМО · Учебный магазин']);
                $this->evidence['urls'] = [
                    'graduate' => 'http://localhost:3100/onboarding/manage/people/'.$this->users['graduate']->id,
                    'in_progress' => 'http://localhost:3100/onboarding/manage/people/'.$this->users['progress']->id,
                    'template' => 'http://localhost:3100/onboarding/manage/templates/'.$template->slug,
                    'report' => 'http://localhost:3100/onboarding/manage/report',
                    'graduate_staff' => 'http://localhost:3100/staff/'.$this->users['graduate']->id,
                ];
                $this->evidence['users'] = collect($this->users)->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'job_title' => $user->job_title])->all();
                $this->evidence['materials'] = collect($this->materials)->map(fn ($item) => ['id' => $item->id, 'title' => $item->title, 'slug' => $item->slug])->all();
                $this->evidence['snapshots'] = $this->snapshots;
                foreach ($this->evidence['checks'] as $check) {
                    if (! $check['passed']) {
                        throw new \RuntimeException('Проверка не прошла: '.$check['name']);
                    }
                }
                $this->evidence['simulation'] = true;
                $this->evidence['push_delivery'] = 'Перехвачено Queue::fake: внешней доставки нет';
                $this->evidence['generated_at'] = '2026-11-12T12:00:00+03:00';
            });
            File::put($manifest, json_encode($this->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->line($manifest);
            $this->info('Созданы программа, два сотрудника-стажёра, HR, наставник и снимки прохождения.');

            return self::SUCCESS;
        } finally {
            Date::setTestNow();
            Auth::logout();
        }
    }

    private function at(string $moscowTime): void
    {
        Date::setTestNow(CarbonImmutable::parse($moscowTime, 'Europe/Moscow')->utc());
    }

    private function as(User $user): void
    {
        $this->actor = $user;
        Auth::login($user);
    }

    private function createPeople(): void
    {
        $people = [
            'hr' => ['Ольга', 'Обучение', 'Менеджер по обучению (демо)', 'onboarding-demo-'.$this->option('batch').'-hr@example.test'],
            'mentor' => ['Ирина', 'Наставник', 'Наставник продавцов (демо)', 'onboarding-demo-'.$this->option('batch').'-mentor@example.test'],
            'graduate' => ['Антон', 'Выпускник', 'Продавец-консультант (демо)', 'onboarding-demo-'.$this->option('batch').'-graduate@example.test'],
            'progress' => ['Мария', 'Стажёр', 'Продавец-консультант (демо)', 'onboarding-demo-'.$this->option('batch').'-progress@example.test'],
        ];
        foreach (array_values(array_keys($people)) as $index => $key) {
            [$first, $middle, $job, $email] = $people[$key];
            $phone = '+79990009'.str_pad((string) ($index + 101 + (abs(crc32((string) $this->option('batch'))) % 150) * 4), 3, '0', STR_PAD_LEFT);
            if (User::where('phone', $phone)->exists()) {
                throw new \RuntimeException('Демонстрационный телефон уже занят: '.$phone);
            }
            $this->users[$key] = app(CreateUser::class)->handle(new NewUserData('Демо', $first, $middle, $email, $phone, $job, '2026-11-02', null, Str::password(32)));
            $this->users[$key]->givePermissionTo(['courses.view', 'documents.view', 'handbooks.view']);
        }
        $this->users['hr']->givePermissionTo(['onboarding.manage', 'achievements.manage', 'users.view']);
        foreach (['graduate', 'progress'] as $key) {
            $this->users[$key]->forceFill(['mentor_id' => $this->users['mentor']->id])->save();
        }
        $department = Department::create(['name' => 'ДЕМО · Учебный магазин', 'parent_id' => Department::whereNull('parent_id')->value('id'), 'position' => 999]);
        foreach ($this->users as $user) {
            $department->people()->attach($user->id, ['role' => 'member']);
        }
        $this->evidence['department_id'] = $department->id;
        $this->as($this->users['hr']);
    }

    private function text(array $paragraphs): array
    {
        return ['type' => 'doc', 'content' => array_map(fn ($text) => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]], $paragraphs)];
    }

    private function createMaterials(): void
    {
        $texts = [
            'company' => [MaterialKind::Document, 'ДЕМО · Компания и команда', ['Учебный пример, не официальный регламент BISMAR.', 'Цель программы — научиться помогать покупателю и обращаться к нужному специалисту.', 'Наставник помогает с практикой, менеджер по обучению отвечает за программу, менеджер по адаптации — за организационные вопросы.', 'Задание: познакомьтесь с наставником и назовите, к кому идти с вопросом о товаре и с вопросом о расписании.']],
            'products' => [MaterialKind::Handbook, 'ДЕМО · Как уточнять запрос покупателя', ['Учебный пример консультации.', 'Сначала уточните задачу, материал основания и условия применения.', 'Проверьте маркировку и инструкцию производителя. Не обещайте свойства, которые не подтверждены документацией.', 'Если данных не хватает, привлеките наставника. Качество консультации важнее скорости ответа.']],
        ];
        foreach ($texts as $key => [$kind, $title, $paragraphs]) {
            $material = app(SaveRegulation::class)->handle(['title' => $title, 'summary' => 'Демонстрационный материал для проверки адаптации', 'content_json' => $this->text($paragraphs), 'status' => 'published', 'visibility' => 'private', 'kind' => $kind], $this->users['hr']);
            app(SyncRegulationPeople::class)->admit($material, collect($this->users)->pluck('id')->all(), [], [], $this->users['hr']);
            $this->materials[$key] = $material;
        }
        foreach (['service' => ['ДЕМО · Стандарты сервиса', ['Поздоровайтесь и предложите помощь без давления.', 'Уточните задачу открытым вопросом, выслушайте ответ и согласуйте следующий шаг.']], 'practice' => ['ДЕМО · Практикум консультации', ['Разыграйте консультацию с наставником.', 'Уточните потребность, найдите сведения в справочнике, объясните выбор и проверьте, остались ли вопросы.']]] as $key => [$title, $paragraphs]) {
            $course = app(SaveCourse::class)->create(['title' => $title, 'description' => 'Демонстрационный учебный курс', 'status' => 'published', 'visibility' => 'private'], $this->users['hr']);
            app(SyncCourseAccess::class)->handle($course, collect($this->users)->pluck('id')->all(), [], [], $this->users['hr']);
            $module = $course->modules()->create(['title' => 'Практика', 'position' => 1]);
            $module->lessons()->create(['title' => $title, 'slug' => 'demo-lesson', 'content_json' => $this->text($paragraphs), 'position' => 1, 'duration_minutes' => 10, 'published_at' => now()]);
            $this->materials[$key] = $course;
        }
        foreach (['first' => 'Первый шаг сделан!', 'company' => 'Знаю компанию', 'almost' => 'Осталось немного!', 'exam' => 'Экзамен сдан', 'equipped' => 'Экипирован и готов к работе!'] as $key => $title) {
            $this->badges[$key] = Achievement::firstOrCreate(['title' => 'ДЕМО · '.$title], ['description' => 'За реальные действия в демонстрационном маршруте', 'created_by' => $this->users['hr']->id]);
        }
    }

    private function createProgram(): OnboardingTemplate
    {
        $template = app(SaveTemplate::class)->handle(null, ['title' => 'ДЕМО · Продавец-консультант · 7 рабочих дней', 'description' => 'Демонстрация ТЗ: материалы, практика, встречи, экзамен, допуск и экипировка.', 'job_title' => 'Продавец-консультант', 'store' => 'ДЕМО · Учебный магазин', 'duration_days' => 7, 'day_mode' => 'working', 'allow_ahead' => false, 'meeting_reminder_minutes' => 30, 'requires_graduation' => true, 'graduation_achievement_id' => $this->badges['equipped']->id, 'settings' => RouteSettings::defaults()], $this->users['hr']);
        $titles = ['Компания и команда', 'Сервис и торговый зал', 'Обучение с менеджером', 'Встреча по адаптации', 'Товар и запрос покупателя', 'Самостоятельная практика', 'Итоговый экзамен'];
        $tasks = ['Назвать наставника и ответственных за обучение', 'Провести приветствие и выяснить потребность покупателя', 'Разобрать учебную ситуацию с менеджером', 'Согласовать личную цель на следующую неделю', 'Уточнить запрос и найти сведения в справочнике', 'Провести учебную консультацию под наблюдением наставника', 'Повторить материалы перед итоговым тестом'];
        foreach ($titles as $index => $title) {
            $day = $index + 1;
            $key = [1 => 'company', 2 => 'service', 5 => 'products', 6 => 'practice'][$day] ?? null;
            $links = $key ? [['type' => in_array($key, ['service', 'practice']) ? 'course' : 'regulation', 'id' => $this->materials[$key]->id, 'is_required' => true, 'day_offset' => $day, 'achievement_id' => $day === 1 ? $this->badges['company']->id : null]] : [];
            $meetings = in_array($day, [3, 4]) ? [['topic' => $day === 3 ? 'Разбор консультации с менеджером' : 'Обратная связь по адаптации', 'format' => $day === 3 ? 'offline' : 'online', 'place' => $day === 3 ? 'ДЕМО · Учебный зал' : 'https://example.org/demo-meeting', 'with_user_id' => $this->users['hr']->id]] : [];
            $stage = app(SaveStage::class)->handle($template, ['title' => $title, 'description' => 'День '.$day.'. '.$tasks[$index].'. Все действия — учебная имитация.', 'position' => $day, 'day_offset' => $day, 'is_final_exam' => $day === 7, 'responsible_ids' => [$this->users['mentor']->id, $this->users['hr']->id], 'achievement_id' => match ($day) {
                1 => $this->badges['first']->id, 6 => $this->badges['almost']->id, default => null
            }, 'quiz_achievement_id' => $day === 7 ? $this->badges['exam']->id : null, 'links' => $links, 'meetings' => $meetings, 'tasks' => [['text' => $tasks[$index], 'confirm_by' => in_array($day, [2, 3, 5, 6]) ? 'responsible' : 'self', 'is_required' => true]]]);
            if (in_array($day, [3, 7])) {
                $questions = [
                    ['Что сделать до рекомендации товара?', 'Уточнить задачу покупателя', 'Сразу предложить самый дорогой товар'],
                    ['Что делать, если не хватает подтверждённых сведений?', 'Обратиться к наставнику и документации', 'Придумать уверенный ответ'],
                    ['Кто подтверждает проведение встречи?', 'Назначенный ответственный', 'Стажёр сам себе'],
                    ['Что означает перенос встречи?', 'Согласование новой даты', 'Провал этапа стажёром'],
                    ['Когда оформляется допуск к продажам?', 'После обучения и успешного экзамена', 'Сразу после открытия страницы'],
                ];
                app(SaveQuiz::class)->handle($stage, ['title' => $day === 7 ? 'ДЕМО · Готовность к работе' : 'ДЕМО · Проверка основ', 'passing_score' => $day === 7 ? 80 : 100, 'max_attempts' => 2, 'questions' => array_map(fn ($q) => ['text' => $q[0], 'type' => 'single', 'points' => 1, 'options' => [['text' => $q[1], 'is_correct' => true], ['text' => $q[2], 'is_correct' => false]]], $day === 7 ? $questions : array_slice($questions, 0, 2))]);
            }
        }
        app(ChangeTemplateStatus::class)->handle($template, OnboardingTemplateStatus::Published);
        $this->evidence['template'] = ['id' => $template->id, 'slug' => $template->slug, 'version' => 1, 'days' => 7, 'calendar' => 'working'];

        return $template;
    }

    private function apply(User $employee, OnboardingTemplate $template, string $date): OnboardingJourney
    {
        $this->as($this->users['hr']);
        $journey = app(OpenJourney::class)->handle($employee);
        app(ApplyTemplate::class)->handle($journey, $template, ['anchor' => 'date', 'date' => $date], $this->users['hr']);

        return $journey->refresh();
    }

    private function snapshot(string $label, OnboardingJourney $journey): void
    {
        $journey->refresh();
        app(RefreshJourneyProgress::class)->handle($journey);
        $payload = app(JourneyPresenter::class)->present($journey->refresh());
        $this->snapshots[] = ['label' => $label, 'at' => now()->toIso8601String(), 'employee' => $journey->user_id, 'journey' => $payload];
    }

    private function materialDone(string $key, User $employee): void
    {
        $this->as($employee);
        $material = $this->materials[$key];
        if (in_array($key, ['company', 'products'])) {
            app(AcknowledgeRegulation::class)->handle($material, $employee);
        } else {
            $enrollment = app(EnrollLearner::class)->handle($material, $employee);
            foreach ($material->lessons()->get() as $lesson) {
                app(CompleteLesson::class)->handle($enrollment, $lesson);
            }
        }
    }

    private function taskDone(OnboardingJourneyStage $stage, User $employee): void
    {
        foreach ($stage->tasks()->get() as $task) {
            $this->as($employee);
            app(SubmitTask::class)->handle($task, $employee);
            if (! $task->refresh()->isDone()) {
                $this->as($this->users['mentor']);
                app(ConfirmTask::class)->handle($task, $this->users['mentor']);
            }
        }
    }

    private function answers(OnboardingJourneyStage $stage, int $correct): array
    {
        $answers = [];
        foreach ($stage->quiz()->with('questions.options')->firstOrFail()->questions as $index => $question) {
            $answers[$question->id] = [$question->options->firstWhere('is_correct', $index < $correct)->id];
        }

        return $answers;
    }

    private function blocked(string $name, callable $operation): void
    {
        try {
            $operation();
        } catch (ConflictException $failure) {
            $this->evidence['checks'][] = ['name' => $name, 'passed' => true, 'message' => $failure->getMessage()];

            return;
        }
        throw new \RuntimeException('Ожидаемый запрет не сработал: '.$name);
    }

    private function simulateGraduate(OnboardingJourney $journey): void
    {
        $employee = $this->users['graduate'];
        $dates = ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-09', '2026-11-10'];
        $this->snapshot('Назначение: первый этап доступен, остальные закрыты', $journey);
        $this->blocked('Допуск до завершения обучения запрещён', fn () => app(ConfirmGraduation::class)->handle($journey, 'access', $this->users['hr']));
        foreach ($journey->stages()->get() as $index => $stage) {
            $day = $index + 1;
            $this->at($dates[$index].' 09:00');
            $this->as($this->users['hr']);
            $this->notify($journey, 'Открытие дня '.$day);
            $key = [1 => 'company', 2 => 'service', 5 => 'products', 6 => 'practice'][$day] ?? null;
            if ($key) {
                $this->materialDone($key, $employee);
            }
            if ($day === 1) {
                $this->snapshot('День 1: материал прочитан, этап в процессе', $journey);
            }
            $this->taskDone($stage, $employee);
            foreach ($stage->meetings()->get() as $meeting) {
                $this->as($this->users['hr']);
                app(ScheduleMeeting::class)->handle($meeting, ['scheduled_at' => $dates[$index].'T12:00:00+03:00']);
                if ($day === 4) {
                    app(ScheduleMeeting::class)->handle($meeting->refresh(), ['scheduled_at' => $dates[$index].'T15:00:00+03:00']);
                    $this->evidence['checks'][] = ['name' => 'Перенос встречи не является неудачей', 'passed' => $meeting->refresh()->reschedules_count === 1];
                }
                $hour = $day === 4 ? '14:30' : '11:30';
                $this->at($dates[$index].' '.$hour);
                $this->notify($journey, 'За 30 минут до встречи', true);
                $this->at($dates[$index].($day === 4 ? ' 15:30' : ' 12:30'));
                app(ConfirmMeeting::class)->handle($meeting->refresh(), $this->users['hr']);
            }
            if (in_array($day, [3, 7])) {
                $this->as($employee);
                if ($day === 7) {
                    app(SubmitStageQuiz::class)->handle($stage->fresh(), $this->answers($stage, 0));
                    $this->snapshot('Экзамен: первая попытка 0%, доступна пересдача', $journey);
                }
                app(SubmitStageQuiz::class)->handle($stage->fresh(), $this->answers($stage, $day === 7 ? 4 : 2));
            }
            $this->at($dates[$index].' 17:00');
            $this->snapshot('День '.$day.' выполнен', $journey);
        }
        $this->snapshot('Обучение 100%, выпуск ещё не подтверждён', $journey);
        $this->as($this->users['hr']);
        $this->blocked('Футболка до допуска запрещена', fn () => app(ConfirmGraduation::class)->handle($journey, 'tshirt', $this->users['hr']));
        foreach (['access' => 'Допуск подтверждён', 'tshirt' => 'Футболка выдана', 'barcode' => 'Штрихкод выдан: маршрут завершён'] as $what => $label) {
            $journey = app(ConfirmGraduation::class)->handle($journey->fresh(), $what, $this->users['hr']);
            $this->snapshot($label, $journey);
        }
        $this->evidence['graduate_final'] = app(JourneyPresenter::class)->present($journey->fresh());
        $before = Queue::pushed(SendPush::class)->count();
        $this->notify($journey, 'После завершения: уведомлений нет');
        $this->evidence['checks'][] = ['name' => 'После завершения напоминаний нет', 'passed' => Queue::pushed(SendPush::class)->count() === $before];
    }

    private function notify(OnboardingJourney $journey, string $label, bool $meeting = false): void
    {
        $before = Queue::pushed(SendPush::class)->count();
        Artisan::call($meeting ? 'onboarding:remind-meetings' : 'onboarding:remind', ['--employee' => $journey->user_id]);
        $jobs = Queue::pushed(SendPush::class)->slice($before)->values();
        $messages = $jobs->map(fn ($job) => (fn () => ['recipients' => $this->userIds, 'message' => $this->message->toArray()])->call($job))->all();
        $this->evidence['notifications'][] = ['label' => $label, 'at' => now()->toIso8601String(), 'captured' => $messages];
    }

    private function simulateInProgress(OnboardingTemplate $template): void
    {
        $this->at('2026-11-09 09:00');
        $employee = $this->users['progress'];
        $journey = $this->apply($employee, $template, '2026-11-09');
        $journey->fill(['allow_ahead' => true])->save();
        $stages = $journey->stages()->get();
        foreach ([0 => 'company', 1 => 'service'] as $index => $material) {
            $this->at('2026-11-'.($index === 0 ? '09' : '10').' 14:00');
            $this->materialDone($material, $employee);
            $this->taskDone($stages[$index], $employee);
        }
        $this->at('2026-11-11 15:00');
        $stages[2]->fill(['due_at' => '2026-11-11 15:00:00'])->save();
        $this->taskDone($stages[2], $employee);
        $this->as($this->users['hr']);
        $meeting = $stages[2]->meetings()->first();
        app(ScheduleMeeting::class)->handle($meeting, ['scheduled_at' => '2026-11-11T15:00:00+03:00']);
        app(ConfirmMeeting::class)->handle($meeting, $this->users['hr']);
        $this->as($employee);
        app(SubmitStageQuiz::class)->handle($stages[2]->fresh(), $this->answers($stages[2], 0));
        $this->at('2026-11-12 12:00');
        $this->taskDone($stages[3], $employee);
        $this->as($this->users['hr']);
        app(MoveStageOpening::class)->handle($stages[5], null);
        $this->snapshot('Мария: пять статусов одновременно на живом маршруте', $journey);
        $this->notify($journey, 'Повторный запуск без дубликатов');
        $before = Queue::pushed(SendPush::class)->count();
        $this->notify($journey, 'Повторный запуск');
        $this->evidence['checks'][] = ['name' => 'Повторный запуск не дублирует уведомления', 'passed' => Queue::pushed(SendPush::class)->count() === $before];
        app(PauseJourney::class)->handle($journey->fresh());
        $before = Queue::pushed(SendPush::class)->count();
        $this->notify($journey, 'Пауза: уведомлений нет');
        $this->evidence['checks'][] = ['name' => 'На паузе напоминаний нет', 'passed' => Queue::pushed(SendPush::class)->count() === $before];
        app(ResumeJourney::class)->handle($journey->fresh());
        $this->evidence['in_progress_final'] = app(JourneyPresenter::class)->present($journey->fresh());
    }

    private function versionProof(OnboardingTemplate $template, OnboardingJourney $graduate): void
    {
        $this->as($this->users['hr']);
        $before = $graduate->stages()->first()->title;
        $version = app(DuplicateTemplate::class)->handle($template, $this->users['hr']);
        $version->stages()->first()->update(['title' => 'Компания и команда · обновлённая программа v2']);
        $this->evidence['version'] = ['id' => $version->id, 'slug' => $version->slug, 'version' => $version->version, 'parent_id' => $version->parent_id, 'url' => 'http://localhost:3100/onboarding/manage/templates/'.$version->slug];
        $this->evidence['checks'][] = ['name' => 'Новая версия не меняет уже назначенное', 'passed' => $graduate->stages()->first()->title === $before];
    }
}
