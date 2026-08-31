<script setup lang="ts">
import type { BarRow } from '~/components/analytics/BarList.vue'
import { formatNumber } from '~/utils/numbers'

/**
 * Аналитика обучения.
 *
 * Отдельная вкладка, а не строка в продажном дашборде: там деньги из ClickHouse
 * и период, здесь — курсы из своей базы и прохождение, которое копится с начала
 * времён. Фильтра по датам поэтому нет: «курс за март» ничего не значит.
 */
definePageMeta({ middleware: 'auth', permission: 'enrollments.manage' })
useHead({ title: 'Обучение — Аналитика' })

const { fetchLearning } = useAnalyticsApi()

const { data, pending, error } = await useAsyncData(
  'analytics-learning',
  async () => (await fetchLearning()).data,
)

const summary = computed(() => data.value?.summary ?? null)

/** Доля в процентах, где знаменатель может быть нулём. */
function share(part: number, whole: number): number {
  return whole === 0 ? 0 : Math.round((part / whole) * 100)
}

/**
 * Курсы по числу записавшихся. Вторая величина строки — то, ради чего рейтинг
 * и открывают: сколько дошло до конца и как далеко ушли остальные.
 */
const courseRows = computed<BarRow[]>(() => (data.value?.courses ?? []).map(course => ({
  name: course.title,
  value: course.enrolled,
  meta: course.enrolled === 0
    ? `${formatNumber(course.lessons)} ${pluralise(course.lessons, 'урок', 'урока', 'уроков')}, никто не записан`
    : `завершили ${formatNumber(course.completed)} · прогресс ${course.average_progress}%`,
})))

const regulationRows = computed<BarRow[]>(() => (data.value?.regulations ?? []).map(regulation => ({
  name: regulation.title,
  value: regulation.acknowledged,
  meta: summary.value
    ? `${share(regulation.acknowledged, summary.value.staff)}% сотрудников`
    : undefined,
})))
</script>

<template>
  <section>
    <header class="head">
      <div>
        <h1 class="page-title">
          Обучение
        </h1>
        <p class="page-subtitle">
          Сколько собрано материала и как его проходят. Уволенные в счёт не идут — отчёт о тех, кого можно спросить.
        </p>
      </div>
    </header>

    <p v-if="error" class="alert alert--danger" role="alert">
      Не удалось посчитать аналитику обучения.
    </p>

    <div v-else-if="pending" class="skeleton skeleton-block" />

    <AnalyticsBentoGrid v-else-if="summary">
      <AnalyticsStatTile
        label="Курсов"
        :value="summary.courses"
        :span="2"
        :hint="`Опубликовано ${formatNumber(summary.published_courses)}`"
      />
      <AnalyticsStatTile
        label="Регламентов"
        :value="summary.regulations"
        :span="2"
        :hint="`Опубликовано ${formatNumber(summary.published_regulations)}`"
      />
      <AnalyticsStatTile
        label="Уроков"
        :value="summary.lessons"
        :span="2"
        hint="Во всех курсах, кроме удалённых"
      />
      <AnalyticsStatTile
        label="Учеников"
        :value="summary.learners"
        :span="3"
        :hint="`${share(summary.learners, summary.staff)}% сотрудников хотя бы на одном курсе`"
      />
      <AnalyticsStatTile
        label="Записей на курсы"
        :value="summary.enrollments"
        :span="3"
        :hint="`Завершено ${formatNumber(summary.completed)} — ${share(summary.completed, summary.enrollments)}%`"
      />

      <AnalyticsStatTile
        label="Средний прогресс"
        :value="summary.average_progress"
        format="percent"
        :span="3"
        hint="Доля пройденных уроков по всем записям"
      />
      <AnalyticsStatTile
        label="Тесты сдано"
        :value="share(summary.quiz_passed, summary.quiz_attempts)"
        format="percent"
        :span="3"
        :hint="`${formatNumber(summary.quiz_passed)} из ${formatNumber(summary.quiz_attempts)} попыток, средний балл ${summary.quiz_average_score}`"
      />
      <AnalyticsStatTile
        label="Ознакомлений с регламентами"
        :value="summary.acknowledgements"
        :span="3"
        :hint="`Отметились ${formatNumber(summary.acknowledged_by)} человек`"
      />
      <AnalyticsStatTile
        label="План обучения пройден"
        :value="share(summary.plan_done, summary.plan_steps)"
        format="percent"
        :span="3"
        :hint="`${formatNumber(summary.plan_done)} из ${formatNumber(summary.plan_steps)} шагов у ${formatNumber(summary.plan_people)} человек`"
      />

      <AnalyticsChartCard
        title="Курсы: кто записан"
        hint="Первым — тот, на который записалось больше всего людей"
        :span="6"
        :rows="3"
      >
        <AnalyticsBarList v-if="courseRows.length" :rows="courseRows" format="number" />
        <UiEmptyState v-else title="Курсов пока нет" description="Появятся здесь, как только их заведут." />
      </AnalyticsChartCard>

      <AnalyticsChartCard
        title="Регламенты: кто ознакомился"
        hint="Доля считается от числа работающих сотрудников"
        :span="6"
        :rows="3"
      >
        <AnalyticsBarList v-if="regulationRows.length" :rows="regulationRows" format="number" />
        <UiEmptyState v-else title="Регламентов пока нет" description="Появятся здесь, как только их заведут." />
      </AnalyticsChartCard>
    </AnalyticsBentoGrid>
  </section>
</template>

<style scoped>
.head {
  margin-bottom: 1.75rem;
}

.skeleton-block {
  width: 100%;
  height: 18rem;
}
</style>
