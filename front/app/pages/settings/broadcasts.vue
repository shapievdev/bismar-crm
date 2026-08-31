<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { Broadcast, BroadcastAudienceKind, Department, DepartmentPerson, Group } from '~/types/structure'
import { formatDate } from '~/utils/numbers'

/**
 * Рассылки уведомлений.
 *
 * Отправляет администратор: телефон звонит у всей компании, и права,
 * отмеченного галочкой, для такого мало. Экран показывает и историю — уведомление
 * нельзя открыть заново, и без записи никто потом не скажет, что разослали.
 */
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Рассылки' })

const { isAdmin } = useAuth()
const { fetchBroadcasts, sendBroadcast, fetchStructure, searchDepartmentCandidates } = useStructureApi()
const { fetchGroups } = useGroupsApi()

const { data, pending, error, refresh } = await useAsyncData('broadcasts', async () => {
  const [history, structure, groups] = await Promise.all([
    fetchBroadcasts(),
    fetchStructure(),
    fetchGroups(),
  ])

  return { history: history.data, roots: structure.data, groups: groups.data }
})

const history = computed(() => data.value?.history ?? [])

/**
 * Отделы одним списком с отступами: рассылку выбирают по названию, а вложенность
 * подсказывает, чей это отдел. Дерево в выпадающем списке не нарисовать.
 */
const departmentOptions = computed(() => {
  const options: { value: string, label: string }[] = []

  const walk = (nodes: Department[], depth: number) => {
    for (const node of nodes) {
      options.push({
        value: String(node.id),
        label: `${'— '.repeat(depth)}${node.name}`,
      })
      walk(node.children, depth + 1)
    }
  }

  walk(data.value?.roots ?? [], 0)

  return options
})

/**
 * Группы — простым списком: вложенности у них нет, и отступы подсказывать
 * нечему. Пустые тоже показываем: пустая группа — повод её наполнить, а не
 * прятать, но сколько в ней людей, видно сразу.
 */
const groupOptions = computed(() => (data.value?.groups ?? []).map((group: Group) => ({
  value: String(group.id),
  label: `${group.name} — ${group.people_count} ${pluralise(group.people_count, 'человек', 'человека', 'человек')}`,
})))

/* ---------- Письмо ---------- */

const form = reactive({
  title: '',
  body: '',
  url: '',
  audience: 'everyone' as BroadcastAudienceKind,
  department: '',
  group: '',
})

const audiences = [
  { value: 'everyone', label: 'Всем сотрудникам', hint: 'Всем, кто работает и подписался на уведомления' },
  { value: 'selected', label: 'Выбранным', hint: 'Тем, кого назовёте по имени' },
  { value: 'department', label: 'Отделу', hint: 'Отделу вместе со всеми его подотделами' },
  { value: 'group', label: 'Группе', hint: 'Списку людей, собранному вручную' },
] as const

/** Названные поимённо: список набирается поиском и держится до отправки. */
const chosen = ref<DepartmentPerson[]>([])

const people = useDebouncedSearch<DepartmentPerson>(
  async term => (await searchDepartmentCandidates(term)).data,
)

function choose(person: DepartmentPerson) {
  people.clear()

  if (!chosen.value.some(one => one.id === person.id)) {
    chosen.value = [...chosen.value, person]
  }
}

function drop(id: number) {
  chosen.value = chosen.value.filter(one => one.id !== id)
}

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSending = ref(false)
const sent = ref<{ recipients: number, devices: number } | null>(null)

const ready = computed(() => {
  if (form.title.trim() === '' || form.body.trim() === '') {
    return false
  }

  if (form.audience === 'selected') {
    return chosen.value.length > 0
  }

  if (form.audience === 'department') {
    return form.department !== ''
  }

  return form.audience !== 'group' || form.group !== ''
})

