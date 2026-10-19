<script setup lang="ts">
import type { SelectOption } from '~/components/ui/Select.vue'
import type { FavouriteItem } from '~/types/lms'

/**
 * Избранное: то, что человек отложил себе сам.
 *
 * Рядом с «Моим планом», но это его противоположность: план назначают, а сюда
 * человек складывает сам — справочник, который спрашивают каждый день, урок, к
 * которому придётся вернуться, документ, в который заглядывают при клиенте.
 *
 * Виды лежат вперемешку, как в корзине: вопрос у человека один — «что я
 * откладывал», — и помнить, курсом это было или справочником, чтобы найти
 * своё, ему незачем. Разложить обратно по видам можно отбором сверху.
 *
 * Отбор и сортировка считаются здесь, а не на сервере, и это не экономия
 * запроса: список личный и короткий, и ждать ответа ради перестановки по
 * алфавиту значит ждать там, где ждать нечего. С сервера он приходит в том
 * порядке, который зависит от базы, — отложенным последним вперёд.
 */
definePageMeta({
  middleware: 'auth',
  // Любой из трёх разделов: у продавца, которому открыты одни справочники,
  // избранное своё и он имеет на него такое же право.
  permission: ['courses.view', 'documents.view', 'handbooks.view'],
})

useHead({ title: 'Избранное' })

const { fetchFavourites } = useFavouritesApi()

const { data, refresh } = await useAsyncData('lms.favourites', () => fetchFavourites())

const items = computed<FavouriteItem[]>(() => data.value?.data ?? [])

/* ---------- Отбор по виду ---------- */

/**
 * Отбор по разделу, а не по виду: человек ищет «тот справочник», а не
 * «материал вида regulation». Курсы и уроки при этом разведены — они живут в
 * одном разделе, но ищут их по-разному.
 */
type Scope = 'all' | 'courses' | 'lessons' | 'documents' | 'handbooks'

const scope = ref<Scope>('all')

/** Какому отбору отвечает строка. */
function scopeOf(item: FavouriteItem): Scope {
  if (item.kind === 'lesson') {
    return 'lessons'
  }

  if (item.kind === 'course') {
    return 'courses'
  }

  return item.section as 'documents' | 'handbooks'
}

/**
 * Кнопки отбора — только по тем видам, что у человека есть.
 *
 * Пустая кнопка обещала бы отложенное, которого нет, и на неё нажали бы
 * первым делом. По той же причине весь ряд пропадает, когда вид остался один:
 * выбирать там не из чего.
 */
const scopes = computed<{ value: Scope, label: string, count: number }[]>(() => {
  const counts = new Map<Scope, number>()

  for (const item of items.value) {
    const key = scopeOf(item)

    counts.set(key, (counts.get(key) ?? 0) + 1)
  }

  const named: { value: Scope, label: string }[] = [
    { value: 'courses', label: 'Курсы' },
    { value: 'lessons', label: 'Уроки' },
    { value: 'documents', label: 'Документы' },
    { value: 'handbooks', label: 'Справочники' },
  ]

  const present = named
    .filter(one => counts.has(one.value))
    .map(one => ({ ...one, count: counts.get(one.value) ?? 0 }))

  if (present.length < 2) {
    return []
  }

  return [{ value: 'all' as Scope, label: 'Всё', count: items.value.length }, ...present]
})

// Отобранный вид мог опустеть — последнюю строку сняли со звезды. Возвращаемся
// ко «всему», иначе человек смотрел бы на пустой экран при непустом избранном.
watch(scopes, (available) => {
  if (!available.some(one => one.value === scope.value)) {
    scope.value = 'all'
  }
})

/* ---------- Сортировка ---------- */

type Order = 'added' | 'title' | 'section'

const SORTS: SelectOption<Order>[] = [
  { value: 'added', label: 'Сначала новые', hint: 'По времени, когда отложили' },
  { value: 'title', label: 'По названию', hint: 'А → Я' },
  { value: 'section', label: 'По разделу', hint: 'Курсы, уроки, документы, справочники' },
]

const order = ref<Order>('added')

/** Порядок разделов в сортировке «по разделу» — тот же, что в полосе вкладок. */
const SECTION_ORDER: Scope[] = ['courses', 'lessons', 'documents', 'handbooks']

const visible = computed<FavouriteItem[]>(() => {
  const chosen = scope.value === 'all'
    ? [...items.value]
    : items.value.filter(item => scopeOf(item) === scope.value)

  if (order.value === 'title') {
    // Сравнение по-русски: `localeCompare` ставит «Ё» между «Е» и «Ж», а не в
    // хвост алфавита, куда её отправляет сравнение по кодам символов.
    return chosen.sort((a, b) => a.title.localeCompare(b.title, 'ru'))
  }

  if (order.value === 'section') {
    return chosen.sort((a, b) => {
      const bySection = SECTION_ORDER.indexOf(scopeOf(a)) - SECTION_ORDER.indexOf(scopeOf(b))

      return bySection === 0 ? a.title.localeCompare(b.title, 'ru') : bySection
    })
  }

  // Порядок с сервера и есть «сначала новые» — пересчитывать его незачем.
  return chosen
})

