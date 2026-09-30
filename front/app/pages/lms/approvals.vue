<script setup lang="ts">
import type { MaterialReview } from '~/types/lms'

/**
 * Материалы, которые просят согласовать именно этого человека.
 *
 * Права на страницу нет и не нужно — как и у аттестаций: сюда попадает то, куда
 * его позвал автор. Назначение и есть право; тому, кого не звали, страница просто
 * пуста.
 *
 * Решают не здесь, а на самой странице материала: согласовать, не прочитав, —
 * это поставить подпись под непрочитанным. Отсюда ведут ссылки, а под ними стоят
 * закрытые круги: к ним возвращаются — вспомнить, что просили исправить, и
 * посмотреть, чем кончилось.
 */
definePageMeta({ middleware: 'auth', permission: ['courses.view', 'documents.view', 'handbooks.view'] })
useHead({ title: 'Согласование' })

const api = useApprovalsApi()
const { refreshBadges } = useNavigation()

/*
 * Две половины раздела: что ждёт моего ответа и что стало с тем, что отправлял я.
 *
 * Одним запросом-парой: половины независимы, но приходят на один экран, и два
 * useAsyncData означали бы два состояния загрузки там, где смысл один.
 */
const { data } = await useAsyncData('lms.approvals', async () => {
  const [asked, submitted] = await Promise.all([api.queue(), api.mine()])

  return { asked: asked.data, mine: submitted.data }
})

const reviews = computed<MaterialReview[]>(() => data.value?.asked ?? [])

/** Мои отправки: идущие круги и возвраты, по последнему кругу материала. */
const mine = computed<MaterialReview[]>(() => data.value?.mine ?? [])

const returnedToMe = computed(() => mine.value.filter(review => review.status === 'returned'))
const awaitingOthers = computed(() => mine.value.filter(review => review.is_open))

/** Кого ещё ждём в этом круге — автору важно именно это. */
function stillWaiting(review: MaterialReview): string {
  return (review.decisions ?? [])
    .filter(decision => decision.status === 'pending')
    .map(decision => decision.user.name)
    .join(', ')
}
const waiting = computed(() => reviews.value.filter(review => review.awaits_me))

/**
 * Круги, в которых от человека уже ничего не ждут.
 *
 * Не «вы ответили»: сюда же попадает круг, отозванный автором, и круг,
 * законченный чужим возвратом, — там человек не отвечал вовсе, и заголовок
 * «Вы уже ответили» соврал бы ему о его же решении.
 */
const closed = computed(() => reviews.value.filter(review => !review.awaits_me))

// Значок в полосе разделов считает навигация, и после захода сюда он мог
// разойтись с тем, что человек видит на экране.
onMounted(() => {
  void refreshBadges()
})

/**
 * Что человек ответил в этом круге — своей строкой, а не общим итогом круга.
 *
 * Своего ответа может и не быть: круг отозвали или закончил чужой возврат. Тогда
 * говорим, чем кончился сам круг, — это и есть ответ на вопрос «а что с ним
 * стало».
 */
function myAnswer(review: MaterialReview): string {
  const mine = (review.decisions ?? []).find(decision => decision.status !== 'pending')

  return mine?.status_label ?? review.status_label
}