async function send() {
  isSending.value = true
  errors.value = {}
  generalError.value = null
  sent.value = null

  try {
    const { data: broadcast } = await sendBroadcast({
      title: form.title.trim(),
      body: form.body.trim(),
      url: form.url.trim() || null,
      audience: form.audience,
      user_ids: form.audience === 'selected' ? chosen.value.map(one => one.id) : undefined,
      department_id: form.audience === 'department' ? Number(form.department) : undefined,
      group_id: form.audience === 'group' ? Number(form.group) : undefined,
    })

    sent.value = { recipients: broadcast.recipients_count, devices: broadcast.devices_count }

    form.title = ''
    form.body = ''
    form.url = ''
    chosen.value = []

    await refresh()
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      errors.value = caught.errors
    }
    else {
      generalError.value = (caught as { data?: { message?: string } }).data?.message
        ?? 'Не удалось отправить рассылку.'
    }
  }
  finally {
    isSending.value = false
  }
}

/**
 * Кому ушла рассылка. Удалённые с тех пор отдел и группа названия не имеют —
 * тогда остаётся «Отделу», «Группе»: лучше, чем стёртая история.
 */
function sentTo(one: Broadcast): string {
  if (one.audience === 'department' && one.department) {
    return `Отделу «${one.department}»`
  }

  if (one.audience === 'group' && one.group) {
    return `Группе «${one.group}»`
  }

  return one.audience_label
}

function when(value: string | null): string {
  if (!value) {
    return ''
  }

  const at = new Date(value)

  return `${formatDate(value)}, ${at.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}`
}
</script>

