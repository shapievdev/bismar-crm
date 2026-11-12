<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Enums\AttachmentSource;
use App\Models\Achievement;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Пересборка того, что уже лежит в хранилище.
 *
 * Проверяется не «картинка стала WebP» — за это отвечает WebpImageTest, — а
 * то, что команда делает с базой и корзиной: подменяет объект, переписывает
 * строку целиком, убирает за собой и ничего не портит при повторе.
 */
final class RebuildStoredImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
    }

    public function test_it_replaces_the_object_and_rewrites_the_row(): void
    {
        $lesson = Lesson::factory()->create();

        $attachment = LessonAttachment::create([
            'lesson_id' => $lesson->getKey(),
            'disk' => 's3',
            'path' => $this->stored('lessons/1/снимок.jpg', $this->photograph(3000, 2000)),
            'name' => 'снимок.jpg',
            'mime_type' => 'image/jpeg',
            'size' => Storage::disk('s3')->size('lessons/1/снимок.jpg'),
        ]);

        $was = $attachment->size;

        $this->artisan('media:rebuild')->assertSuccessful();

        $attachment->refresh();

        // Строка переписана целиком: не только путь, но и то, что по нему
        // теперь лежит.
        $this->assertStringEndsWith('.webp', (string) $attachment->path);
        $this->assertSame('снимок.webp', $attachment->name);
        $this->assertSame('image/webp', $attachment->mime_type);
        $this->assertLessThan($was, $attachment->size);

        // Новый объект лежит в той же папке — по ключу видно, чей он.
        $this->assertStringStartsWith('lessons/1/', (string) $attachment->path);

        Storage::disk('s3')->assertExists($attachment->path);
        Storage::disk('s3')->assertMissing('lessons/1/снимок.jpg');

        [$width, , $type] = getimagesizefromstring(Storage::disk('s3')->get($attachment->path));

        $this->assertSame(IMAGETYPE_WEBP, $type);
        $this->assertSame(2560, $width);
        $this->assertSame($attachment->size, Storage::disk('s3')->size($attachment->path));
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $achievement = Achievement::query()->create(['title' => 'Значок']);
        $achievement->forceFill(['image_path' => $this->stored('achievements/1/знак.png', $this->photograph(3000, 2000))])->save();

        $this->artisan('media:rebuild')->assertSuccessful();

        $path = (string) $achievement->refresh()->image_path;
        $bytes = Storage::disk('s3')->get($path);

        /*
         * Повтор обязан быть ничем, и это про качество, а не про время:
         * пересборка с потерями из сжатого с потерями портит картинку каждый
         * раз заново. Сравниваем байты, а не слова в выводе: вывод — дело
         * оформления, а испортится именно содержимое.
         */
        $this->artisan('media:rebuild')->assertSuccessful();

        $this->assertSame($path, (string) $achievement->refresh()->image_path);
        $this->assertSame($bytes, Storage::disk('s3')->get($path));
    }

    public function test_the_dry_run_touches_nothing(): void
    {
        $achievement = Achievement::query()->create(['title' => 'Значок']);
        $achievement->forceFill(['image_path' => $this->stored('achievements/1/знак.jpg', $this->photograph(3000, 2000))])->save();

        $before = Storage::disk('s3')->get('achievements/1/знак.jpg');

        $this->artisan('media:rebuild --dry-run')->assertSuccessful();

        $this->assertSame('achievements/1/знак.jpg', $achievement->refresh()->image_path);
        $this->assertSame($before, Storage::disk('s3')->get('achievements/1/знак.jpg'));
        $this->assertCount(1, Storage::disk('s3')->allFiles());
    }

    public function test_what_is_not_a_picture_is_left_alone(): void
    {
        $lesson = Lesson::factory()->create();

        $attachment = LessonAttachment::create([
            'lesson_id' => $lesson->getKey(),
            'disk' => 's3',
            'path' => $this->stored('lessons/1/договор.pdf', $this->document()),
            'name' => 'договор.pdf',
            'mime_type' => 'application/pdf',
            'size' => Storage::disk('s3')->size('lessons/1/договор.pdf'),
        ]);

        $this->artisan('media:rebuild')->assertSuccessful();

        $this->assertSame('lessons/1/договор.pdf', $attachment->refresh()->path);
        Storage::disk('s3')->assertExists('lessons/1/договор.pdf');
    }

    /** У вложения с Диска нашего объекта нет — только номер чужого файла. */
    public function test_a_google_drive_attachment_is_skipped(): void
    {
        $lesson = Lesson::factory()->create();

        $attachment = LessonAttachment::create([
            'lesson_id' => $lesson->getKey(),
            'disk' => 's3',
            'path' => 'не-наш-объект',
            'name' => 'Схема зала',
            'mime_type' => 'image/png',
            'size' => 0,
            'source' => AttachmentSource::GoogleDrive,
            'external_id' => '1AbCdEf',
        ]);

        $this->artisan('media:rebuild')->assertSuccessful();

        $this->assertSame('не-наш-объект', $attachment->refresh()->path);
    }

    /* ---------- Подопытные ---------- */

    private function stored(string $path, string $contents): string
    {
        Storage::disk('s3')->put($path, $contents);

        return $path;
    }

    private function document(): string
    {
        return '%PDF-1.4 ещё не договор';
    }

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

        $contents = (string) file_get_contents($path);
        @unlink($path);

        return $contents;
    }
}
