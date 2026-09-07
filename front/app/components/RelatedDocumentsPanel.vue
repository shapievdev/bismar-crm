<script setup lang="ts">
import type { MaterialSection, RegulationLink } from '~/types/lms'

/**
 * Соседние материалы «рядом по теме»: показать, убрать, найти и добавить.
 *
 * Устроена как панель людей (CoursePeoplePanel) и по той же причине: поиск с
 * задержкой, ответ только на последний запрос, разметка списка. Отдельная,
 * а не общая с ней: у человека лицо и почта, у документа — название и
 * категория, и панель «на всё» свелась бы к ветвлениям в каждой строке.
 *
 * Ничего не решает сама: показывает то, что дали, и сообщает о нажатиях.
 */
const props = withDefaults(defineProps<{
  /** Раздел, в котором живут соседи: связывать можно только внутри своего. */
  section: MaterialSection
  documents: RegulationLink[]
  isLoading: boolean
  isSaving: boolean
  errorMessage?: string | null
  search: (term: string) => Promise<RegulationLink[]>
  /** Заголовок и пояснение: панель ведёт два разных списка — см. MaterialEditor. */
  title?: string
  note?: string
  emptyNote?: string
  /**
   * Порядок задаётся руками — тогда у строк появляются стрелки. У соседей его
   * нет: они равны между собой, и переставлять там нечего.
   */
  ordered?: boolean
}>(), {
  title: 'Рядом по теме',
  emptyNote: 'Соседей пока нет.',
  ordered: false,
})

const emit = defineEmits<{
  add: [document: RegulationLink]
  remove: [document: RegulationLink]
  move: [document: RegulationLink, delta: number]
}>()

const copy = useMaterialSection(props.section)

function add(document: RegulationLink) {
  query.value = ''
  candidates.value = []

  if (!props.documents.some(one => one.id === document.id)) {
    emit('add', document)
  }
}

/* ---------- Поиск ---------- */

const query = ref('')
const candidates = ref<RegulationLink[]>([])
const isSearching = ref(false)

let searchTimer: ReturnType<typeof setTimeout> | undefined
let searchToken = 0

/**
 * Ищет с задержкой и показывает только ответ на последний запрос: набранное
 * целиком приходит раньше, чем ответ на первую букву, и без этого список
 * подсказок мигал бы результатами уже стёртого слова.
 */
watch(query, (value) => {
  clearTimeout(searchTimer)

  const term = value.trim()

  if (term === '') {
    candidates.value = []
    isSearching.value = false

    return
  }

  isSearching.value = true

  searchTimer = setTimeout(async () => {
    const token = ++searchToken

    try {
      const found = await props.search(term)

      if (token === searchToken) {
        candidates.value = found
      }
    }
    catch {
      if (token === searchToken) {
        candidates.value = []
      }
    }
    finally {
      if (token === searchToken) {
        isSearching.value = false
      }
    }
  }, 250)
})

onBeforeUnmount(() => clearTimeout(searchTimer))

const inputId = useId()
</script>

