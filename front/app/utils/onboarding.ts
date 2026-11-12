import type { RouteSettings } from '~/types/onboarding'
export function onboardingSettings(): RouteSettings {
  return {
    notifications_enabled: true,
    passed_message: 'Тест сдан! Продолжайте маршрут.',
    failed_message: 'Ничего страшного: повторите материал и попробуйте ещё раз.',
    completed_message: 'Вся программа адаптации позади. Добро пожаловать в команду!',
    stage_notification: 'Наступил новый этап адаптации.',
    status_labels: { locked: 'Закрыт', available: 'Доступен', in_progress: 'В процессе', completed: 'Пройден', quiz_failed: 'Нужно повторить тест' },
  }
}
