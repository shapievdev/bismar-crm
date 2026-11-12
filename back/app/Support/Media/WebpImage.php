<?php

declare(strict_types=1);

namespace App\Support\Media;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Пересобирает загруженную картинку в WebP — один раз, при загрузке.
 *
 * Стоит перед каждым `->store()`, который принимает файл от человека, и
 * подменяет файл на пересобранный. Остальное приложение об этом не знает:
 * возвращается такой же UploadedFile, и имя, тип и размер вложения берутся с
 * него, как и раньше.
 *
 * **При загрузке, а не при отдаче.** Картинку загружают однажды, а смотрят
 * сотни раз; пересобирать её на каждый показ значило бы держать кэш
 * вариантов, следить за его протуханием и платить за процессор вместо
 * дискового места, которое и так дешёвое.
 *
 * Что делается, по убыванию пользы:
 *
 * 1. **Уменьшение.** Главная экономия здесь, а не в формате: камера телефона
 *    снимает четыре тысячи точек в ширину, самое широкое место в приложении
 *    — около тысячи двухсот. Исходник меньше предела не растягиваем.
 * 2. **Поворот по EXIF.** Снимок с телефона лежит в файле боком, а
 *    «правильно» записано отдельной меткой. GD её не читает, и без этого
 *    пересобранная картинка легла бы набок — уже навсегда.
 * 3. **Кодирование в WebP** с качеством, на котором глаз разницы не берёт.
 *
 * Правила осторожности, каждое выстрадано тем, что иначе теряется:
 *
 * - **Не вышло — кладём как пришло.** Сбой пересборки не должен превращаться
 *   в ошибку загрузки: человек уже выбрал файл, и ему важнее, что файл
 *   сохранился, чем то, сколько он весит.
 * - **Стало больше — оставляем исходник.** На ровной заливке — скриншоте,
 *   схеме, логотипе — PNG бывает меньше WebP с потерями. Тогда пробуем WebP
 *   без потерь, а если и он больше, честно оставляем то, что было: смысл
 *   всей затеи — чтобы весило меньше.
 * - **GIF не трогаем.** Он бывает движущимся, а GD собирает только
 *   неподвижный WebP: от анимации остался бы первый кадр.
 * - **Прозрачность сохраняется.** Значки достижений рисуют с прозрачным
 *   фоном, и залитый белым значок — это испорченный значок.
 */
final class WebpImage
{
    /**
     * Что умеем пересобирать — по содержимому файла, а не по его имени и не
     * по тому, чем его назвал клиент.
     */
    private const CONVERTIBLE = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /** Временные файлы пересборки — подметаются в конце запроса. */
    private static array $temporary = [];

    private static bool $sweeperRegistered = false;

    /**
     * Пересобранная картинка — или тот же файл, если пересобирать нечего.
     */
    public static function from(UploadedFile $file): UploadedFile
    {
        if (! config('media.webp.enabled', true)) {
            return $file;
        }

        $path = $file->getRealPath();

        if ($path === false || ! is_file($path)) {
            return $file;
        }

        $info = @getimagesize($path);

        if ($info === false || ! in_array($info[2] ?? null, self::CONVERTIBLE, true)) {
            return $file;
        }

        [$width, $height] = $info;
        $pixels = (int) $width * (int) $height;

        if (self::alreadyGood((int) $info[2], (int) $width, (int) $height)) {
            return $file;
        }

        if ($pixels > (int) config('media.webp.max_pixels', 30_000_000)) {
            Log::info('Картинка слишком велика для пересборки — сохранена как есть.', [
                'name' => $file->getClientOriginalName(),
                'pixels' => $pixels,
            ]);

            return $file;
        }

        try {
            return self::rebuild($file, $path, (int) $info[2]) ?? $file;
        } catch (Throwable $exception) {
            // Загрузка важнее пересборки: файл уже выбран человеком, и
            // уронить её из-за экономии места — худший из исходов.
            Log::warning('Картинку не удалось пересобрать в WebP.', [
                'name' => $file->getClientOriginalName(),
                'exception' => $exception,
            ]);

            return $file;
        }
    }

