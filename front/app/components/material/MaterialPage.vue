<script setup lang="ts">
import { withResolvedMedia } from '~/utils/editor/attachments'
import type { CoursePerson, MaterialSection, MaterialVersion, QuizOutcome } from '~/types/lms'

/**
 * Страница документа или справочника — один экран на оба раздела.
 */
const props = defineProps<{ section: MaterialSection }>()

const copy = useMaterialSection(props.section)

const route = useRoute()
const slug = computed(() => String(route.params.slug))

const { can, isAdmin, user } = useAuth()
const {
  fetchRegulation,
  acknowledge,
  fetchReaders,
  fetchCategories,
  submitQuiz,
  fetchVersion,
  submitVersionQuiz,
  fetchProgress,
  fetchQuizStatistics,
  fetchQuizAttempt,
} = useMaterialsApi(props.section)

const { data, error, refresh } = await useAsyncData(
  () => `lms.${props.section}.${slug.value}`,
  () => fetchRegulation(slug.value),
)

if (error.value) {
  throw createError({ statusCode: 404, statusMessage: copy.notFound, fatal: true })
}

const regulation = computed(() => data.value?.data ?? null)

useHead({ title: () => regulation.value?.title ?? copy.title })

/* ---------- Версии: то же правило, написанное для своих людей ---------- */

/**
 * Какие версии этому человеку открыты и какую он читает.
 *
 * Общей версии в списке нет — она сам документ, — поэтому в переключателе она
 * стоит первой строкой, а `selected` при ней пуст. Своя версия открывается
 * сама: её присылает сервер, и спрашивать его об этом второй раз незачем.
 */
const versions = computed(() => regulation.value?.versions ?? [])
const selected = ref<MaterialVersion | null>(regulation.value?.version ?? null)
const isSwitchingVersion = ref(false)

/*
 * Перечитали документ — перечитываем и открытую версию.
 *
 * Перечитывают его после ознакомления и после сдачи проверки, и тело версии
 * при этом устаревает вместе с ним: в нём лежит история попыток. Подменять
 * открытое на общую тоже нельзя — текст сменился бы под читателем.
 */
watch(regulation, async (value) => {
  const open = selected.value

  if (open === null) {
    selected.value = value?.version ?? null

    return
  }

  selected.value = (value?.versions ?? []).some(one => one.id === open.id)
    ? (await fetchVersion(slug.value, open.id)).data
    : null
})

/**
 * Тело страницы: статья, файлы и проверка выбранной версии — или общие, если
 * выбрана общая.
 */
const body = computed(() => selected.value ?? regulation.value)

async function openVersion(versionId: number | null) {
  if (versionId === null) {
    selected.value = null

    return
  }

  if (selected.value?.id === versionId) {
    return
  }

  isSwitchingVersion.value = true

  try {
    selected.value = (await fetchVersion(slug.value, versionId)).data
  }
  finally {
    isSwitchingVersion.value = false
  }
}

/**
 * Крошки: раздел, категории по дороге сюда и сам документ.
 *
 * У документа своя категория, а крошкам нужен весь путь наверх — отсюда дерево
 * категорий. Ключ общий с разделом: дерево одно, и второй раз за ним не ходят.
 */
const { data: categoryData } = await useAsyncData(
  `lms.${props.section}.categories`,
  () => fetchCategories(),
)

const trail = computed(() => categoryTrail(
  categoryData.value?.data ?? [],
  regulation.value?.category?.slug,
))

/**
 * Адреса вложенных картинок и видео подставляются на пути к экрану: статья
 * хранит их номера, а подписанные ссылки живут час.
 */
const article = computed(() => withResolvedMedia(
  body.value?.content_json ?? null,
  body.value?.attachments ?? [],
))

/**
 * Кого спрашивать, если написанного не хватило.
 *
 * Ответственных назначают не всегда, а спросить хочется в любом случае — тогда
 * остаётся автор: материал написал он, и он же его поправит. Себя в этом
 * списке читатель не видит: панель, предлагающая написать самому себе, не
 * отвечает ни на один вопрос.
 */