/* ---------- Снятие звезды ---------- */

/**
 * Снятую строку убираем сразу, не дожидаясь перечитывания списка.
 *
 * Иначе человек видел бы пустую звезду при оставшейся строке — а это читается
 * как «не сработало». Список всё равно перечитывается следом: отложить могли и
 * с другой вкладки.
 */
const forgotten = ref(new Set<number>())

const shown = computed<FavouriteItem[]>(() => visible.value.filter(item => !forgotten.value.has(item.id)))

async function forget(item: FavouriteItem) {
  forgotten.value = new Set(forgotten.value).add(item.id)

  await refresh()

  forgotten.value = new Set()
}

function when(value: string | null): string {
  return value ? new Date(value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' }) : ''
}
</script>

<template>
  <section>
    <header class="head">
      <h1 class="page-title">
        Избранное
      </h1>
      <p class="page-subtitle">
        То, что вы отложили сами: курсы, уроки, документы и справочники, к которым
        возвращаетесь. Видите эту страницу только вы.
      </p>
    </header>

    <UiEmptyState
      v-if="!items.length"
      title="Пока пусто"
      description="Нажмите звезду на курсе, уроке, документе или справочнике — и он окажется здесь."
    />

    <template v-else>
      <div class="tools">
        <!-- Ряд отбора — пилюлями, как полоса разделов: видов немного, и
             спрятать их в список значило бы прятать то, что и так помещается. -->
        <div v-if="scopes.length" class="scopes" role="group" aria-label="Что показывать">
          <button
            v-for="one in scopes"
            :key="one.value"
            type="button"
            class="scope"
            :class="{ 'scope--on': scope === one.value }"
            :aria-pressed="scope === one.value"
            @click="scope = one.value"
          >
            {{ one.label }}
            <span class="scope__count">{{ one.count }}</span>
          </button>
        </div>

        <label class="sort">
          <span class="faint sort__label">Порядок</span>
          <UiSelect v-model="order" :options="SORTS" auto />
        </label>
      </div>

      <p v-if="!shown.length" class="faint empty">
        В этом разделе отложенного нет.
      </p>

      <ul v-else class="items">
        <li v-for="item in shown" :key="`${item.kind}-${item.material_id}`" class="card item">
          <div class="item__body">
            <p class="item__title">
              <!-- Строка без ссылки — урок, чей курс пропал: вести ей некуда, и
                   обещать переход нельзя. -->
              <NuxtLink v-if="item.url" :to="item.url">
                {{ item.title }}
              </NuxtLink>
              <span v-else>{{ item.title }}</span>

              <span class="badge">{{ item.section_label }}</span>
              <span v-if="!item.is_published" class="badge badge--warning">Черновик</span>
            </p>

            <p v-if="item.summary" class="faint item__summary">
              {{ item.summary }}
            </p>

            <p class="faint item__meta">
              <!-- Курс у урока — не украшение: «Введение» есть в каждом втором
                   курсе, и без него строка безымянна. -->
              <template v-if="item.course">{{ item.course }} · </template>
              <template v-if="item.category">{{ item.category }} · </template>
              отложено {{ when(item.added_at) }}
            </p>
          </div>

          <FavouriteButton
            :kind="item.kind"
            :material-id="item.material_id"
            :model-value="true"
            @forgotten="forget(item)"
          />
        </li>
      </ul>
    </template>
  </section>
</template>

<style scoped>
.head {
  margin-bottom: 1.5rem;
}

.page-subtitle,
.empty {
  max-width: 62ch;
}

.tools {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
  margin-bottom: 1rem;
}

.scopes {
  display: flex;
  gap: 0.35rem;
  flex-wrap: wrap;
}

.scope {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.35rem 0.8rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill);
  background: transparent;
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.85rem;
  cursor: pointer;
  transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;
}

.scope:hover {
  color: var(--color-text);
}

.scope--on {
  background: var(--color-accent);
  border-color: var(--color-accent);
  color: var(--color-accent-text);
}

/* Число на выбранной пилюле — тем же цветом, что и её подпись: своего тона у
   счётчика нет, он часть слова. */
.scope__count {
  font-variant-numeric: tabular-nums;
  opacity: 0.7;
}

.sort {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.sort__label {
  font-size: 0.85rem;
}

.items {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.item {
  display: flex;
  align-items: flex-start;
  gap: 1rem;
  padding: 0.9rem 1.1rem;
}

.item__body {
  flex: 1;
  min-width: 0;
}

.item__title {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin: 0;
  font-weight: 550;
}

.item__title a {
  color: inherit;
  text-decoration: none;
}

.item__title a:hover {
  text-decoration: underline;
  text-underline-offset: 3px;
}

.item__summary {
  margin: 0.25rem 0 0;
  font-size: 0.88rem;
  /* Две строки: отложенного бывает много, и длинная подпись превращает список
     в полотно, по которому уже не пробежать глазами. */
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  overflow: hidden;
}

.item__meta {
  margin: 0.25rem 0 0;
  font-size: 0.82rem;
}

@media (max-width: 40rem) {
  .tools {
    align-items: stretch;
    flex-direction: column;
  }
}
</style>