<template>
  <section>
    <header class="head">
      <div>
        <h1 class="page-title">
          Рассылки
        </h1>
        <p class="page-subtitle">
          Уведомление придёт на телефон и компьютер — даже когда приложение закрыто. Дойдёт до тех, кто включил уведомления у себя в профиле.
        </p>
      </div>
    </header>

    <p v-if="!isAdmin" class="alert alert--danger" role="alert">
      Рассылки отправляет администратор: телефон звонит у всей компании, и права для этого мало.
    </p>

    <template v-else>
      <p v-if="generalError" class="alert alert--danger" role="alert">
        {{ generalError }}
      </p>

      <p v-if="sent" class="alert alert--success" role="status">
        Отправлено: {{ sent.recipients }} {{ pluralise(sent.recipients, 'человек', 'человека', 'человек') }},
        {{ sent.devices }} {{ pluralise(sent.devices, 'устройство', 'устройства', 'устройств') }}.
        <template v-if="sent.devices === 0">
          Пока никто не включил уведомления — само сообщение никуда не денется, но и не прозвенит.
        </template>
      </p>

      <form class="card panel" novalidate @submit.prevent="send">
        <div class="field">
          <label class="field-label" for="title">Заголовок</label>
          <input id="title" v-model="form.title" class="input" maxlength="120" placeholder="Склад закрыт до понедельника">
          <p v-if="errors.title?.length" class="field-error">
            {{ errors.title[0] }}
          </p>
        </div>

        <div class="field">
          <label class="field-label" for="body">Текст</label>
          <textarea id="body" v-model="form.body" class="textarea" rows="3" maxlength="500" placeholder="Приёмка не работает, заявки принимаем в мессенджере." />
          <p class="faint">
            На экране видно две-три строки — остальное система обрежет.
          </p>
          <p v-if="errors.body?.length" class="field-error">
            {{ errors.body[0] }}
          </p>
        </div>

        <div class="field">
          <label class="field-label" for="url">Куда открыть — если нужно</label>
          <input id="url" v-model="form.url" class="input" placeholder="/news">
          <p class="faint">
            Путь внутри приложения: например, <code>/news</code> или <code>/lms</code>. Пусто — откроется главная.
          </p>
          <p v-if="errors.url?.length" class="field-error">
            {{ errors.url[0] }}
          </p>
        </div>

        <div class="field">
          <span class="field-label">Кому</span>

          <div class="audience">
            <label
              v-for="option in audiences"
              :key="option.value"
              class="audience__option"
              :class="{ 'audience__option--on': form.audience === option.value }"
            >
              <input v-model="form.audience" type="radio" :value="option.value">
              <span>
                <span class="audience__name">{{ option.label }}</span>
                <span class="faint">{{ option.hint }}</span>
              </span>
            </label>
          </div>
        </div>

        <!-- Названные поимённо. -->
        <div v-if="form.audience === 'selected'" class="field">
          <label class="field-label" for="person">Найдите сотрудника</label>
          <input
            id="person"
            v-model="people.query.value"
            class="input"
            type="search"
            autocomplete="off"
            placeholder="Фамилия или почта"
          >

          <p v-if="people.isSearching.value" class="faint">
            Ищем…
          </p>
          <p v-else-if="people.query.value.trim() && !people.results.value.length" class="faint">
            Никого не нашли.
          </p>

          <ul v-else-if="people.results.value.length" class="found">
            <li v-for="person in people.results.value" :key="person.id">
              <button type="button" class="found__item" @click="choose(person)">
                <UserAvatar :name="person.name" :src="person.avatar_url" :size="28" />
                <span class="found__body">
                  <span class="found__name">{{ person.name }}</span>
                  <span class="faint">{{ person.job_title ?? 'Должность не указана' }}</span>
                </span>
              </button>
            </li>
          </ul>

          <ul v-if="chosen.length" class="chosen">
            <li v-for="person in chosen" :key="person.id" class="chosen__one">
              <UserAvatar :name="person.name" :src="person.avatar_url" :size="22" />
              <span>{{ person.short_name }}</span>
              <button type="button" class="chosen__drop" :aria-label="`Убрать ${person.name}`" @click="drop(person.id)">
                ✕
              </button>
            </li>
          </ul>

          <p v-if="errors.user_ids?.length" class="field-error">
            {{ errors.user_ids[0] }}
          </p>
        </div>

        <!-- Отдел вместе с подотделами. -->
        <div v-if="form.audience === 'department'" class="field">
          <label class="field-label" for="department">Отдел</label>
          <UiSelect
            id="department"
            v-model="form.department"
            :options="departmentOptions"
            placeholder="Выберите отдел"
          />
          <p class="faint">
            Уведомление получат и подотделы выбранного.
          </p>
          <p v-if="errors.department_id?.length" class="field-error">
            {{ errors.department_id[0] }}
          </p>
        </div>

        <!-- Группа — ровно те, кого в неё внесли. -->
        <div v-if="form.audience === 'group'" class="field">
          <label class="field-label" for="group">Группа</label>
          <UiSelect
            id="group"
            v-model="form.group"
            :options="groupOptions"
            placeholder="Выберите группу"
          />
          <p class="faint">
            Состав группы правят в разделе «Сотрудники» — вкладка «Группы».
          </p>
          <p v-if="errors.group_id?.length" class="field-error">
            {{ errors.group_id[0] }}
          </p>
        </div>

        <!-- Как это будет выглядеть: заголовок и текст в том же порядке, в
             каком их покажет телефон. -->
        <div v-if="form.title.trim() || form.body.trim()" class="preview">
          <span class="preview__label">Как увидят</span>
          <div class="preview__card">
            <span class="preview__app">Bismar CRM</span>
            <span class="preview__title">{{ form.title || 'Заголовок' }}</span>
            <span class="preview__body">{{ form.body || 'Текст уведомления' }}</span>
          </div>
        </div>

        <div class="panel__actions">
          <button type="submit" class="button-primary" :disabled="isSending || !ready">
            {{ isSending ? 'Отправляем…' : 'Отправить' }}
          </button>
          <span class="faint">Отменить отправку нельзя — уведомление уходит сразу.</span>
        </div>
      </form>

      <section class="card panel">
        <h2 class="panel__title">
          Что уже рассылали
        </h2>

        <p v-if="pending" class="faint">
          Загрузка…
        </p>
        <p v-else-if="error" class="alert alert--danger" role="alert">
          Не удалось загрузить историю.
        </p>
        <UiEmptyState
          v-else-if="!history.length"
          title="Рассылок ещё не было"
          description="Первая появится здесь сразу после отправки."
        />

        <ul v-else class="sent">
          <li v-for="one in history" :key="one.id" class="sent__one">
            <div class="sent__head">
              <span class="sent__title">{{ one.title }}</span>
              <span class="faint">{{ when(one.sent_at) }}</span>
            </div>
            <p class="sent__body">
              {{ one.body }}
            </p>
            <p class="faint">
              {{ sentTo(one) }}
              · {{ one.recipients_count }} {{ pluralise(one.recipients_count, 'человек', 'человека', 'человек') }}
              · {{ one.devices_count }} {{ pluralise(one.devices_count, 'устройство', 'устройства', 'устройств') }}
              <template v-if="one.author"> · {{ one.author }}</template>
            </p>
          </li>
        </ul>
      </section>
    </template>
  </section>
