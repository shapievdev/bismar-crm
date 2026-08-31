<script setup lang="ts">
import type { User } from '~/types/auth'
import { formatDate } from '~/utils/numbers'
import { maskPhone } from '~/utils/phone'

definePageMeta({ middleware: 'auth', permission: 'users.view' })
useHead({ title: 'Сотрудники' })

const { fetchUsers } = useAdminApi()
const { can } = useAuth()
const route = useRoute()
const router = useRouter()

const canManage = computed(() => can('users.manage'))

const search = ref(typeof route.query.search === 'string' ? route.query.search : '')
const page = ref(pageFromQuery(route.query.page))

/**
 * Запрос отстаёт от набора на четверть секунды: одна фамилия — это десяток
 * нажатий, и без задержки каждое из них шло бы в базу за своей страницей.
 */
const term = ref(search.value.trim())
let debounce: ReturnType<typeof setTimeout> | undefined

watch(search, (value) => {
  clearTimeout(debounce)
  debounce = setTimeout(() => {
    term.value = value.trim()
  }, 250)
})

onBeforeUnmount(() => clearTimeout(debounce))

/**
 * Список только читают: завести сотрудника и править его отправляют на
 * отдельные экраны. Формы, раскрывавшиеся над таблицей, уводили внимание с
 * того, ради чего сюда приходят, — с самого списка.
 *
 * Ищет и листает сервер: на странице двадцать пять человек из всех, и отбор по
 * загруженному находил бы только тех, кто и так на виду.
 */
const { data, pending, error } = await useAsyncData(
  'staff.list',
  () => fetchUsers({
    search: term.value || undefined,
    page: page.value > 1 ? page.value : undefined,
  }),
  { watch: [term, page] },
)

// Найденное — свой, куда более короткий список: четвёртая страница всех
// сотрудников почти никогда не четвёртая страница поиска и обычно за её концом.
watch(term, () => {
  page.value = 1
})

// Найденное и открытая страница остаются в адресе: из карточки сотрудника
// возвращаются к тому же списку, а не к его началу.
watchEffect(() => {
  router.replace({
    query: {
      ...(term.value ? { search: term.value } : {}),
      ...(page.value > 1 ? { page: String(page.value) } : {}),
    },
  })
})

const users = computed(() => data.value?.data ?? [])

const total = computed(() => data.value?.meta.total ?? 0)
const currentPage = computed(() => data.value?.meta.current_page ?? 1)
const lastPage = computed(() => data.value?.meta.last_page ?? 1)

/**
 * «Загрузка…» вместо таблицы — только пока её ещё ни разу не было. Дальше
 * список остаётся на месте и лишь притухает: подменять его на строку после
 * каждой набранной буквы значит дёргать страницу под руками.
 */
const isFirstLoad = computed(() => pending.value && !data.value)

const table = useTemplateRef<HTMLElement>('table')

function goToPage(next: number) {
  const target = Math.min(Math.max(1, next), lastPage.value)

  if (target === page.value) {
    return
  }

  page.value = target

  // Иначе следующая страница открывается там же, где оставили предыдущую, —
  // серединой списка.
  table.value?.scrollIntoView({ block: 'start' })
}

function pageFromQuery(value: unknown): number {
  const parsed = Number(value)

  return Number.isInteger(parsed) && parsed > 1 ? parsed : 1
}

/** «Уволен 30 августа 2026» — метка в строке списка. */
function dismissalLabel(user: User): string {
  return user.dismissed_at ? `Уволен ${formatDate(user.dismissed_at)}` : ''
}

/**
 * Почта и телефон одной строкой: разными они заняли бы у списка ещё этаж, а
 * читают их вместе — это способы дозвониться до человека.
 */
function contactsLabel(user: User): string {
  return [user.email, user.phone ? maskPhone(user.phone) : null]
    .filter(Boolean)
    .join(' · ')
}

function permissionsLabel(user: User): string {
  const count = user.own_permissions.length

  return count === 0
    ? 'без прав'
    : `${count} ${pluralise(count, 'право', 'права', 'прав')}`
}

function foundLabel(count: number): string {
  return `${count} ${pluralise(count, 'сотрудник', 'сотрудника', 'сотрудников')}`
}
</script>

