<script setup lang="ts">
import type { StaffLink, StaffQuery } from '~/types/analytics'

/**
 * Два способа вынести отчёт из системы — и оба под присмотром.
 *
 * Выгрузка отдаёт файл ровно того среза, что на экране. Ссылка отдаёт то же
 * самое тому, у кого нет учётной записи, но без единой фамилии: наружу уходят
 * цифры, а не люди.
 *
 * Оба способа спрятаны за кнопкой, а не разложены по шапке. Это не украшение:
 * выгрузка списка с фамилиями — действие, о котором остаётся запись в журнале, и
 * начинаться оно должно с осознанного нажатия, а не с кнопки, попавшейся под
 * руку рядом с фильтрами.
 */
const props = defineProps<{
  query: StaffQuery
  /** Есть ли вообще кого выгружать — у пустого среза и файл пуст. */
  people: number
}>()

const { downloadStaffReport, fetchStaffLinks, createStaffLink, revokeStaffLink } = useAnalyticsApi()

const open = ref(false)
const busy = ref(false)
const failure = ref<string | null>(null)

/* ---------- Выгрузка ---------- */

/** Со списком людей или только цифрами. Умолчание — цифры: их просят чаще. */
const withNames = ref(false)

async function download(format: 'xlsx' | 'csv'): Promise<void> {
  busy.value = true
  failure.value = null

  try {
    const blob = await downloadStaffReport(props.query, { format, names: withNames.value })

    save(blob, filename(format))
  }
  catch {
    failure.value = 'Не удалось собрать файл.'
  }
  finally {
    busy.value = false
  }
}

/**
 * Имя файла считается здесь, а не берётся из ответа.
 *
 * Заголовок `Content-Disposition` браузеру через CORS не виден, и читать его
 * пришлось бы, разрешив на сервере лишнее. Правило простое и повторяется на
 * обеих сторонах: период и признак списка.
 */
function filename(format: string): string {
  const from = props.query.from ?? 'period'
  const to = props.query.to ?? 'period'

  return `shtat-${from}-${to}${withNames.value ? '-spisok' : ''}.${format}`
}

function save(blob: Blob, name: string): void {
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')

  anchor.href = url
  anchor.download = name
  anchor.click()

  // Ссылка на объект держит файл в памяти вкладки, пока её не отпустят.
  URL.revokeObjectURL(url)
}

/* ---------- Ссылка наружу ---------- */

const links = ref<StaffLink[]>([])
const loadedLinks = ref(false)
const days = ref('14')
const fresh = ref<string | null>(null)
const copied = ref(false)

const DAYS = [
  { value: '7', label: '7 дней' },
  { value: '14', label: '14 дней' },
  { value: '30', label: '30 дней' },
  { value: '90', label: '90 дней' },
]

// Список ссылок приезжает, только когда панель раскрыли: держать его наготове
// ради кнопки, которую нажимают раз в квартал, незачем.
watch(open, async (isOpen) => {
  if (isOpen && !loadedLinks.value) {
    links.value = (await fetchStaffLinks()).data
    loadedLinks.value = true
  }
})

async function issue(): Promise<void> {
  busy.value = true
  failure.value = null

  try {
    const link = (await createStaffLink(props.query, Number(days.value))).data

    links.value = [link, ...links.value]
    fresh.value = link.token ? address(link.token) : null
    copied.value = false
  }
  catch {
    failure.value = 'Не удалось выдать ссылку.'
  }
  finally {
    busy.value = false
  }
}

function address(token: string): string {
  return `${window.location.origin}/shared/staff-report/${token}`
}

async function copy(): Promise<void> {
  if (!fresh.value) {
    return
  }

  await navigator.clipboard.writeText(fresh.value)
  copied.value = true
}

async function revoke(link: StaffLink): Promise<void> {
  const updated = (await revokeStaffLink(link.id)).data

  links.value = links.value.map(one => (one.id === link.id ? updated : one))

  if (!updated.live) {
    fresh.value = null
  }
}

function when(value: string | null): string {
  return value ? new Date(value).toLocaleDateString('ru-RU') : '—'
}
</script>

