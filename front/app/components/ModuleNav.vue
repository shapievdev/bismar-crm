<script setup lang="ts">
const { can, isAdmin, isSuperAdmin } = useAuth()
const route = useRoute()

/*
 * Сколько работ ждёт проверки и есть ли они вообще. Считает навигация — она
 * спрашивает сервер один раз на вход, а не каждая страница по отдельности.
 */
const { pendingAttestations, hasAttestations } = useNavigation()

/* ---------- Прокрутка полосы ---------- */

/**
 * Растворяющийся край — обещание, что дальше есть ещё.
 *
 * Поэтому он появляется только когда там правда что-то есть: постоянная маска
 * съедала правый край последней пилюли и на просторной полосе, где мотать
 * нечего, — «Корзина» выглядела обрезанной без всякой причины.
 */
const strip = useTemplateRef<HTMLElement>('strip')

const hasMoreBefore = ref(false)
const hasMoreAfter = ref(false)

function measure() {
  const node = strip.value

  if (!node) {
    return
  }

  // Округление вниз: дробные пиксели при масштабировании страницы дают
  // «остаток» в полпикселя, и край растворялся бы у полосы, которая уже домотана.
  hasMoreBefore.value = node.scrollLeft > 1
  hasMoreAfter.value = Math.ceil(node.scrollLeft + node.clientWidth) < node.scrollWidth
}

/**
 * Колесо мыши крутит только вертикально, а полосе нужен горизонтальный ход:
 * без этого домотать её мышью нельзя вовсе — только тачпадом или перетаскивая
 * невидимую полосу прокрутки.
 */
function onWheel(event: WheelEvent) {
  const node = strip.value

  if (!node || node.scrollWidth <= node.clientWidth) {
    return
  }

  // Горизонтальный жест тачпада отдаём браузеру: он и так делает нужное.
  if (Math.abs(event.deltaY) <= Math.abs(event.deltaX)) {
    return
  }

  event.preventDefault()
  node.scrollLeft += event.deltaY
}

onMounted(() => {
  measure()

  // Пилюли меняются вместе с разделом, а ширина полосы — вместе с окном:
  // мерить надо и то и другое, иначе край останется обещать несуществующее.
  const observer = new ResizeObserver(measure)

  if (strip.value) {
    observer.observe(strip.value)
  }

  onBeforeUnmount(() => observer.disconnect())
})

watch(() => route.path, () => nextTick(measure))

interface NavLink {
  to: string
  label: string
  visible: boolean
  /** Exact by default; a section with child routes says so explicitly. */
  matches?: (path: string) => boolean
}

/**
 * Pages within the module the reader is currently in.
 *
 * The rail decides which module; this decides where inside it. A module with a
 * single page renders nothing rather than a lone pill that cannot be left.
 */
const links = computed<NavLink[]>(() => {
  const path = route.path

  if (path.startsWith('/lms')) {
    return [
      // Раздел показывают тому, кому он открыт: права у курсов, документов и
      // справочников свои (2026-09-11), и вкладка в чужой раздел вела бы в
      // отказ.
      { to: '/lms', label: 'Курсы', visible: can('courses.view'), matches: (p: string) => p === '/lms' },
      // Рядом с материалами: по одному учатся, по другому работают, в третье
      // заглядывают за ответом посреди разговора с клиентом.
      {
        to: '/lms/documents',
        label: 'Документы',
        visible: can('documents.view'),
        matches: (p: string) => p.startsWith('/lms/documents') && p !== '/lms/documents/categories',
      },
      {
        to: '/lms/handbooks',
        label: 'Справочники',
        visible: can('handbooks.view'),
        matches: (p: string) => p.startsWith('/lms/handbooks') && p !== '/lms/handbooks/categories',
      },
      // «Мой план» — назначенное, «Мои материалы» — всё, за что человек брался
      // сам. Первое идёт раньше: с него начинают. План ведёт и в документы,
      // поэтому виден всякому, кому открыт хоть один раздел.
      { to: '/lms/plan', label: 'Мой план', visible: true },
      { to: '/lms/my', label: 'Мои курсы', visible: can('courses.view') },
      // Вкладка есть у того, кому сдают работы: назначение — не право с
      // галочкой, и пустой раздел в меню обещал бы то, чего за ним нет.
      {
        to: '/lms/attestations',
        label: pendingAttestations.value > 0
          ? `Аттестация · ${pendingAttestations.value}`
          : 'Аттестация',
        visible: hasAttestations.value,
      },
      { to: '/lms/assistant', label: 'Консультант', visible: true },
      // Корзина — тому, кто вправе удалять: остальным в ней нечего искать.
      { to: '/lms/trash', label: 'Корзина', visible: can('courses.delete') || can('documents.delete') || can('handbooks.delete') },
      //
      // Категорий здесь нет намеренно. С появлением справочников их стало три
      // штуки — курсов, документов, справочников, — и полоса разделов
      // превратилась в список настроек, из которого не найти сами разделы.
      // Дерево правят там же, где смотрят его содержимое: кнопка «Категории»
      // стоит в шапке каждого каталога, рядом с «Новый …».
      //
    ].filter(link => link.visible)
  }

  // Главная и новости — один модуль: рельса ведёт на «Панель», а лента на ней
  // и есть первое, ради чего сюда заходят.
  if (path === '/' || path.startsWith('/news')) {
    return [
      { to: '/', label: 'Главная', visible: true, matches: (p: string) => p === '/' },
      { to: '/news', label: 'Новости', visible: true, matches: (p: string) => p.startsWith('/news') },
    ]
  }

  if (path.startsWith('/analytics')) {
    // Продажные вкладки — под одним правом и на одной витрине: цифры приходят
    // из одного источника, и разделять «кто видит выручку» и «кто видит
    // клиентов» здесь нечем. Обучение — право другое: там курсы, не деньги.
    return [
      {
        to: '/analytics',
        label: 'Продажи',
        visible: can('analytics.view'),
        matches: (p: string) => p === '/analytics',
      },
      { to: '/analytics/customers', label: 'Клиенты', visible: can('analytics.view') },
      { to: '/analytics/products', label: 'Товары', visible: can('analytics.view') },
      { to: '/analytics/learning', label: 'Обучение', visible: can('enrollments.manage') },
    ].filter(link => link.visible)
  }

  if (path.startsWith('/staff')) {
    return [
      // Структура открыта всем, список людей — по праву: это разные вопросы,
      // «как устроена компания» и «что можно с этим человеком сделать».
      {
        to: '/staff/structure',
        label: 'Структура',
        visible: true,
        matches: (p: string) => p.startsWith('/staff/structure'),
      },
      {
        to: '/staff',
        label: 'Сотрудники',
        visible: can('users.view'),
        matches: (p: string) => p === '/staff' || /^\/staff\/(new|\d+)/.test(p),
      },
      // Группы открыты всем, как и структура: без названия группы не выбрать
      // адресата новости тому, кто её ведёт, а права на людей у него может и
      // не быть.
      {
        to: '/staff/groups',
        label: 'Группы',
        visible: true,
        matches: (p: string) => p.startsWith('/staff/groups'),
      },
    ].filter(link => link.visible)
  }

  if (path.startsWith('/settings')) {
    return [
      // Рассылка будит телефоны всей компании — вкладка видна тем, кто вправе
      // это делать, а не всем, у кого есть настройки.
      { to: '/settings/broadcasts', label: 'Рассылки', visible: isAdmin.value },
      // Пробелы в базе закрывают авторы курсов — журнал открыт им.
      { to: '/settings/questions', label: 'Вопросы', visible: can('courses.update') },
      // Связка с чужими службами — решение о компании, а не право с галочкой.
      { to: '/settings/integrations', label: 'Интеграции', visible: isAdmin.value },
      // Платёжный ключ и модель — только суперадминистратору.
      { to: '/settings/ai', label: 'Консультант', visible: isSuperAdmin.value },
    ].filter(link => link.visible)
  }

  return []
})

