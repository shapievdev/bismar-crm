<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AttachmentSource;
use App\Support\Lms\StoredFiles;
use App\Support\Media\WebpImage;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Пересобирает в WebP то, что загрузили до появления обработчика.
 *
 * Новые картинки приходят уже пересобранными (App\Support\Media\WebpImage), а
 * лежащие в хранилище остались какими были — снимками с телефона на несколько
 * мегабайт. Команда проходит по всему, что указывает на объект в корзине,
 * прогоняет его через тот же обработчик и подменяет.
 *
 * **Безвредна при повторе** — и это не удобство, а защита качества: пересборка
 * с потерями из того, что уже сжато с потерями, портит картинку каждый раз.
 * Поэтому обработчик возвращает уже пригодный WebP нетронутым, и второй проход
 * ничего не делает (см. WebpImage::alreadyGood).
 *
 * Порядок на каждой строке: залить новое, переписать строку, убрать старое.
 * Упади она посередине — останется лишний объект в корзине, который подметёт
 * правило жизненного цикла; строка, указывающая в пустоту, была бы хуже.
 *
 * Вложения с Google Диска пропускаются: там нет нашего объекта — только номер
 * чужого файла, и пускать к нему решает Google (см. AttachmentSource).
 */
final class RebuildStoredImages extends Command
{
    protected $signature = 'media:rebuild {--dry-run : Посчитать, ничего не трогая}';

    protected $description = 'Пересобирает в WebP картинки, загруженные до появления обработчика';

    /**
     * Где в базе лежат ссылки на объекты хранилища.
     *
     * Описанием, а не семью похожими кусками кода: таблиц столько, сколько
     * мест, куда в приложении кладут картинку, и восьмое добавляется строкой.
     *
     * @var list<array{table: string, path: string, disk?: string, name?: string, mime?: string, size?: string, source?: bool}>
     */
    private const TARGETS = [
        ['table' => 'users', 'path' => 'avatar_path'],
        ['table' => 'achievements', 'path' => 'image_path'],
        ['table' => 'courses', 'path' => 'cover_path'],

        ['table' => 'lesson_attachments', 'path' => 'path', 'disk' => 'disk', 'name' => 'name', 'mime' => 'mime_type', 'size' => 'size', 'source' => true],
        ['table' => 'regulation_attachments', 'path' => 'path', 'disk' => 'disk', 'name' => 'name', 'mime' => 'mime_type', 'size' => 'size', 'source' => true],
        ['table' => 'news_attachments', 'path' => 'path', 'disk' => 'disk', 'name' => 'name', 'mime' => 'mime_type', 'size' => 'size'],
        ['table' => 'message_attachments', 'path' => 'path', 'disk' => 'disk', 'name' => 'name', 'mime' => 'mime_type', 'size' => 'size'],
    ];

    private int $rebuilt = 0;

    private int $skipped = 0;

    private int $failed = 0;

    private int $saved = 0;

    public function handle(): int
    {
        if (! config('media.webp.enabled', true)) {
            $this->warn('Пересборка выключена: MEDIA_WEBP=false.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');

        if ($dry) {
            $this->comment('Примерка: ничего не изменится.');
        }

        foreach (self::TARGETS as $target) {
            $this->walk($target, $dry);
        }

        $this->newLine();
        $this->info(sprintf(
            'Пересобрано: %d. Без изменений: %d. Не вышло: %d. Освободилось: %s.',
            $this->rebuilt,
            $this->skipped,
            $this->failed,
            $this->humanise($this->saved),
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array{table: string, path: string, disk?: string, name?: string, mime?: string, size?: string, source?: bool}  $target
     */
    private function walk(array $target, bool $dry): void
    {
        $query = DB::table($target['table'])
            ->whereNotNull($target['path'])
            ->where($target['path'], '!=', '');

        // Вложение с Google Диска живёт у Google: нашего объекта за ним нет.
        if ($target['source'] ?? false) {
            $query->where(fn ($where) => $where
                ->whereNull('source')
                ->orWhere('source', AttachmentSource::Storage->value));
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            return;
        }

        $this->line("{$target['table']}: {$total}");

        // Постранично по ключу: таблица сообщений бывает на сотни тысяч строк,
        // и забирать её целиком в память незачем.
        $query->orderBy('id')->chunkById(200, function ($rows) use ($target, $dry): void {
            foreach ($rows as $row) {
                $this->rebuildOne($target, $row, $dry);
            }
        });
    }

    /**
     * @param  array{table: string, path: string, disk?: string, name?: string, mime?: string, size?: string}  $target
     */
    private function rebuildOne(array $target, object $row, bool $dry): void
    {
        $disk = isset($target['disk']) ? (string) $row->{$target['disk']} : 's3';
        $path = (string) $row->{$target['path']};

        $local = null;

        try {
            $storage = Storage::disk($disk);

            if (! $storage->exists($path)) {
                $this->skipped++;

                return;
            }

            $local = tempnam(sys_get_temp_dir(), 'rebuild');

            if ($local === false) {
                $this->failed++;

                return;
            }

            file_put_contents($local, $storage->get($path));

            $before = (int) filesize($local);

            // Имя показа, а не ключ объекта: по нему обработчик соберёт новое,
            // с расширением .webp.
            $shown = isset($target['name']) ? (string) $row->{$target['name']} : basename($path);

            $file = new UploadedFile($local, $shown === '' ? basename($path) : $shown, null, null, test: true);
            $converted = WebpImage::from($file);

            // Тот же путь — значит пересобирать было нечего: не картинка, уже
            // пригодный WebP, или пересобранное оказалось не меньше.
            if ($converted->getRealPath() === $file->getRealPath()) {
                $this->skipped++;

                return;
            }

            $after = (int) $converted->getSize();

            if ($dry) {
                $this->rebuilt++;
                $this->saved += $before - $after;

                return;
            }

            // Новый объект кладём рядом со старым, в ту же папку: по ключу в
            // корзине должно быть видно, чьё это, как и раньше.
            $fresh = $storage->putFile($this->folderOf($path), $converted);

            if ($fresh === false) {
                $this->failed++;

                return;
            }

            $changes = [$target['path'] => $fresh];

            foreach (['name' => $converted->getClientOriginalName(), 'mime' => 'image/webp', 'size' => $after] as $key => $value) {
                if (isset($target[$key])) {
                    $changes[$target[$key]] = $value;
                }
            }

            DB::table($target['table'])->where('id', $row->id)->update($changes);

            // Старое убирается последним: его неудачная уборка оставит лишний
            // объект, а не строку, указывающую в пустоту.
            StoredFiles::discard($disk, $path);

            $this->rebuilt++;
            $this->saved += $before - $after;
        } catch (Throwable $exception) {
            $this->failed++;

            $this->warn(sprintf('  %s #%d: %s', $target['table'], $row->id, $exception->getMessage()));
        } finally {
            if ($local !== null && is_file($local)) {
                @unlink($local);
            }
        }
    }

    private function folderOf(string $path): string
    {
        $folder = dirname($path);

        return $folder === '.' ? '' : $folder;
    }

    private function humanise(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' Б';
        }

        return $bytes < 1024 * 1024
            ? round($bytes / 1024, 1).' КБ'
            : round($bytes / 1024 / 1024, 1).' МБ';
    }
}
