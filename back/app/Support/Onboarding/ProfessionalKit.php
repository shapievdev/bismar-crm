<?php

declare(strict_types=1);

namespace App\Support\Onboarding;

use App\Models\OnboardingJourney;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Состав снимается с шаблона; выдача принадлежит конкретному сотруднику. */
final class ProfessionalKit
{
    public const ICONS = ['access', 'shirt', 'barcode', 'badge', 'key', 'book', 'tool', 'device', 'bag', 'star'];

    public static function defaults(): array
    {
        return [
            ['id' => 'access', 'label' => 'Допуск к продажам', 'icon' => 'access', 'kind' => 'admission', 'is_required' => true],
            ['id' => 'tshirt', 'label' => 'Футболка команды', 'icon' => 'shirt', 'kind' => 'item', 'is_required' => true],
            ['id' => 'barcode', 'label' => 'Штрихкод продавца', 'icon' => 'barcode', 'kind' => 'item', 'is_required' => true],
        ];
    }

    public static function definitions(?array $items): array
    {
        return $items ?? self::defaults();
    }

    public static function rules(): array
    {
        return [
            'kit_items' => ['sometimes', 'array', 'max:30'],
            'kit_items.*' => ['array:id,label,icon,kind,is_required'],
            'kit_items.*.id' => ['required', 'string', 'distinct:strict', 'regex:/^[a-zA-Z0-9_-]{1,64}$/'],
            'kit_items.*.label' => ['required', 'string', 'max:100'],
            'kit_items.*.icon' => ['required', Rule::in(self::ICONS)],
            'kit_items.*.kind' => ['required', Rule::in(['item', 'admission'])],
            'kit_items.*.is_required' => ['required', 'boolean'],
        ];
    }

    public static function validated(array $items, bool $required): array
    {
        Validator::make(['kit_items' => $items], self::rules())->validate();
        $items = array_map(fn ($item) => [
            'id' => $item['id'], 'label' => trim($item['label']), 'icon' => $item['icon'],
            'kind' => $item['kind'], 'is_required' => (bool) $item['is_required'],
        ], array_values($items));
        if ($required && ! collect($items)->contains('is_required', true)) {
            throw ValidationException::withMessages(['kit_items' => 'Добавьте хотя бы один обязательный пункт набора или выключите обязательный выпуск.']);
        }
        $admissions = collect($items)->where('kind', 'admission');
        if ($admissions->count() > 1) {
            throw ValidationException::withMessages(['kit_items' => 'В наборе может быть только один допуск после экзамена.']);
        }
        if ($admissions->contains('is_required', false)) {
            throw ValidationException::withMessages(['kit_items' => 'Допуск после экзамена должен быть обязательным.']);
        }

        return $items;
    }

    public static function admission(?array $items): ?array
    {
        return collect(self::definitions($items))->firstWhere('kind', 'admission');
    }

    /** Старые колонки остаются достоверными фактами для прежних маршрутов. */
    public static function receipts(OnboardingJourney $journey): array
    {
        $receipts = $journey->kit_receipts ?? [];
        foreach (['access' => ['access_confirmed_at', 'access_confirmed_by'], 'tshirt' => ['tshirt_issued_at', 'tshirt_issued_by'], 'barcode' => ['barcode_issued_at', 'barcode_issued_by']] as $id => [$at, $by]) {
            if (! isset($receipts[$id]) && $journey->getAttribute($at) !== null) {
                $receipts[$id] = ['confirmed_at' => $journey->getAttribute($at)->toIso8601String(), 'confirmed_by' => $journey->getAttribute($by)];
            }
        }

        return $receipts;
    }

    public static function isComplete(OnboardingJourney $journey): bool
    {
        $items = self::definitions($journey->kit_items);
        if ($items === []) {
            return false;
        }
        $required = array_values(array_filter($items, fn ($item) => $item['is_required']));
        $receipts = self::receipts($journey);

        return collect($required ?: $items)->every(fn ($item) => isset($receipts[$item['id']]['confirmed_at']));
    }

    public static function presented(OnboardingJourney $journey): array
    {
        $receipts = self::receipts($journey);
        $people = User::withTrashed()->whereIn('id', collect($receipts)->pluck('confirmed_by')->filter())->get()->keyBy('id');

        return array_map(function ($item) use ($receipts, $people) {
            $receipt = $receipts[$item['id']] ?? [];

            return [...$item, 'confirmed_at' => $receipt['confirmed_at'] ?? null,
                'confirmed_by' => $receipt['confirmed_by'] ?? null,
                'confirmed_by_name' => $people->get($receipt['confirmed_by'] ?? null)?->name];
        }, self::definitions($journey->kit_items));
    }
}