function isActive(link: NavLink): boolean {
  return link.matches ? link.matches(route.path) : route.path.startsWith(link.to)
}
</script>

<template>
  <nav
    v-if="links.length > 1"
    ref="strip"
    class="module-nav"
    :class="{
      'module-nav--more-before': hasMoreBefore,
      'module-nav--more-after': hasMoreAfter,
    }"
    aria-label="Разделы модуля"
    @scroll.passive="measure"
    @wheel="onWheel"
  >
    <NuxtLink
      v-for="link in links"
      :key="link.to"
      :to="link.to"
      class="module-nav__pill"
      :class="{ 'module-nav__pill--active': isActive(link) }"
      :aria-current="isActive(link) ? 'page' : undefined"
    >
      {{ link.label }}
    </NuxtLink>
  </nav>
</template>

<style scoped>
.module-nav {
  display: flex;
  gap: 0.4rem;
  margin-right: auto;
  min-width: 0;

  /*
   * Прокрутка на любой ширине, а не только на телефоне. Разделов стало больше,
   * и на узком окне ряд вылезал за свои границы — пилюли ложились поверх
   * аватара справа. Полоса обязана оставаться в отведённом ей месте: чего не
   * поместилось, до того доматывают.
   */
  overflow-x: auto;
  scrollbar-width: none;
}

/*
 * Край растворяется только с той стороны, где ещё что-то осталось: маска на
 * все случаи обрезала последнюю пилюлю и тогда, когда мотать было нечего.
 */
.module-nav--more-after {
  mask-image: linear-gradient(to right, #000 calc(100% - 1.5rem), transparent);
}

.module-nav--more-before {
  mask-image: linear-gradient(to left, #000 calc(100% - 1.5rem), transparent);
}

.module-nav--more-before.module-nav--more-after {
  mask-image: linear-gradient(
    to right,
    transparent,
    #000 1.5rem,
    #000 calc(100% - 1.5rem),
    transparent
  );
}

.module-nav::-webkit-scrollbar {
  display: none;
}

.module-nav__pill {
  /* Never wrap: a two-line pill breaks the row's rhythm and, once one wraps,
     the ones after it fall off the edge. The row scrolls instead. */
  white-space: nowrap;
  flex-shrink: 0;
  padding: 0.5rem 1.05rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-raised);
  color: var(--color-text-muted);
  font-size: 0.92rem;
  text-decoration: none;
  transition: background-color 0.15s ease, color 0.15s ease;
}

/*
 * Held off the active pill on purpose. A bare `:hover` outranks `--active` on
 * specificity, so it would repaint the text of the accent-filled pill in the
 * page's text colour — near-black on near-black in light, near-white on lime in
 * dark. Each state gets its own hover instead.
 */
.module-nav__pill:hover:not(.module-nav__pill--active) {
  color: var(--color-text);
}

.module-nav__pill--active {
  background: var(--color-accent);
  color: var(--color-accent-text);
}

.module-nav__pill--active:hover {
  background: var(--color-accent-hover);
  color: var(--color-accent-text);
}

</style>