<script setup lang="ts">
import { messageFromError } from '~/composables/useAuth'
import type { User } from '~/types/auth'
import { formatDate } from '~/utils/numbers'
import { maskPhone } from '~/utils/phone'

definePageMeta({ middleware: 'auth', permission: 'users.view' })
useHead({ title: 'Сотрудники' })

const { fetchUsers, restoreUser } = useAdminApi()
const { can, isSuperAdmin } = useAuth()
const route = useRoute()
const router = useRouter()

const canManage = computed(() => can('users.manage'))

const search = ref(typeof route.query.search === 'string' ? route.query.search : '')
const page = ref(pageFromQuery(route.query.page))

/**
 * Удалённые записи вместо живых.
 *
 * Тем же списком, а не отдельным экраном: столбцы те же, и второй экран с
 * теми же столбцами разошёлся бы с первым на первой правке. Видит их один
 * суперадминистратор — он их и возвращает; API проверяет то же самое.
 */
const showDeleted = ref(route.query.deleted === '1')

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
const { data, pending, error, refresh } = await useAsyncData(
  'staff.list',
  () => fetchUsers({
    search: term.value || undefined,
    page: page.value > 1 ? page.value : undefined,
    deleted: showDeleted.value || undefined,
  }),
  { watch: [term, page, showDeleted] },
)

// Найденное — свой, куда более короткий список: четвёртая страница всех
// сотрудников почти никогда не четвёртая страница поиска и обычно за её концом.
// Удалённых это касается тем более: их единицы.
watch([term, showDeleted], () => {
  page.value = 1
})

// Найденное, открытая страница и то, какой список открыт, остаются в адресе: из
// карточки сотрудника возвращаются к тому же списку, а не к его началу.
watchEffect(() => {
  router.replace({
    query: {
      ...(term.value ? { search: term.value } : {}),
      ...(page.value > 1 ? { page: String(page.value) } : {}),
      ...(showDeleted.value ? { deleted: '1' } : {}),
    },
  })
})

const errorMessage = ref<string | null>(null)

/** Какую запись возвращаем: по ней гаснет одна кнопка, а не весь список. */
const restoring = ref<number | null>(null)

/**
 * Вернуть удалённую запись.
 *
 * Без подтверждения: возвращение ничего не ломает и само отменяется удалением.
 * Запись возвращается уволенной — вернуть человека в строй решают в карточке.
 */
async function restore(person: User) {
  restoring.value = person.id
  errorMessage.value = null

  try {
    await restoreUser(person)
    await refresh()
  }
  catch (caught) {
    errorMessage.value = messageFromError(caught, 'Не удалось вернуть запись.')
  }
  finally {
    restoring.value = null
  }
}

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
 * «Удалён 22 октября 2026 · Петров Пётр» — метка в списке удалённых.
 *
 * Кем удалена, стоит рядом с датой: об этом спрашивают первым делом, а
 * спросить больше некого — удалённую карточку не открыть.
 */
function deletionLabel(user: User): string {
  return [
    user.deleted_at ? `Удалён ${formatDate(user.deleted_at)}` : 'Удалён',
    user.deleted_by,
  ].filter(Boolean).join(' · ')
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
  return showDeleted.value
    // Запись, а не сотрудник: удалённая карточка — это уже не человек в штате,
    // и называть её сотрудником значило бы считать её дважды.
    ? `${count} ${pluralise(count, 'запись', 'записи', 'записей')}`
    : `${count} ${pluralise(count, 'сотрудник', 'сотрудника', 'сотрудников')}`
}
</script>

<template>
  <section>
    <header class="page-header">
      <div class="page-header__row">
        <div>
          <h1>{{ showDeleted ? 'Удалённые записи' : 'Сотрудники' }}</h1>
          <p class="muted">
            {{ showDeleted
              ? 'Карточек этих людей в приложении нет, но в отчёте о движении персонала они считаются по-прежнему. Любую можно вернуть — она вернётся уволенной.'
              : canManage
                ? 'Откройте карточку, чтобы посмотреть человека, поправить его данные или отметить доступ.'
                : 'Только просмотр: у вас нет права управлять сотрудниками.' }}
          </p>
        </div>

        <NuxtLink v-if="canManage && !showDeleted" to="/staff/new" class="button-primary">
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

        <!-- Удалённые смотрит и возвращает один суперадминистратор: остальным
             убранного в приложении нет. -->
        <button
          v-if="isSuperAdmin"
          type="button"
          class="button-secondary button-sm"
          :aria-pressed="showDeleted"
          @click="showDeleted = !showDeleted"
        >
          {{ showDeleted ? 'К сотрудникам' : 'Удалённые' }}
        </button>

        <p v-if="!isFirstLoad && !error" class="muted" aria-live="polite">
          {{ term ? `Нашли ${foundLabel(total)}` : foundLabel(total) }}
        </p>
      </div>
    </header>

    <p v-if="errorMessage" class="auth-alert" role="alert">
      {{ errorMessage }}
    </p>

    <p v-if="isFirstLoad" class="muted">
      Загрузка…
    </p>

    <p v-else-if="error" class="auth-alert" role="alert">
      Не удалось загрузить сотрудников.
    </p>

    <UiEmptyState
      v-else-if="!users.length && showDeleted"
      :title="term ? 'Среди удалённых никого не нашли' : 'Удалённых записей нет'"
      :description="term
        ? 'Поиск идёт по фамилии, имени, отчеству и почте — проверьте написание.'
        : 'Удаляют редко, и до увольнения — никого.'"
    />

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
                <!-- У удалённой записи карточки нет: открывать нечего, и
                     ссылка вела бы на «такого сотрудника нет». -->
                <span v-if="showDeleted" class="user__name">
                  {{ row.name }}
                </span>
                <NuxtLink v-else :to="`/staff/${row.id}`" class="user__name">
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
                <!-- Удалённая запись ни прав, ни уровня доступа не отстаивает:
                     войти по ней нельзя. Вместо них — когда её убрали, кто и
                     кнопка вернуть. -->
                <div v-if="showDeleted" class="deleted">
                  <span class="badge badge--warning">
                    {{ deletionLabel(row) }}
                  </span>
                  <button
                    type="button"
                    class="button-secondary button-sm"
                    :disabled="restoring === row.id"
                    @click="restore(row)"
                  >
                    {{ restoring === row.id ? 'Возвращаем…' : 'Вернуть' }}
                  </button>
                </div>

                <!-- Уволенный не спорит об уровне доступа: пока он не в строю,
                     ни то, ни другое ничего ему не открывает. -->
                <span v-else-if="row.dismissed_at" class="badge badge--warning">
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

/* Метка и кнопка возврата — в строку, пока помещаются: на узком экране кнопка
   уходит под метку, а не сжимает её до многоточия. */
.deleted {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
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