<template>
  <section class="panel card">
    <header class="panel__header">
      <h2 class="panel__title">
        {{ title }}
      </h2>
      <span v-if="isSaving" class="panel__status">Сохраняем…</span>
    </header>

    <p class="panel__note">
      <template v-if="note">{{ note }}</template>
      <template v-else>
        Что стоит прочитать вместе с этим. Связь взаимная: этот
        {{ copy.materialLabel.toLowerCase() }} появится и в их списке.
      </template>
    </p>

    <p v-if="errorMessage" class="alert alert--danger" role="alert">
      {{ errorMessage }}
    </p>

    <ul class="documents">
      <li v-for="(document, index) in documents" :key="document.id" class="documents__item">
        <!-- Адрес приходит с сервера: «частым вопросом» бывает материал чужого
             раздела, и собрать ссылку из своего здесь больше нельзя. -->
        <NuxtLink :to="document.path" class="documents__link">
          {{ document.title }}
          <span v-if="document.category" class="documents__where">{{ document.category }}</span>
        </NuxtLink>

        <span v-if="!document.is_published" class="badge badge--warning">Черновик</span>
        <span v-else-if="document.is_private" class="badge">Закрыт</span>

        <!-- Порядок правят стрелками, а не перетаскиванием: строк в списке
             единицы, а перетаскивание нужно ещё и с клавиатуры. -->
        <template v-if="ordered">
          <button
            type="button"
            class="documents__move"
            :disabled="isSaving || index === 0"
            aria-label="Выше"
            @click="emit('move', document, -1)"
          >
            ↑
          </button>
          <button
            type="button"
            class="documents__move"
            :disabled="isSaving || index === documents.length - 1"
            aria-label="Ниже"
            @click="emit('move', document, 1)"
          >
            ↓
          </button>
        </template>

        <button type="button" class="documents__remove" :disabled="isSaving" @click="emit('remove', document)">
          Убрать
        </button>
      </li>
    </ul>

    <p v-if="!isLoading && documents.length === 0" class="panel__note">
      {{ emptyNote }}
    </p>

    <div class="finder">
      <div class="finder__row">
        <label :for="inputId">Добавить {{ copy.materialLabel.toLowerCase() }}</label>
        <input
          :id="inputId"
          v-model="query"
          type="search"
          autocomplete="off"
          placeholder="Название"
        >
      </div>

      <ul v-if="candidates.length" class="finder__results">
        <li v-for="document in candidates" :key="document.id">
          <button type="button" class="finder__option" @click="add(document)">
            <span class="documents__link">
              {{ document.title }}
              <span v-if="document.category" class="documents__where">{{ document.category }}</span>
            </span>
            <span v-if="!document.is_published" class="badge badge--warning">Черновик</span>
            <span v-else-if="document.is_private" class="badge">Закрыт</span>
          </button>
        </li>
      </ul>

      <p v-else-if="query.trim() && !isSearching" class="panel__note">
        Ничего не нашли.
      </p>
    </div>
  </section>
</template>

<style scoped>
.panel {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  padding: 1rem 1.15rem 1.05rem;
}

.panel__header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
}

.panel__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 500;
}

.panel__status,
.panel__note {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.documents {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.documents__item {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.3rem 0.1rem;
  border-bottom: 1px solid var(--color-border-subtle, var(--color-border));
}

.documents__item:last-child {
  border-bottom: none;
}

.documents__link {
  display: flex;
  flex-direction: column;
  min-width: 0;
  color: inherit;
  font-size: 0.92rem;
  line-height: 1.25;
  text-decoration: none;
}

a.documents__link:hover {
  text-decoration: underline;
}

.documents__where {
  color: var(--color-text-faint);
  font-size: 0.8rem;
  overflow: hidden;
  text-overflow: ellipsis;
}

.documents__remove {
  margin-left: auto;
  padding: 0.25rem 0.6rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill);
  background: transparent;
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.82rem;
  cursor: pointer;
}

.documents__remove:hover:not(:disabled) {
  border-color: var(--color-danger);
  color: var(--color-danger);
}

/* Стрелки порядка. Круглые и без подписи: они стоят парой у каждой строки, и
   слова «выше» и «ниже» весили бы больше самой строки. */
.documents__move {
  display: inline-grid;
  place-items: center;
  width: 1.75rem;
  height: 1.75rem;
  flex-shrink: 0;
  padding: 0;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill);
  background: transparent;
  color: var(--color-text-muted);
  font: inherit;
  line-height: 1;
  cursor: pointer;
}

.documents__move:hover:not(:disabled) {
  border-color: var(--color-border-strong);
  color: var(--color-text);
}

.documents__move:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.finder {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.finder__row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.6rem;
}

.finder label {
  flex-shrink: 0;
  font-size: 0.875rem;
  font-weight: 500;
}

.finder input {
  flex: 1 1 12rem;
  min-width: 0;
  max-width: 22rem;
  padding: 0.45rem 0.65rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
}

.finder__results {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.finder__option {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  width: 100%;
  padding: 0.45rem 0.5rem;
  border: none;
  border-radius: var(--radius);
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.finder__option:hover {
  background: var(--color-surface-sunken);
}
</style>