<template>
  <section>
    <header class="page-header">
      <div class="page-header__row">
        <div>
          <h1>Сотрудники</h1>
          <p class="muted">
            {{ canManage
              ? 'Откройте карточку, чтобы посмотреть человека, поправить его данные или отметить доступ.'
              : 'Только просмотр: у вас нет права управлять сотрудниками.' }}
          </p>
        </div>

        <NuxtLink v-if="canManage" to="/staff/new" class="button-primary">
          Новый сотрудник
        </NuxtLink>
      </div>

      <div class="page-header__tools">
        <input
          v-model.trim="search"
          type="search"
          class="input search"
          autocomplete="off"
          placeholder="Поиск по фамилии, имени или почте…"
          aria-label="Поиск по сотрудникам"
        >

        <p v-if="!isFirstLoad && !error" class="muted" aria-live="polite">
          {{ term ? `Нашли ${foundLabel(total)}` : foundLabel(total) }}
        </p>
      </div>
    </header>

    <p v-if="isFirstLoad" class="muted">
      Загрузка…
    </p>

    <p v-else-if="error" class="auth-alert" role="alert">
      Не удалось загрузить сотрудников.
    </p>

    <UiEmptyState
      v-else-if="!users.length"
      :title="term ? 'Никого не нашли' : 'Сотрудников пока нет'"
      :description="term
        ? 'Поиск идёт по фамилии, имени, отчеству и почте — проверьте написание.'
        : 'Как только появятся сотрудники, они будут здесь.'"
    >
      <NuxtLink v-if="canManage && !term" to="/staff/new" class="button-primary">
        Завести первого
      </NuxtLink>
    </UiEmptyState>

    <template v-else>
      <div ref="table" class="table-wrap" :class="{ 'table-wrap--stale': pending }">
        <table>
          <thead>
            <tr>
              <th>Сотрудник</th>
              <th>Отдел</th>
              <th>Доступ</th>
            </tr>
          </thead>

          <tbody>
            <tr v-for="row in users" :key="row.id">
              <td :class="{ 'user--dismissed': row.dismissed_at }">
                <NuxtLink :to="`/staff/${row.id}`" class="user__name">
                  {{ row.name }}
                </NuxtLink>
                <div v-if="row.job_title" class="muted">
                  {{ row.job_title }}
                </div>
                <div class="muted">
                  {{ contactsLabel(row) }}
                </div>
              </td>

              <!--
                Отделов у человека бывает несколько — начальник направления
                нередко и в шапке компании, и во главе своего отдела, — поэтому
                здесь список, а не одна строка. Роль подписана только у
                руководителя и заместителя: «Сотрудник» в каждой строке
                повторял бы название столбца и ничего бы не различал.
              -->
              <td>
                <ul v-if="row.departments?.length" class="units">
                  <li v-for="unit in row.departments" :key="unit.id" class="units__item">
                    <span>{{ unit.name }}</span>
                    <span v-if="unit.role !== 'member'" class="muted">
                      {{ unit.role_label }}
                    </span>
                  </li>
                </ul>
                <span v-else class="muted">Не в структуре</span>
              </td>

              <td>
                <!-- Уволенный не спорит об уровне доступа: пока он не в строю,
                     ни то, ни другое ничего ему не открывает. -->
                <span v-if="row.dismissed_at" class="badge badge--warning">
                  {{ dismissalLabel(row) }}
                </span>

                <template v-else>
                  <!-- Lime marks the rare standing, not the ordinary one: it is
                       what tells you at a glance who runs the place. -->
                  <span class="badge" :class="{ 'badge--highlight': row.level !== 'user' }">
                    {{ row.level_label }}
                  </span>
                  <span v-if="row.level === 'user'" class="muted permissions-count">
                    {{ permissionsLabel(row) }}
                  </span>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <nav v-if="lastPage > 1" class="pager" aria-label="Страницы списка сотрудников">
        <button
          type="button"
          class="button-secondary button-sm"
          :disabled="currentPage <= 1 || pending"
          @click="goToPage(currentPage - 1)"
        >
          ← Назад
        </button>

        <span class="pager__position" aria-live="polite">
          Страница {{ currentPage }} из {{ lastPage }}
        </span>

        <button
          type="button"
          class="button-secondary button-sm"
          :disabled="currentPage >= lastPage || pending"
          @click="goToPage(currentPage + 1)"
        >
          Вперёд →
        </button>
      </nav>
    </template>
  </section>
</template>

<style scoped>
.page-header {
  margin-bottom: 1.5rem;
}

.page-header__row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

.page-header__tools {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-top: 1rem;
}

.search {
  width: auto;
  min-width: 15rem;
  flex: 0 1 24rem;
}

.page-header h1 {
  margin: 0 0 0.25rem;
  font-size: 1.5rem;
}

.muted {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.table-wrap {
  overflow-x: auto;
  background: var(--color-surface);
  border-radius: var(--radius);
}

/* Прежний ответ, пока идёт следующий: видно, что он уже не свеж, но список
   остаётся читаемым и не прыгает. */
.table-wrap--stale {
  opacity: 0.6;
  transition: opacity 0.15s ease;
}

table {
  width: 100%;
  border-collapse: collapse;
}

th,
td {
  padding: 0.85rem 1rem;
  text-align: left;
  /* Middle, so a one-line cell lines up with the two-line name beside it. */
  vertical-align: middle;
  border-bottom: 1px solid var(--color-border);
}

th {
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-muted);
}

tbody tr:last-child td {
  border-bottom: none;
}

/* Имя — вход в карточку: подчёркивание появляется под указателем, чтобы
   таблица не рябила линиями в спокойном состоянии. */
.user__name {
  display: inline-block;
  font-weight: 500;
  color: inherit;
  text-decoration: none;
}

.user__name:hover {
  text-decoration: underline;
}

.units {
  margin: 0;
  padding: 0;
  list-style: none;
}

.units__item {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.4rem;
  font-size: 0.92rem;
}

.units__item + .units__item {
  margin-top: 0.25rem;
}

.permissions-count {
  margin-left: 0.5rem;
}

.pager {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  margin-top: 1.5rem;
}

.pager__position {
  color: var(--color-text-muted);
  font-size: 0.88rem;
  font-variant-numeric: tabular-nums;
  /* Fixed enough not to shuffle the buttons as the numbers grow. */
  min-width: 9rem;
  text-align: center;
}

/*
 * Уволенный из списка не пропадает — он здесь ради возвращения в строй, — но и
 * не спорит за внимание с работающими: приглушено имя, а метка остаётся в
 * полную силу.
 */
.user--dismissed .user__name {
  color: var(--color-text-muted);
}

@media (max-width: 48rem) {
  .page-header__row {
    flex-direction: column;
  }

  .page-header__tools {
    flex-direction: column;
    align-items: stretch;
    gap: 0.5rem;
  }

  .search {
    flex: 1 1 auto;
    width: 100%;
    min-width: 0;
  }

  th,
  td {
    padding: 0.6rem 0.7rem;
  }
}
</style>