const asked = computed<{ id: number, name: string, avatar_url: string | null }[]>(() => {
  const experts = regulation.value?.experts ?? []

  if (experts.length > 0) {
    return experts
  }

  const author = regulation.value?.author

  return author && author.id !== user.value?.id ? [author] : []
})

/** Автор отвечает за материал не по назначению, а по авторству — так и сказано. */
const askedTitle = computed(() =>
  regulation.value?.experts?.length ? 'Спросите ответственного' : 'Спросите автора',
)

const documents = computed(() =>
  (body.value?.attachments ?? []).filter(file =>
    // Файл с Диска в списке всегда: он не бывает случайной картинкой из статьи
    // — его прикладывают руками и затем, чтобы его нашли.
    file.source === 'google_drive' || !file.opens_inline || file.description))

function day(value: string | null): string {
  return value ? new Date(value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' }) : ''
}

/* ---------- Ознакомление ---------- */

const isConfirming = ref(false)
const confirmError = ref<string | null>(null)

async function confirm() {
  isConfirming.value = true
  confirmError.value = null

  try {
    await acknowledge(slug.value)
    await refresh()
  }
  catch {
    confirmError.value = 'Не удалось отметить ознакомление.'
  }
  finally {
    isConfirming.value = false
  }
}

/* ---------- Проверка вместо кнопки ---------- */

/**
 * Есть проверка — ознакомление засчитывается сдачей. Кнопки при ней нет вовсе:
 * нажатие обесценивало бы тест, и сервер её всё равно не примет.
 */
const quiz = computed(() => body.value?.quiz ?? null)
const attempts = computed(() => body.value?.own_attempts ?? [])

const isSubmitting = ref(false)
const quizError = ref<string | null>(null)
const outcome = ref<QuizOutcome | null>(null)

/**
 * Последняя работа, ушедшая на аттестацию, и её состояние.
 *
 * Ожидание живёт дольше одного захода: отправили вчера, ответ пришёл сегодня —
 * и человек должен увидеть его, просто открыв документ. Поэтому берётся не
 * итог отправки, а последняя попытка с сервера.
 */
const lastAttempt = computed(() => attempts.value[0] ?? null)

const awaitingAttestation = computed(() =>
  lastAttempt.value?.review_status === 'pending' ? lastAttempt.value : null)

const judgedAttestation = computed(() =>
  lastAttempt.value?.review_status === 'passed' || lastAttempt.value?.review_status === 'failed'
    ? lastAttempt.value
    : null)

async function sendAnswers(answers: Record<number, number[] | string | string[][]>) {
  isSubmitting.value = true
  quizError.value = null

  try {
    // Проверка своя у каждой версии: сдают ту, что читают. Сдача при этом
    // по-прежнему означает ознакомление с документом.
    outcome.value = selected.value === null
      ? (await submitQuiz(slug.value, answers)).data
      : (await submitVersionQuiz(slug.value, selected.value.id, answers)).data

    // Сдал — документ прочитан: перечитываем его, чтобы отметка и история
    // попыток пришли с сервера, а не собирались на экране.
    if (outcome.value.passed) {
      await refresh()
    }
  }
  catch (caught) {
    const failure = caught as { data?: { message?: string } }
    quizError.value = failure.data?.message ?? 'Не удалось отправить ответы.'
  }
  finally {
    isSubmitting.value = false
  }
}

/* ---------- Кто прочитал: только тому, кто документ ведёт ---------- */

const readers = ref<CoursePerson[] | null>(null)

async function toggleReaders() {
  if (readers.value !== null) {
    readers.value = null

    return
  }

  readers.value = (await fetchReaders(slug.value)).data
}
</script>

<template>
  <article v-if="regulation" class="regulation">
    <nav class="crumbs" aria-label="Где я">
      <NuxtLink :to="`/lms/${copy.section}`">
        {{ copy.title }}
      </NuxtLink>

      <template v-for="node in trail" :key="node.slug">
        <span class="crumbs__separator" aria-hidden="true">/</span>
        <NuxtLink :to="{ path: `/lms/${copy.section}`, query: { category: node.slug } }">
          {{ node.name }}
        </NuxtLink>
      </template>

      <span class="crumbs__separator" aria-hidden="true">/</span>
      <span class="faint" aria-current="page">{{ regulation.title }}</span>
    </nav>

    <header class="head">
      <div class="head__marks">
        <span v-if="!regulation.is_published" class="badge badge--warning">{{ regulation.status_label }}</span>
        <span v-if="regulation.is_private" class="badge">{{ regulation.visibility_label }}</span>
        <span v-if="regulation.is_acknowledged" class="badge badge--success">Ознакомлен</span>
      </div>

      <h1 class="page-title">
        {{ regulation.title }}
      </h1>

      <p v-if="regulation.summary" class="page-subtitle">
        {{ regulation.summary }}
      </p>

      <p class="faint">
        <template v-if="regulation.category">{{ regulation.category.name }} · </template>
        <template v-if="regulation.author">{{ regulation.author.name }} · </template>
        {{ day(regulation.published_at) }}
      </p>

      <!--
        Переключатель версий: то же правило, написанное для разных людей.

        Общая стоит первой и всегда — она сам документ. Своя версия открыта с
        самого начала, но посмотреть соседнюю не запрещено: за этим сюда и
        приходят («а как считают у них»). Закрытая помечена: пересказывать её
        коллеге, которого в группу не внесли, не стоит.
      -->
      <nav v-if="versions.length" class="versions" aria-label="Версии документа">
        <button
          type="button"
          class="versions__item"
          :class="{ 'versions__item--current': selected === null }"
          :aria-pressed="selected === null"
          :disabled="isSwitchingVersion"
          @click="openVersion(null)"
        >
          Общая
        </button>

        <button
          v-for="version in versions"
          :key="version.id"
          type="button"
          class="versions__item"
          :class="{ 'versions__item--current': selected?.id === version.id }"
          :aria-pressed="selected?.id === version.id"
          :disabled="isSwitchingVersion"
          @click="openVersion(version.id)"
        >
          {{ version.name }}
          <span v-if="version.is_mine" class="versions__mark">ваша</span>
          <span v-else-if="version.is_private" class="versions__mark">закрытая</span>
        </button>
      </nav>

      <div v-if="can(copy.rights.update)" class="head__actions">
        <NuxtLink :to="`/lms/${copy.section}/${regulation.slug}/edit`" class="button-secondary button-sm">
          Редактировать
        </NuxtLink>
        <button type="button" class="button-ghost button-sm" @click="toggleReaders">
          {{ readers ? 'Скрыть' : 'Кто ознакомился' }}
          <template v-if="regulation.acknowledged_count !== undefined">
            ({{ regulation.acknowledged_count }})
          </template>
        </button>
      </div>
    </header>

    <section v-if="readers" class="card readers">
      <h2 class="readers__title">
        Ознакомились — {{ readers.length }}
      </h2>
      <p v-if="!readers.length" class="faint">
        Пока никто.
      </p>
      <ul v-else class="people">
        <li v-for="person in readers" :key="person.id" class="person">
          <UserAvatar :name="person.name" :src="person.avatar_url" :size="26" />
          <span class="person__body">
            <span>{{ person.name }}</span>
            <span v-if="person.acknowledged_at" class="faint">{{ day(person.acknowledged_at) }}</span>
          </span>
        </li>
      </ul>
    </section>

    <!--
      Статья слева, ссылки справа (решение пользователя 2026-09-06). Соседи,
      ответственные и частые вопросы — не продолжение правила, а выходы из
      него: стоя друг под другом под текстом, они отодвигали отметку
      «ознакомлен» на третий экран.
    -->
    <div class="layout">
      <div class="layout__main">
        <EditorRichTextRenderer :content="article" />

        <section v-if="documents.length" class="card files">
          <h2 class="files__title">
            Приложенные файлы
          </h2>
          <ul class="files__list">
            <li v-for="file in documents" :key="file.id" class="file">
              <div class="file__row">
                <UiFileIcon :name="file.name" :mime-type="file.mime_type" />

                <a :href="file.url" target="_blank" rel="noopener noreferrer" class="file__link">
                  {{ file.name }}
                  <span v-if="file.description" class="faint file__note">{{ file.description }}</span>
                </a>
              </div>

              <!-- Файл с Диска раскрыт сразу: его затем и прикладывают, чтобы
                   читали здесь. Рамка стоит под строкой, а не рядом со значком:
                   иначе листу A4 остаётся колонка в две трети ширины. -->
              <DriveEmbed
                v-if="file.embed_url"
                :src="file.embed_url"
                :title="file.name"
                :open-url="file.url"
              />
            </li>
          </ul>
        </section>

        <template v-if="regulation.is_published">
          <!-- Отметка стоит — говорим об этом и не спрашиваем второй раз, была
               она поставлена кнопкой или сдачей проверки. -->
          <section v-if="regulation.is_acknowledged" class="card confirm">
            <p class="confirm__done">
              {{ copy.acknowledged }}
              <template v-if="regulation.acknowledged_at">
                {{ day(regulation.acknowledged_at) }}
              </template>
            </p>
          </section>

          <!-- Работа ушла человеку: пока он не ответил, отвечать заново нечего. -->
          <section v-else-if="quiz && awaitingAttestation" class="card confirm">
            <h2 class="files__title">
              {{ quiz.title }}
            </h2>
            <AttestationStatusPanel :attempt="awaitingAttestation" :examiner="quiz.examiner?.name" />
          </section>

          <!-- Проверка вместо кнопки: сдал — значит прочитал. -->
          <QuizRunner
            v-else-if="quiz"
            :quiz="quiz"
            :is-submitting="isSubmitting"
            :error-message="quizError"
            :result="outcome"
            :rule="copy.quizRule(quiz.kind === 'attestation'
              ? quiz.examiner?.name ?? 'назначенный проверяющий'
              : null)"
            :passed-note="copy.quizPassedNote"
            :failed-note="copy.quizFailedNote"
            @submit="sendAnswers"
            @retry="outcome = null"
          />

          <!-- Ответ проверяющего, когда он уже пришёл: отказ с причиной или зачёт. -->
          <section v-if="quiz && judgedAttestation" class="card confirm">
            <AttestationStatusPanel :attempt="judgedAttestation" :examiner="quiz.examiner?.name" />
          </section>

          <!--
            Кнопка — только тому, кому ещё есть что отметить, и только там, где
            отмечаются кнопкой.

            Условие названо полностью, а не одним `v-else`: тот относился к
            вердикту проверяющего строкой выше, и потому кнопка вылезала дважды
            — рядом с «вы ознакомились» у отмеченного документа и рядом с самой
            проверкой, где её быть не должно вовсе (сервер такое нажатие и не
            принимает, см. RegulationAcknowledgementController).
          -->
          <section v-else-if="!regulation.is_acknowledged && !quiz" class="card confirm">
            <p v-if="confirmError" class="alert alert--danger" role="alert">
              {{ confirmError }}
            </p>
            <p class="faint">
              {{ copy.acknowledgeHint }}
            </p>
            <button type="button" class="button-primary" :disabled="isConfirming" @click="confirm">
              {{ isConfirming ? 'Отмечаем…' : 'Ознакомлен' }}
            </button>
          </section>

          <!-- Прошлые попытки с разбором каждой — как у теста урока. -->
          <QuizAttemptsHistory :attempts="attempts" />
        </template>

        <!-- «Нашли ответ?» — то же, что и у курса: справочник читают ради
             ответа, и сказать, что ответа не нашлось, надо там же, где искали. -->
        <MaterialFeedback
          :target="{ kind: 'material', section, slug: regulation.slug }"
          class="feedback"
        />
      </div>

      <!--
        Выходы со страницы — в том порядке, в каком к ним приходят: сначала
        «что ещё почитать», потом «у кого спросить», и только потом список
        частых вопросов, который ведёт дальше по базе знаний.
      -->
      <aside class="layout__side">
        <!-- Что читать рядом. Сервер отдаёт только то, что этому человеку
             открыто, — отбирать здесь нечего. -->
        <section v-if="regulation.related?.length" class="card neighbours">
          <h2 class="files__title">
            Рядом по теме
          </h2>
          <ul class="neighbours__list">
            <li v-for="document in regulation.related" :key="document.id" class="neighbours__item">
              <NuxtLink :to="document.path" class="neighbours__link">
                {{ document.title }}
              </NuxtLink>
              <span v-if="!document.is_published" class="badge badge--warning">Черновик</span>
            </li>
          </ul>
        </section>

        <!-- Кому писать, если написанного не хватило: ответственным, а когда
             их не назначили — автору. -->
        <section v-if="asked.length" class="card experts">
          <h2 class="files__title">
            {{ askedTitle }}
          </h2>
          <ul class="people">
            <li v-for="person in asked" :key="person.id" class="person">
              <UserAvatar :name="person.name" :src="person.avatar_url" :size="26" />
              <span class="person__body">
                <span>{{ person.name }}</span>
                <!-- Материал уходит вместе с адресом: в мессенджере над
                     репликой встанет карточка, и адресат увидит, о чём вопрос. -->
                <NuxtLink
                  :to="`/messenger?write=${person.id}&about=${copy.kind}:${regulation.id}`"
                  class="faint person__write"
                >
                  Написать
                </NuxtLink>
              </span>
            </li>
          </ul>
        </section>

        <!-- «Частые вопросы» — вопросами они называются только на экране: под
             каждой строкой лежит другой материал, свой или из чужого раздела,
             и адрес к нему собран на сервере. -->
        <section v-if="regulation.questions?.length" class="card questions">
          <h2 class="files__title">
            Частые вопросы
          </h2>
          <ul class="questions__list">
            <li v-for="item in regulation.questions" :key="item.id">
              <NuxtLink :to="item.path" class="questions__link">
                {{ item.title }}
              </NuxtLink>
            </li>
          </ul>
        </section>
      </aside>
    </div>

    <!-- Как материал проходят — администратору (решение пользователя
         2026-09-12). Во всю ширину, под колонками: список людей длиннее
         врезки, и в боковой колонке он читался бы в три слова на строку. -->
    <template v-if="isAdmin">
      <ProgressPeoplePanel
        :key="`progress-${regulation.id}`"
        :load="async () => (await fetchProgress(slug)).data"
        title="Кто ознакомился"
        summary-label="Ознакомились"
        done-label="Ознакомлен"
        pending-label="Не ознакомлен"
      />

      <!-- Разбор проверки стоит здесь же: прежде он жил в редакторе, но это
           такая же статистика прохождения, и место у неё одно. -->
      <QuizStatisticsPanel
        v-if="quiz"
        :key="quiz.id"
        :load="async () => (await fetchQuizStatistics(slug)).data"
        :load-review="async id => (await fetchQuizAttempt(slug, id)).data.review ?? null"
      />
    </template>
  </article>