function day(value: string | null): string {
  return value
    ? new Date(value).toLocaleString('ru-RU', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' })
    : ''
}
</script>

<template>
  <section>
    <header class="head">
      <h1 class="page-title">
        Согласование
      </h1>
      <p class="page-subtitle">
        Две половины одного разговора: что просят согласовать вас — и что стало
        с тем, что отправили вы. Решают на самой странице материала.
      </p>
    </header>

    <p v-if="!reviews.length && !mine.length" class="faint empty">
      Вас пока ни о чём не просили, и своих материалов на согласовании нет.
      Отправить материал можно из его редактора.
    </p>

    <!-- Своё — первым: вернувшаяся работа ждёт именно вас, а чужая ждёт вашего
         времени. -->
    <template v-if="returnedToMe.length">
      <h2 class="group">
        Вернули вам на исправление · {{ returnedToMe.length }}
      </h2>

      <ul class="rows">
        <li v-for="review in returnedToMe" :key="review.id" class="card card--raised row row--returned">
          <div class="row__body">
            <NuxtLink :to="review.material?.path ?? '/lms'" class="row__title">
              {{ review.material?.title }}
            </NuxtLink>
            <span class="faint row__meta">
              {{ review.material?.label }} · {{ day(review.closed_at) }}
              <template v-if="review.round > 1"> · круг {{ review.round }}</template>
            </span>
            <span v-if="review.returned_reason" class="row__reason">{{ review.returned_reason }}</span>
          </div>

          <NuxtLink :to="review.material?.path ?? '/lms'" class="button-primary button-sm">
            Открыть
          </NuxtLink>
        </li>
      </ul>
    </template>

    <template v-if="awaitingOthers.length">
      <h2 class="group">
        Ваши материалы на согласовании
      </h2>

      <ul class="rows">
        <li v-for="review in awaitingOthers" :key="review.id" class="card row row--quiet">
          <div class="row__body">
            <NuxtLink :to="review.material?.path ?? '/lms'" class="row__title">
              {{ review.material?.title }}
            </NuxtLink>
            <span class="faint row__meta">
              Ждём ответа: {{ stillWaiting(review) || 'все ответили' }} · отправлено {{ day(review.submitted_at) }}
            </span>
          </div>
        </li>
      </ul>
    </template>

    <template v-if="reviews.length">
      <h2 v-if="waiting.length" class="group">
        Просят согласовать · {{ waiting.length }}
      </h2>

      <ul v-if="waiting.length" class="rows">
        <li v-for="review in waiting" :key="review.id" class="card card--raised row">
          <div class="row__body">
            <NuxtLink :to="review.material?.path ?? '/lms'" class="row__title">
              {{ review.material?.title }}
            </NuxtLink>
            <span class="faint row__meta">
              {{ review.material?.label }} · отправил {{ review.requested_by }} · {{ day(review.submitted_at) }}
              <template v-if="review.round > 1"> · круг {{ review.round }}</template>
            </span>
          </div>

          <NuxtLink :to="review.material?.path ?? '/lms'" class="button-primary button-sm">
            Открыть
          </NuxtLink>
        </li>
      </ul>

      <h2 v-if="closed.length" class="group">
        Ответа больше не ждут
      </h2>

      <ul v-if="closed.length" class="rows">
        <li v-for="review in closed" :key="review.id" class="card row row--quiet">
          <div class="row__body">
            <NuxtLink :to="review.material?.path ?? '/lms'" class="row__title">
              {{ review.material?.title }}
            </NuxtLink>
            <span class="faint row__meta">
              {{ myAnswer(review) }} · {{ day(review.closed_at ?? review.submitted_at) }}
              <!-- Номер круга: один и тот же материал присылают снова после
                   исправления, и без него строки не отличить друг от друга. -->
              <template v-if="review.round > 1"> · круг {{ review.round }}</template>
              <template v-if="review.material?.is_published"> · материал опубликован</template>
            </span>

            <!-- Причина возврата остаётся на виду: к ней возвращаются, когда
                 автор присылает исправленное. -->
            <span v-if="review.returned_reason" class="row__reason">{{ review.returned_reason }}</span>
          </div>
        </li>
      </ul>
    </template>
  </section>
</template>

<style scoped>
.head {
  margin-bottom: 1.5rem;
}

.empty {
  margin: 0;
}

.group {
  margin: 1.5rem 0 0.75rem;
  font-size: 0.95rem;
  font-weight: 500;
}

.rows {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.row {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.85rem 1rem;
}

/* Возврат — единственное, что требует действия от автора: его и выделяем. */
.row--returned {
  border-left: 3px solid var(--color-danger);
}

/* Решённое тише ждущего: оно здесь для памяти, а не для работы. */
.row--quiet {
  opacity: 0.85;
}

.row__body {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
  gap: 0.15rem;
}

.row__title {
  font-size: 0.98rem;
  text-decoration: none;
  color: var(--color-text);
}

.row__title:hover {
  text-decoration: underline;
}

.row__meta {
  font-size: 0.82rem;
}

.row__reason {
  margin-top: 0.2rem;
  font-size: 0.85rem;
  white-space: pre-line;
}

.row a.button-primary {
  flex-shrink: 0;
  text-decoration: none;
}
</style>
