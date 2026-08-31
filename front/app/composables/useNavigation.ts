/**
 * Разделы платформы — один источник для рельсы слева и для дока внизу.
 *
 * Оба показывают одно и то же, только по-разному: на широком экране колонка
 * значков, на телефоне — плавающая полоса с самыми нужными и «ещё» для
 * остальных. Держать два списка значило бы однажды добавить раздел в один и
 * забыть про другой.
 */

export interface NavItem {
  to: string
  label: string
  /** Имя значка — рисует AppNavIcon. */
  icon: string
  /** Какие адреса считаются «внутри» этого раздела. */
  matches: (path: string) => boolean
  /** Число на значке: непрочитанные сообщения, новости к ознакомлению. */
  badge?: number
}

export function useNavigation() {
  const { can } = useAuth()
  const route = useRoute()
  const messenger = useMessenger()
  const { fetchPendingCount } = useNewsApi()

  /**
   * Сколько новостей ждут ознакомления. Считает сервер: значок висит на каждой
   * странице, и тянуть ради него всю ленту незачем.
   *
   * Состояние общее — на него смотрят и рельса, и док.
   */
  const pendingNews = useState('nav.pending-news', () => 0)

  async function refreshBadges(): Promise<void> {
    try {
      pendingNews.value = (await fetchPendingCount()).data.count
    }
    catch {
      pendingNews.value = 0
    }
  }

  /**
   * Разделы в том порядке, в каком их читают: сначала то, ради чего заходят
   * каждый день, настройки — последними.
   */
  const items = computed<NavItem[]>(() => [
    {
      to: '/',
      label: 'Главная',
      icon: 'dashboard',
      visible: true,
      // Новости живут в этом же модуле: рельса ведёт на главную, а лента там же.
      matches: (path: string) => path === '/' || path.startsWith('/news'),
      badge: pendingNews.value,
    },
    {
      to: '/lms',
      label: 'База знаний',
      icon: 'library',
      visible: can('courses.view'),
      matches: (path: string) => path.startsWith('/lms'),
    },
    {
      to: '/messenger',
      label: 'Сообщения',
      icon: 'messages',
      visible: true,
      matches: (path: string) => path.startsWith('/messenger'),
      badge: messenger.unreadTotal.value,
    },
    {
      // Раздел открыт двоим: тому, кому доверены деньги, и тому, кому доверено
      // обучение. Без права на продажи модуль открывается обучением.
      to: can('analytics.view') ? '/analytics' : '/analytics/learning',
      label: 'Аналитика',
      icon: 'analytics',
      visible: can('analytics.view') || can('enrollments.manage'),
      matches: (path: string) => path.startsWith('/analytics'),
    },
    {
      // Структура компании открыта всем, поэтому и раздел виден всем; список
      // людей за ним — по праву, и без него модуль открывается структурой.
      to: can('users.view') ? '/staff' : '/staff/structure',
      label: 'Сотрудники',
      icon: 'staff',
      visible: true,
      matches: (path: string) => path.startsWith('/staff'),
    },
    {
      // У каждого есть профиль, поэтому раздел не скрывается; какие страницы он
      // предлагает, решает ModuleNav по правам смотрящего.
      to: '/settings/profile',
      label: 'Настройки',
      icon: 'settings',
      visible: true,
      matches: (path: string) => path.startsWith('/settings'),
    },
  ].filter(item => item.visible))

  /** Раздел, в котором человек сейчас находится. */
  const current = computed(() => items.value.find(item => item.matches(route.path)) ?? null)

  function isCurrent(item: NavItem): boolean {
    return item.matches(route.path)
  }

  return { items, current, isCurrent, pendingNews, refreshBadges }
}