<template>
  <div class="share">
    <button
      type="button"
      class="button-secondary button-sm"
      :aria-expanded="open"
      @click="open = !open"
    >
      Выгрузить или поделиться
    </button>

    <div v-if="open" class="share__panel card card--raised">
      <p v-if="failure" class="alert alert--danger" role="alert">
        {{ failure }}
      </p>

      <section class="share__section">
        <h2 class="share__title">
          Файлом
        </h2>
        <p class="share__note">
          Выгружается тот же срез, что на экране.
        </p>

        <label class="share__check">
          <input v-model="withNames" type="checkbox">
          <span>
            Со списком людей
            <!-- Сказано прямо, до нажатия: человек должен знать, что уносит
                 персональные данные и что об этом останется запись. -->
            <span class="share__note">персональные данные, выгрузка попадёт в журнал</span>
          </span>
        </label>

        <div class="share__row">
          <button
            type="button"
            class="button-secondary button-sm"
            :disabled="busy"
            @click="download('xlsx')"
          >
            XLSX
          </button>
          <button
            type="button"
            class="button-secondary button-sm"
            :disabled="busy"
            @click="download('csv')"
          >
            CSV
          </button>
          <span class="share__note">
            {{ withNames
              ? `XLSX — весь отчёт со списком, CSV — только список (${people} чел.)`
              : 'XLSX — весь отчёт по листам, CSV — разрез по подразделениям' }}
          </span>
        </div>
      </section>

      <section class="share__section">
        <h2 class="share__title">
          Ссылкой наружу
        </h2>
        <p class="share__note">
          Открывается без входа в систему и показывает только цифры — ни одной фамилии.
        </p>

        <div class="share__row">
          <UiSelect v-model="days" :options="DAYS" />
          <button type="button" class="button-primary button-sm" :disabled="busy" @click="issue">
            Выдать ссылку
          </button>
        </div>

        <!-- Адрес показывается один раз. Дальше в списке остаётся хвост: это
             секрет, и хранить его на экране незачем. -->
        <div v-if="fresh" class="share__fresh">
          <code class="share__address">{{ fresh }}</code>
          <button type="button" class="button-secondary button-sm" @click="copy">
            {{ copied ? 'Скопировано' : 'Скопировать' }}
          </button>
        </div>

        <table v-if="links.length" class="share__links data-table">
          <thead>
            <tr>
              <th>Ссылка</th>
              <th>Период</th>
              <th>Действует до</th>
              <th />
            </tr>
          </thead>
          <tbody>
            <tr v-for="link in links" :key="link.id" :class="{ 'share__dead': !link.live }">
              <td>
                <code>{{ link.hint }}</code>
                <span v-if="link.created_by" class="share__note">{{ link.created_by }}</span>
              </td>
              <td class="data-table__number" data-label="Период">
                {{ when(link.period.from) }} — {{ when(link.period.to) }}
              </td>
              <td class="data-table__number" data-label="Действует до">
                {{ when(link.expires_at) }}
                <span v-if="link.revoked_at" class="badge badge--warning">отозвана</span>
                <span v-else-if="!link.live" class="badge">истекла</span>
              </td>
              <td>
                <button
                  v-if="link.live"
                  type="button"
                  class="button-ghost button-sm"
                  @click="revoke(link)"
                >
                  Отозвать
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </section>
    </div>
  </div>
</template>

<style scoped>
.share {
  position: relative;
}

.share__panel {
  /* Над отчётом, а не в потоке: панель раскрывается поверх плиток и не должна
     сдвигать вниз всё, ради чего страницу открыли. */
  position: absolute;
  z-index: 20;
  top: calc(100% + 0.5rem);
  right: 0;
  display: flex;
  width: min(34rem, calc(100vw - 2rem));
  flex-direction: column;
  gap: 1.35rem;
  padding: 1.15rem 1.25rem;
  box-shadow: var(--shadow-lg);
}

/* Разделитель между двумя способами: они про разное — файл себе и ссылка
   чужому, — и сливаться в один список не должны. */
.share__section + .share__section {
  padding-top: 1.1rem;
  border-top: 1px solid var(--color-border);
}

/*
 * Телефон: панель не висит над страницей, а раскрывается под кнопкой.
 *
 * Всплывающее окно шириной в экран — это и есть экран, только с содержимым под
 * ним, которое ничего не сообщает, но перехватывает нажатия. Обычный блок,
 * раздвигающий страницу, на телефоне честнее: его прокручивают вместе со всем
 * остальным и закрывают той же кнопкой, которой открыли.
 */
@media (max-width: 40rem) {
  .share {
    width: 100%;
  }

  .share__panel {
    position: static;
    width: 100%;
    margin-top: 0.5rem;
  }
}

.share__section {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.share__title {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 600;
}

.share__note {
  display: block;
  color: var(--color-text-muted);
  font-size: 0.78rem;
}

.share__row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

/* Пояснение под кнопками, а не рядом: втиснутое в ту же строку, оно ломается
   на три обрывка и читается хуже, чем не читается вовсе. */
.share__row .share__note {
  flex-basis: 100%;
}

.share__check {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  font-size: 0.88rem;
  cursor: pointer;
}

.share__fresh {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 0.6rem;
  border-radius: var(--radius-sm);
  background: var(--color-surface-sunken, var(--color-surface));
}

.share__address {
  /* Адрес длинный и обязан переноситься: уехав за край, он превращается в
     ссылку, которую нельзя выделить целиком. */
  overflow-wrap: anywhere;
  flex: 1;
  min-width: 0;
  font-size: 0.78rem;
}

/* Список ссылок мельче отчётных таблиц: это служебная запись, а не то, ради
   чего панель открыли. */
.share__links {
  font-size: 0.82rem;
}

.share__links th {
  font-size: 0.72rem;
  white-space: nowrap;
}

/* Мёртвая ссылка остаётся в списке, но не спорит за внимание с живыми. */
.share__dead {
  opacity: 0.55;
}
</style>
