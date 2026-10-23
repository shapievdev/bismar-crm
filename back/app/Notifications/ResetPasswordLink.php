<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Письмо со ссылкой на смену забытого пароля.
 *
 * Своё, а не то, что идёт с фреймворком: стандартное написано по-английски, а
 * ссылка в нём ведёт на маршрут Laravel, которого здесь нет — форма живёт в
 * приложении Nuxt. Отсюда и адрес из `app.frontend_url`.
 *
 * Не в очереди намеренно, хотя очередь в приложении есть. Человек стоит у формы
 * и ждёт письма прямо сейчас; отложенное на очередь оно пришло бы, когда рабочий
 * процесс до него дойдёт, — а стоит тому встать, и «письмо отправлено»
 * превратилось бы в неправду, о которой никто не узнает.
 *
 * Текст называет срок и говорит, что делать, если это не вы. Второе важнее
 * первого: чаще всего такое письмо получает человек, который ничего не
 * забывал, — и ему нужно знать, что делать не надо ничего.
 */
final class ResetPasswordLink extends Notification
{
    public function __construct(private readonly string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        $minutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Смена пароля в платформе BISMAR')
            ->greeting($notifiable->first_name === null || $notifiable->first_name === ''
                ? 'Здравствуйте!'
                : 'Здравствуйте, '.$notifiable->first_name.'!')
            ->line('Кто-то попросил сменить пароль к вашей учётной записи в платформе BISMAR. Если это вы — нажмите кнопку ниже и придумайте новый пароль.')
            ->action('Сменить пароль', $this->link($notifiable))
            ->line('Ссылка работает '.$minutes.' минут и только один раз.')
            ->line('Если пароль вы не забывали — ничего делать не нужно: без этой ссылки пароль не сменится, а сама она скоро перестанет работать. Входить в платформу по-прежнему нужно по номеру телефона.')
            ->salutation('Это письмо отправлено автоматически, отвечать на него не нужно.');
    }

    /**
     * Адрес формы — в приложении Nuxt, а не в Laravel.
     *
     * В ссылке номер сотрудника, а не его почта. Почта в адресе осела бы в
     * истории браузера и в журналах любого сервера по дороге, а пускает всё
     * равно не она, а токен.
     */
    private function link(User $user): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            .'/password/reset?user='.$user->getKey()
            .'&token='.$this->token;
    }
}