</template>

<style scoped>
.crumbs {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem;
  margin-bottom: 1rem;
  font-size: 0.87rem;
}

.crumbs a {
  color: var(--color-text-muted);
  text-decoration: none;
}

.crumbs a:hover {
  color: var(--color-text);
  text-decoration: underline;
}

.crumbs__separator {
  color: var(--color-text-faint);
}

.regulation {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.head {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.head__marks,
.head__actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.head__actions {
  margin-top: 0.35rem;
}

.head__actions a {
  text-decoration: none;
}

/*
 * Статья и выходы из неё. Одна колонка, пока нет места на две: боковые блоки
 * тогда просто продолжают страницу — читать их сверху вниз в том же порядке,
 * в каком они стоят справа.
 */
.layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 1.25rem;
}

.layout__main,
.layout__side {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  min-width: 0;
}

@media (min-width: 68rem) {
  .layout {
    /* Колонка ссылок задана в единицах шрифта, а не долей: её ширину диктует
       длина названия материала, и на широком мониторе доля растянула бы список
       из четырёх строк на треть экрана. */
    grid-template-columns: minmax(0, 1fr) 20rem;
    align-items: start;
  }

  /*
   * Выходы со страницы едут вместе с читателем. Статья длиннее экрана, а
   * «спросите ответственного» нужно ровно тогда, когда ответа в ней не нашлось,
   * — то есть посреди чтения, а не долистав до низа. Колонка встаёт под шапкой,
   * которая и сама прилипшая, а переросшая экран прокручивается внутри себя:
   * иначе нижняя карточка ушла бы за край окна и стала недосягаемой.
   */
  .layout__side {
    position: sticky;
    top: calc(var(--header-height) + 1rem);
    max-height: calc(100dvh - var(--header-height) - 2rem);
    overflow-y: auto;
    overscroll-behavior: contain;
  }
}

.readers,
.files,
.experts,
.neighbours,
.questions,
.confirm {
  padding: 1.25rem;
}

/*
 * Частые вопросы — список строк с волосяной линией между ними: строки читают
 * взглядом сверху вниз, и линия делит их, не превращая в четыре карточки.
 */
.questions__list {
  display: flex;
  flex-direction: column;
  margin: 0;
  padding: 0;
  list-style: none;
}

.questions__list li + li {
  border-top: 1px solid var(--color-border);
}

.questions__link {
  display: block;
  padding: 0.65rem 0;
  color: inherit;
  text-decoration: none;
}

.questions__list li:first-child .questions__link {
  padding-top: 0.15rem;
}

.questions__list li:last-child .questions__link {
  padding-bottom: 0.15rem;
}

.questions__link:hover {
  color: var(--color-accent);
}

.readers__title,
.files__title {
  margin: 0 0 0.6rem;
  font-size: 1rem;
  font-weight: 600;
}

.people,
.files__list {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.person,
.file {
  font-size: 0.9rem;
}

/* Значок и имя — в ряд; рамка просмотра, если она есть, — под ними во всю
   ширину, иначе листу A4 достаётся колонка в две трети. Строка файла и строка
   человека устроены одинаково, поэтому и правило одно. */
.person,
.file__row {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}

.person__body,
.file__link {
  display: flex;
  flex-direction: column;
  gap: 0.05rem;
  min-width: 0;
}

.person__write {
  align-self: flex-start;
  font-size: 0.8rem;
}

.file__link {
  color: inherit;
  text-decoration: none;
}

.file__link:hover {
  text-decoration: underline;
}

.file__note {
  font-size: 0.825rem;
}

/* Соседи — список ссылок через волосяную линию: строка длиннее одной, и без
   разделителя два названия читаются как одно. */
.neighbours__list {
  display: flex;
  flex-direction: column;
  margin: 0;
  padding: 0;
  list-style: none;
}

.neighbours__item {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.7rem 0;
  border-top: 1px solid var(--color-border-subtle, var(--color-border));
}

.neighbours__item:first-child {
  border-top: none;
  padding-top: 0.2rem;
}

.neighbours__item:last-child {
  padding-bottom: 0.2rem;
}

.neighbours__link {
  color: inherit;
  font-size: 0.95rem;
  line-height: 1.35;
  text-decoration: none;
}

.neighbours__link:hover {
  text-decoration: underline;
}

/*
 * Переключатель версий — строка вкладок под шапкой.
 *
 * Кнопками, а не ссылками: версия не меняет адреса документа. Ссылку на него
 * шлют коллеге, а откроется по ней версия того, кто перешёл, — в этом весь
 * смысл разделения.
 */
.versions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  margin-top: 0.9rem;
}

.versions__item {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.35rem 0.8rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill);
  background: var(--color-surface);
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.87rem;
  cursor: pointer;
}

.versions__item:hover:not(:disabled) {
  border-color: var(--color-border-strong);
  color: var(--color-text);
}

.versions__item:disabled {
  cursor: progress;
}

/* Выбранная — заливкой, а не одним цветом текста: вкладок бывает пять, и
   разницу в оттенке между ними глазом не поймать. */
.versions__item--current {
  border-color: var(--color-accent);
  background: var(--color-accent-soft);
  color: var(--color-accent);
  font-weight: 550;
}

.versions__mark {
  color: var(--color-text-faint);
  font-size: 0.75rem;
}

.versions__item--current .versions__mark {
  color: inherit;
  opacity: 0.75;
}

.confirm {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  align-items: flex-start;
}

.confirm__done {
  margin: 0;
  color: var(--color-success);
  font-weight: 550;
}
</style>
