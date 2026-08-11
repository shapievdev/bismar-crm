<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use App\Support\Analytics\Directory;
use Illuminate\Http\JsonResponse;

/**
 * Из чего собирается панель фильтров, и по какое число доехали данные.
 *
 * Запрашивается один раз при открытии раздела: списки складов и менеджеров за
 * день не меняются, а свежесть выгрузки нужна на каждой вкладке.
 */
final class DirectoryController extends Controller
{
    public function __construct(private readonly Directory $directory) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->directory->options()]);
    }
}
