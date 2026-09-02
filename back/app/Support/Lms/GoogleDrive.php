<?php

declare(strict_types=1);

namespace App\Support\Lms;

/**
 * Адреса файла на Google Диске.
 *
 * Собираются здесь из номера файла и его вида, а не берутся с экрана. Присланный
 * адрес попал бы прямиком в `src` рамки на странице урока — то есть чужой сайт
 * показывался бы внутри нашего, от нашего имени и рядом с нашими куками. Номер
 * же ничего не решает: из него получается только адрес Google. Тот же приём, что
 * у внешних видео на фронте, — см. `utils/video.ts`.
 *
 * Виды Google различает не расширением, а своими mime: у Документа, Таблицы и
 * Презентации адреса разные, и это единственное место, где разницу приходится
 * знать.
 */
final readonly class GoogleDrive
{
    /**
     * Номер файла у Google: буквы, цифры, дефис и подчёркивание. Длина не
     * закреплена — она менялась за годы, — поэтому проверяется только состав и
     * разумные границы.
     */
    private const ID = '/^[A-Za-z0-9_-]{10,200}$/';

    private const DOCUMENT = 'application/vnd.google-apps.document';

    private const SPREADSHEET = 'application/vnd.google-apps.spreadsheet';

    private const PRESENTATION = 'application/vnd.google-apps.presentation';

    private const FOLDER = 'application/vnd.google-apps.folder';

    public function isFileId(?string $id): bool
    {
        return $id !== null && preg_match(self::ID, $id) === 1;
    }

    /**
     * Адрес для встроенного просмотра — то, что уходит в рамку на странице.
     *
     * У Документов, Таблиц и Презентаций свой просмотр, у папки — её содержимое
     * сеткой, у всего остального — общий просмотрщик Диска, который сам решает,
     * чем показать PDF, картинку или видео.
     */
    public function embedUrl(string $id, ?string $mimeType): string
    {
        return match ($mimeType) {
            self::DOCUMENT => "https://docs.google.com/document/d/{$id}/preview",
            self::SPREADSHEET => "https://docs.google.com/spreadsheets/d/{$id}/preview",
            self::PRESENTATION => "https://docs.google.com/presentation/d/{$id}/embed",
            self::FOLDER => "https://drive.google.com/embeddedfolderview?id={$id}#grid",
            default => "https://drive.google.com/file/d/{$id}/preview",
        };
    }

    /**
     * Адрес самого файла на Диске: туда уходят по ссылке «Открыть в Google
     * Диске». Нужен и как запасной выход — рамка пуста у того, кому файл не
     * открыт, и вместо загадочной пустоты человек получает место, где можно
     * попросить доступ.
     */
    public function viewUrl(string $id, ?string $mimeType): string
    {
        return match ($mimeType) {
            self::DOCUMENT => "https://docs.google.com/document/d/{$id}/edit",
            self::SPREADSHEET => "https://docs.google.com/spreadsheets/d/{$id}/edit",
            self::PRESENTATION => "https://docs.google.com/presentation/d/{$id}/edit",
            self::FOLDER => "https://drive.google.com/drive/folders/{$id}",
            default => "https://drive.google.com/file/d/{$id}/view",
        };
    }
}
