<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Support\Chat\LinkPreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Карточка ссылки, сказанной в переписке.
 *
 * Спрашивается экраном по мере того, как реплики появляются на глазах, а не
 * кладётся в само сообщение: ждать чужой сайт, пока человек отправляет реплику,
 * нельзя, а хранить у себя чужие заголовки — незачем (см. LinkPreview).
 *
 * Отдельная точка, открытая любому вошедшему, — и потому единственное, что
 * стоит между ней и внутренней сетью, это проверка адреса. Она живёт в
 * HostGuard, и обойти её здесь нечем: контроллер не решает, куда идти.
 */
final class LinkPreviewController extends Controller
{
    public function show(Request $request, LinkPreview $preview): JsonResponse
    {
        $url = (string) $request->validate([
            'url' => ['required', 'string', 'max:2048', 'url:http,https'],
        ])['url'];

        $card = $preview->for($url);

        // Нечего показать — так и говорим, пустым ответом. Ошибкой это не
        // является: половина ссылок в переписке ведёт туда, где нет ни
        // заголовка, ни картинки.
        return response()->json(['data' => $card]);
    }
}
