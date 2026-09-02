<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use App\Support\Lms\GoogleDrive;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Файл, выбранный в окне Google Диска.
 *
 * Приходит не сам файл, а рассказ о нём: номер у Google, имя и вид. Из всего
 * этого доверия заслуживает только номер — по нему мы соберём адрес сами (см.
 * GoogleDrive). Имя и вид идут на экран и в подпись, поэтому длина у них
 * ограничена, а больше о них знать нечего.
 */
final class AttachDriveFileRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'external_id' => ['required', 'string', 'max:200'],
            'name' => ['required', 'string', 'max:255'],
            'mime_type' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Номер проверяется тем же правилом, каким из него потом собирают адрес:
     * иначе в рамку на странице урока уехало бы что угодно.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $id = $this->input('external_id');

            if (is_string($id) && ! app(GoogleDrive::class)->isFileId($id)) {
                $validator->errors()->add('external_id', 'Это не похоже на файл с Google Диска.');
            }
        });
    }
}
