<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Models\User;
use App\Support\Media\WebpImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsSpaClient;
use Tests\TestCase;

/**
 * Пересборка загруженных картинок в WebP.
 *
 * Проверяется не «GD умеет кодировать» — за это отвечает GD, — а четыре
 * обещания, которые легко потерять: станет меньше, не ляжет набок,
 * прозрачность уцелеет, а всё, что не картинка, пройдёт нетронутым.
 */
final class WebpImageTest extends TestCase
{
    use ActsAsSpaClient, RefreshDatabase;

    public function test_a_photograph_shrinks_and_loses_its_excess_size(): void
    {
        $photo = $this->photograph(4000, 3000);
        $before = filesize($photo);

        $result = WebpImage::from($this->upload($photo, 'фото.jpg'));

        $this->assertSame('фото.webp', $result->getClientOriginalName());
        $this->assertSame('image/webp', $result->getClientMimeType());

        [$width, $height, $type] = getimagesize($result->getRealPath());

        $this->assertSame(IMAGETYPE_WEBP, $type);

        // Длинная сторона упёрлась в предел, пропорции сохранились.
        $this->assertSame(2560, $width);
        $this->assertSame(1920, $height);

        // Главное обещание: стало меньше. Насколько — зависит от картинки,
        // поэтому проверяется не доля, а направление.
        $this->assertLessThan($before, $result->getSize());
    }

    /** Меньшее предела не растягиваем: увеличенная картинка хуже маленькой. */
    public function test_a_small_picture_keeps_its_size(): void
    {
        $small = $this->photograph(800, 600);

        $result = WebpImage::from($this->upload($small, 'мелкое.jpg'));

        [$width, $height] = getimagesize($result->getRealPath());

        $this->assertSame(800, $width);
        $this->assertSame(600, $height);
    }

    /**
     * Снимок с телефона лежит в файле боком, а «как надо» записано отдельной
     * меткой. GD её не читает — без поворота картинка легла бы набок навсегда.
     */
    public function test_a_photograph_from_a_phone_is_turned_upright(): void
    {
        // Широкий кадр, помеченный «повёрнут на 90°»: после пересборки он
        // обязан стать высоким.
        $sideways = $this->photograph(1200, 600);
        $tagged = $this->withOrientation($sideways, 6);

        $result = WebpImage::from($this->upload($tagged, 'снимок.jpg'));

        [$width, $height] = getimagesize($result->getRealPath());

        $this->assertSame(600, $width);
        $this->assertSame(1200, $height);
    }

    public function test_transparency_survives(): void
    {
        $badge = $this->transparentBadge();

        $result = WebpImage::from($this->upload($badge, 'значок.png'));

        $image = imagecreatefromwebp($result->getRealPath());

        $corner = imagecolorsforindex($image, imagecolorat($image, 2, 2));
        $centre = imagecolorsforindex($image, imagecolorat($image, 256, 256));

        imagedestroy($image);

        // 127 — полностью прозрачная точка. Залитый фон означал бы испорченный
        // значок: их рисуют именно с прозрачным.
        $this->assertSame(127, $corner['alpha']);
        $this->assertSame(0, $centre['alpha']);
        $this->assertGreaterThan(200, $centre['red']);
    }

    public function test_what_is_not_a_picture_passes_untouched(): void
    {
        $document = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($document, '%PDF-1.4 ещё не договор');

        $result = WebpImage::from($this->upload($document, 'договор.pdf'));

        $this->assertSame('договор.pdf', $result->getClientOriginalName());
    }

    /** GIF бывает движущимся, а WebP от GD — нет: от анимации остался бы кадр. */
    public function test_a_gif_passes_untouched(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gif');
        $image = imagecreatetruecolor(60, 40);
        imagegif($image, $path);
        imagedestroy($image);

        $result = WebpImage::from($this->upload($path, 'кот.gif'));

        $this->assertSame('кот.gif', $result->getClientOriginalName());
    }

    public function test_it_can_be_switched_off_whole(): void
    {
        config(['media.webp.enabled' => false]);

        $result = WebpImage::from($this->upload($this->photograph(1200, 900), 'фото.jpg'));

        $this->assertSame('фото.jpg', $result->getClientOriginalName());
    }

    /** И то же самое — на живом пути загрузки, а не только в обработчике. */
    public function test_an_uploaded_avatar_reaches_the_bucket_as_webp(): void
    {
        Storage::fake('s3');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('profile.avatar.store'), [
                'avatar' => new UploadedFile($this->photograph(3000, 2000), 'селфи.jpg', 'image/jpeg', null, true),
            ])
            ->assertOk();

        $path = (string) $user->refresh()->avatar_path;

        $this->assertStringEndsWith('.webp', $path);

        [$width, , $type] = getimagesizefromstring(Storage::disk('s3')->get($path));

        $this->assertSame(IMAGETYPE_WEBP, $type);
        $this->assertSame(2560, $width);
    }

    /* ---------- Подопытные ---------- */

    private function upload(string $path, string $name): UploadedFile
    {
        // Копия: обработчик отдаёт путь к своему временному файлу, а исходник
        // в проверках нужен ещё раз.
        $copy = tempnam(sys_get_temp_dir(), 'src');
        copy($path, $copy);

        return new UploadedFile($copy, $name, null, null, true);
    }

    /** Картинка, похожая на фотографию: ровную заливку webp сжал бы иначе. */
    private function photograph(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);

        for ($y = 0; $y < $height; $y += 4) {
            for ($x = 0; $x < $width; $x += 4) {
                $colour = imagecolorallocate(
                    $image,
                    (int) ($x * 255 / $width),
                    (int) ($y * 255 / $height),
                    ($x + $y) % 255,
                );

                imagefilledrectangle($image, $x, $y, $x + 3, $y + 3, $colour);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'photo');
        imagejpeg($image, $path, 92);
        imagedestroy($image);

        return $path;
    }

    private function transparentBadge(): string
    {
        $image = imagecreatetruecolor(512, 512);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        imagefilledrectangle($image, 0, 0, 511, 511, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledellipse($image, 256, 256, 400, 400, imagecolorallocate($image, 220, 40, 60));

        $path = tempnam(sys_get_temp_dir(), 'badge');
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    /**
     * Вписывает в JPEG метку поворота — ту самую, что ставит камера телефона.
     *
     * Собирается руками: записывать EXIF в PHP нечем, а без настоящей метки
     * проверка поворота проверяла бы сама себя.
     */
    private function withOrientation(string $jpeg, int $orientation): string
    {
        $tiff = "II\x2A\x00\x08\x00\x00\x00"       // порядок байтов, подпись, смещение IFD0
            ."\x01\x00"                              // записей в IFD0 — одна
            ."\x12\x01"                              // тег 0x0112 — Orientation
            ."\x03\x00"                              // тип SHORT
            ."\x01\x00\x00\x00"                      // одно значение
            .pack('v', $orientation)."\x00\x00"      // само значение
            ."\x00\x00\x00\x00";                     // следующего IFD нет

        $exif = "Exif\x00\x00".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

        $contents = (string) file_get_contents($jpeg);

        $path = tempnam(sys_get_temp_dir(), 'exif');
        // Сразу за маркером начала изображения (FFD8), как и кладёт камера.
        file_put_contents($path, substr($contents, 0, 2).$segment.substr($contents, 2));

        return $path;
    }
}