    /**
     * Уже WebP и уже в пределах — трогать нечего.
     *
     * Правило не про экономию времени, а про качество: пересборка с потерями
     * из того, что уже сжато с потерями, портит картинку каждый раз заново.
     * Пройдись `media:rebuild` по хранилищу дважды — и всё в нём станет вдвое
     * хуже. Поэтому повторная пересборка должна быть ничем.
     */
    private static function alreadyGood(int $type, int $width, int $height): bool
    {
        $limit = (int) config('media.webp.max_side', 2560);

        return $type === IMAGETYPE_WEBP && ($limit <= 0 || max($width, $height) <= $limit);
    }

    private static function rebuild(UploadedFile $file, string $path, int $type): ?UploadedFile
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            return null;
        }

        unset($contents);

        try {
            if ($type === IMAGETYPE_JPEG) {
                $image = self::upright($image, $path);
            }

            $image = self::fitted($image);

            $encoded = self::encode($image, (int) filesize($path));

            if ($encoded === null) {
                return null;
            }

            return self::asUpload($encoded, $file);
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * Ставит снимок с телефона как надо: в файле он лежит боком, а «как надо»
     * записано отдельной меткой, которую GD не читает.
     */
    private static function upright(GdImage $image, string $path): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };

        // Зеркальные положения (2, 4, 5, 7) встречаются у снимков фронтальной
        // камерой: там кроме поворота нужен ещё и переворот по горизонтали.
        $mirrored = in_array($orientation, [2, 4, 5, 7], true);

        if ($angle === 0 && ! $mirrored) {
            return $image;
        }

        if ($angle !== 0) {
            $turned = imagerotate($image, $angle, 0);

            if ($turned === false) {
                return $image;
            }

            imagedestroy($image);
            $image = $turned;
        }

        if ($mirrored) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    /** Ужимает длинную сторону до предела. Меньшее не растягивает. */
    private static function fitted(GdImage $image): GdImage
    {
        $limit = (int) config('media.webp.max_side', 2560);
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($limit <= 0 || $longest <= $limit) {
            return $image;
        }

        $scale = $limit / $longest;
        $target = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));

        // Прозрачность: без этих двух строк альфа-канал схлопывается в чёрный
        // — значок достижения приехал бы на чёрной подложке.
        imagealphablending($target, false);
        imagesavealpha($target, true);

        imagecopyresampled(
            $target, $image,
            0, 0, 0, 0,
            imagesx($target), imagesy($target),
            $width, $height,
        );

        imagedestroy($image);

        return $target;
    }

    /**
     * Кодирует и выбирает, что меньше: WebP с потерями, без потерь — или
     * вовсе исходник.
     *
     * @return string|null путь к временному файлу; null — исходник оказался меньше
     */
    private static function encode(GdImage $image, int $originalSize): ?string
    {
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $lossy = self::write($image, (int) config('media.webp.quality', 82));

        if ($lossy === null) {
            return null;
        }

        if (filesize($lossy) < $originalSize) {
            return $lossy;
        }

        /*
         * Исходник оказался меньше — так бывает у ровной заливки: скриншота,
         * схемы, логотипа. Там выигрывает сжатие без потерь, и оно же обычно
         * обходит PNG.
         */
        $lossless = self::write($image, IMG_WEBP_LOSSLESS);

        if ($lossless !== null && filesize($lossless) < min($originalSize, filesize($lossy))) {
            return $lossless;
        }

        return filesize($lossy) < $originalSize ? $lossy : null;
    }

    private static function write(GdImage $image, int $quality): ?string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'webp');

        if ($temporary === false) {
            return null;
        }

        self::sweepLater($temporary);

        return imagewebp($image, $temporary, $quality) ? $temporary : null;
    }

    /**
     * Тот же файл для остального приложения: имя с новым расширением, тип и
     * размер — пересобранного.
     */
    private static function asUpload(string $path, UploadedFile $original): UploadedFile
    {
        $name = pathinfo($original->getClientOriginalName(), PATHINFO_FILENAME);

        return new UploadedFile(
            $path,
            ($name === '' ? 'image' : $name).'.webp',
            'image/webp',
            null,
            // Файл наш, а не из формы: без этого Symfony отказался бы его
            // принимать, не найдя следов загрузки.
            test: true,
        );
    }

    /**
     * Временные файлы живут до конца запроса: `store()` ещё не случился, и
     * убирать их сразу нельзя.
     */
    private static function sweepLater(string $path): void
    {
        self::$temporary[] = $path;

        if (self::$sweeperRegistered) {
            return;
        }

        self::$sweeperRegistered = true;

        register_shutdown_function(static function (): void {
            foreach (self::$temporary as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            self::$temporary = [];
        });
    }
}
