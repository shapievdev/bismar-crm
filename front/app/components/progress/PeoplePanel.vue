<script setup lang="ts">
import type { MaterialProgress, MaterialProgressPerson } from '~/types/lms'

/**
 * Кто прошёл материал — поимённо.
 *
 * Одна панель на урок и на документ: прогресс у обоих — одно событие (урок
 * закрыт, документ прочитан), и разница только в том, как его назвать. Слова
 * приходят снаружи, откуда брать цифры — тоже: панель об устройстве разделов
 * знать не должна.
 *
 * Видит её администратор, и это решено не здесь, а на маршруте
 * (EnsureAdministrator) и на странице, которая панель ставит.
 */
const props = defineProps<{
  load: () => Promise<MaterialProgress>
  title: string
  /** «Прошли урок» / «Ознакомились» — чем кончается сводка над списком. */
  summaryLabel: string
  /** «Пройден» / «Ознакомлен» — отметка у имени. */
  doneLabel: string
  pendingLabel: string
}>()

const report = ref<MaterialProgress | null>(null)
const isLoading = ref(true)
const errorMessage = ref<string | null>(null)

onMounted(async () => {
  try {
    report.value = await props.load()
  }
  catch {
    errorMessage.value = 'Не удалось загрузить статистику прохождения.'
  }
  finally {
    isLoading.value = false
  }
})

const people = computed(() => report.value?.people ?? [])
const summary = computed(() => report.value?.summary ?? null)

/** Есть ли о чём говорить в колонке теста: у материала без проверки — нет. */
const hasQuiz = computed(() => people.value.some(person => person.quiz !== null))

/**
 * Чем кончился тест у человека — одной строкой.
 *
 * Ожидание проверяющего названо прямо: у аттестации ноль баллов до вердикта
 * — обычное дело, и без оговорки он читался бы провалом.
 */
function quizLabel(person: MaterialProgressPerson): string {
  const quiz = person.quiz

  if (!quiz || quiz.attempts === 0) {
    return 'не проходил'
  }

  if (quiz.awaits_review) {
    return 'ждёт проверки'
  }

  const attempts = `${quiz.attempts} ${pluralise(quiz.attempts, 'попытка', 'попытки', 'попыток')}`

  return quiz.passed
    ? `сдано, ${quiz.best_score}% · ${attempts}`
    : `не сдано, ${quiz.best_score}% · ${attempts}`
}
</script>

<template>
  <section class="progress card">
    <h3 class="progress__title">
      {{ title }}
    </h3>

    <p v-if="isLoading" class="progress__note">
      Считаем…
    </p>

    <p v-else-if="errorMessage" class="alert alert--danger" role="alert">
      {{ errorMessage }}
    </p>

    <template v-else-if="summary">
      <!-- Круг людей — начавшие и те, кому материал назначен планом. Весь штат
           сюда не входит: документ открыт всякому, кто читает базу знаний. -->
      <p v-if="!people.length" class="progress__note">
        Материал пока никто не открывал, и в планах обучения его нет.
      </p>

      <template v-else>
        <!-- «Ознакомились 1 из 3», а не «1 из 3 человек»: счётное слово здесь
             ломается на каждом числе, а сказать надо одно — сколько из
             скольких. -->
        <p class="progress__summary">
          {{ summaryLabel }} {{ summary.done }} из {{ summary.people }}.
          <span v-if="hasQuiz && summary.passed !== undefined" class="progress__note">
            Сдали {{ summary.passed }} из {{ summary.attempted }} проходивших.
          </span>
        </p>

        <ul class="people">
          <li v-for="person in people" :key="person.id" class="person">
            <UserAvatar :name="person.name" :src="person.avatar_url" :size="32" />

            <span class="person__body">
              <span class="person__name">
                {{ person.name }}
                <span v-if="person.in_plan" class="badge">в плане</span>
              </span>
              <span class="faint person__job">
                <template v-if="person.job_title">{{ person.job_title }}</template>
                <!-- Какую версию человек читал: у кого какая, там и
                     спрашивать. У документа без версий поля нет вовсе. -->
                <template v-if="person.version">
                  <template v-if="person.job_title"> · </template>версия «{{ person.version }}»
                </template>
              </span>
            </span>

            <span v-if="hasQuiz" class="person__quiz faint">
              {{ quizLabel(person) }}
            </span>

            <span
              class="person__mark"
              :class="person.is_done ? 'badge badge--success' : 'badge'"
            >
              {{ person.is_done ? doneLabel : pendingLabel }}
            </span>
          </li>
        </ul>
      </template>
    </template>
  </section>
</template>

<style scoped>
.progress {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  margin-top: 1.5rem;
  padding: 1.2rem 1.35rem 1.35rem;
}

.progress__title {
  margin: 0;
  font-size: 1.02rem;
  font-weight: 500;
}

.progress__summary {
  margin: 0;
  font-size: 0.92rem;
}

.progress__note {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.people {
  display: flex;
  flex-direction: column;
  margin: 0.2rem 0 0;
  padding: 0;
  list-style: none;
}

.person {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  padding: 0.55rem 0;
  border-top: 1px solid var(--color-border-subtle, var(--color-border));
}

.person:first-child {
  border-top: none;
}

.person__body {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
}

.person__name {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.92rem;
}

.person__job {
  font-size: 0.8rem;
}

/* Итог теста стоит перед отметкой и в одну строку: колонка читается сверху
   вниз, а не выискивается глазами посреди имени. */
.person__quiz {
  flex-shrink: 0;
  font-size: 0.82rem;
  font-variant-numeric: tabular-nums;
}

.person__mark {
  flex-shrink: 0;
}

@media (max-width: 44rem) {
  /* На телефоне строка ломается: имя сверху, итог и отметка — под ним. */
  .person {
    flex-wrap: wrap;
  }

  .person__body {
    flex-basis: calc(100% - 2.5rem);
  }

  .person__quiz {
    margin-left: 2.7rem;
  }
}
</style>
