<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\User\RevokeOAuthTokens;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ExitSurveyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $survey = $user->pendingExitSurvey();
        abort_unless($survey, 403, 'Нет анкеты, ожидающей заполнения.');

        return response()->json(['data' => [
            'id' => $survey->id,
            'definition' => $survey->definition,
            'prefill' => [
                'full_name' => $survey->employee['name'],
                'department' => in_array($survey->employee['department'], collect($survey->definition['fields'])->firstWhere('id', 'department')['options'], true) ? $survey->employee['department'] : '',
                'job_title' => $survey->employee['job_title'] ?? '',
                'tenure' => $user->hired_at ? (int) $user->hired_at->diffInMonths($survey->dismissed_at).' мес.' : '',
                'dismissed_on' => $survey->dismissed_at->toDateString(),
                'reason' => $user->dismissal_reason?->label() ?? '',
            ],
        ]])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, RevokeOAuthTokens $revoke): JsonResponse
    {
        DB::transaction(function () use ($request, $revoke): void {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->getKey());
            $survey = $user->pendingExitSurvey();
            abort_unless($survey, 409, 'Анкета уже отправлена или больше не доступна.');
            $request->validate(['assignment_id' => ['required', 'integer', Rule::in([$survey->id])], 'answers' => ['required', 'array']]);

            $rules = [];
            $labels = [];
            foreach ($survey->definition['fields'] as $field) {
                $key = 'answers.'.$field['id'];
                $rules[$key] = [$field['required'] ? 'required' : 'nullable', 'string', 'max:5000'];
                if ($field['type'] === 'date') {
                    $rules[$key][] = 'date_format:Y-m-d';
                    $rules[$key][] = 'before_or_equal:today';
                }
                if (isset($field['options'])) {
                    $rules[$key][] = Rule::in([...$field['options'], ...(! empty($field['other']) ? ['other'] : [])]);
                }
                if (! empty($field['other'])) {
                    $otherKey = $key.'_other';
                    $rules[$otherKey] = [Rule::requiredIf($request->input($key) === 'other'), 'nullable', 'string', 'max:5000'];
                    $labels[$otherKey] = '«Другое» — '.$field['label'];
                }
                $labels[$key] = $field['label'];
            }
            $validated = Validator::make($request->all(), $rules, [], $labels)->validate();
            $answers = $validated['answers'];
            // Не храним скрытый текст «другое», если человек сменил вариант ответа.
            foreach ($survey->definition['fields'] as $field) {
                if (($answers[$field['id']] ?? null) !== 'other') {
                    unset($answers[$field['id'].'_other']);
                }
            }
            $survey->forceFill(['answers' => $answers, 'submitted_at' => now()])->save();
            $user->forceFill(['remember_token' => Str::random(60)])->save();
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
            }
            $revoke->handle($user);
        });

        // Сначала надёжно сохраняем ответы; только затем завершаем текущую сессию.
        Auth::guard('web')->logout();
        // Sanctum кэширует пользователя текущего запроса. Очищаем и его,
        // чтобы запись новой гостевой сессии не сохранила user_id уволенного.
        Auth::guard('sanctum')->forgetUser();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Спасибо за ответы. Доступ к платформе закрыт.']);
    }
}