</template>

<style scoped>
.head {
  margin-bottom: 1.75rem;
}

.panel {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.4rem 1.5rem;
  margin-bottom: 1rem;
}

.panel__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
}

.panel__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
}

/* Кому — три случая карточками: выбор здесь важнее текста рядом. */
.audience {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
  gap: 0.6rem;
}

.audience__option {
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
  padding: 0.75rem 0.9rem;
  border: 1px solid transparent;
  border-radius: var(--radius);
  background: var(--color-surface-raised);
  cursor: pointer;
  transition: border-color 0.15s ease;
}

.audience__option:hover {
  border-color: var(--color-border-strong);
}

.audience__option--on {
  border-color: var(--color-highlight-strong);
  background: color-mix(in srgb, var(--color-highlight) 12%, var(--color-surface-raised));
}

.audience__option > span {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
}

.audience__name {
  font-size: 0.94rem;
  font-weight: 550;
}

.found {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.found__item {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  width: 100%;
  padding: 0.5rem 0.6rem;
  border: 0;
  border-radius: var(--radius);
  background: transparent;
  color: inherit;
  font: inherit;
  cursor: pointer;
}

.found__item:hover {
  background: var(--color-surface-sunken);
}

.found__body {
  display: flex;
  flex-direction: column;
  gap: 0.05rem;
  min-width: 0;
  text-align: left;
  font-size: 0.9rem;
}

.found__name {
  font-weight: 550;
}

.chosen {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin: 0.2rem 0 0;
  padding: 0;
  list-style: none;
}

.chosen__one {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.25rem 0.6rem 0.25rem 0.3rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-sunken);
  font-size: 0.85rem;
}

.chosen__drop {
  border: 0;
  background: transparent;
  color: var(--color-text-muted);
  font-size: 0.8rem;
  line-height: 1;
  cursor: pointer;
}

.chosen__drop:hover {
  color: var(--color-text);
}

/*
 * Предпросмотр: тёмная карточка с именем приложения сверху — так уведомление и
 * выглядит в системе. Не для красоты: короткий заголовок и длинный текст в
 * форме кажутся равными, а на экране телефона это две разные вещи.
 */
.preview {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.preview__label {
  color: var(--color-text-muted);
  font-size: 0.78rem;
}

.preview__card {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  max-width: 26rem;
  padding: 0.8rem 1rem;
  border-radius: var(--radius);
  background: var(--color-accent);
  color: var(--color-accent-text);
}

.preview__app {
  font-size: 0.72rem;
  opacity: 0.65;
}

.preview__title {
  font-weight: 600;
}

.preview__body {
  font-size: 0.9rem;
  opacity: 0.85;
  /* Две строки, как на экране: третью система обрежет сама. */
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  overflow: hidden;
}

.sent {
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.sent__one {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  padding-bottom: 0.9rem;
  border-bottom: 1px solid var(--color-border);
}

.sent__one:last-child {
  padding-bottom: 0;
  border-bottom: 0;
}

.sent__head {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
}

.sent__title {
  font-weight: 550;
}

.sent__body {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
}
</style>
