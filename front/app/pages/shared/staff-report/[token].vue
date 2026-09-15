<script setup lang="ts">
import { formatNumber } from '~/utils/numbers'

/**
 * Отчёт по ссылке — единственная страница приложения, которую открывают без
 * входа.
 *
 * Показывает ровно то, что отдаёт сервер, а отдаёт он только цифры. Списка
 * людей здесь нет и не появится: ссылка уходит наружу — в переписки, письма,
 * чужие закладки, — и то, что по ней видно, перестаёт быть под контролем в ту
 * же минуту.
 *
 * Оттого и вид у страницы другой: ни рельсы, ни вкладок, ни перехода «внутрь».
 * Открывший её не сотрудник, и вести ему отсюда некуда.
 */
const route = useRoute()
const token = String(route.params.token ?? '')

const { fetchSharedStaffReport } = useAnalyticsApi()

useHead({ title: 'Движение персонала' })

const { data, error } = await useAsyncData(
  `shared-staff-${token}`,
  async () => (await fetchSharedStaffReport(token)).data,
)

/**
 * Отказ объясняется словами сервера.
 *
 * «Отозвана» и «срок истёк» — разные новости: первая говорит, что просить
 * ссылку снова не нужно, вторая — что стоит попросить свежую.
 */
const refusal = computed(() => {
  const failure = error.value as { statusCode?: number, data?: { message?: string } } | null

  if (!failure) {
    return null
  }

  return failure.data?.message ?? 'Такой ссылки не существует.'
})

const summary = computed(() => data.value?.summary ?? null)

function when(value: string | null | undefined): string {
  return value ? new Date(value).toLocaleDateString('ru-RU') : '—'
}

function tenure(months: number | null): string {
  if (months === null) {
    return '—'
  }

  const years = Math.floor(months / 12)
  const rest = months % 12

  return [
    years > 0 ? `${years} ${pluralise(years, 'год', 'года', 'лет')}` : null,
    rest > 0 || years === 0 ? `${rest} ${pluralise(rest, 'месяц', 'месяца', 'месяцев')}` : null,
  ].filter(Boolean).join(' ')
}
</script>

<template>
  <section class="shared">
    <div v-if="refusal" class="shared__refusal card">
      <h1 class="page-title">
        Ссылка не открывается
      </h1>
      <p class="muted">
        {{ refusal }}
      </p>
    </div>

    <template v-else-if="data && summary">
      <header class="shared__head">
        <h1 class="page-title">
          Движение персонала
        </h1>
        <p class="page-subtitle">
          {{ when(data.period.from) }} — {{ when(data.period.to) }}.
          Только сводные цифры: персональные данные по этой ссылке не передаются.
        </p>
      </header>

      <AnalyticsBentoGrid>
        <AnalyticsStatTile
          label="Численность на конец"
          :value="summary.headcount_end"
          :span="3"
          :hint="`На начало ${formatNumber(summary.headcount_start)}`"
        />
        <AnalyticsStatTile label="Принято" :value="summary.hired" :span="3" />
        <AnalyticsStatTile
          label="Уволено"
          :value="summary.left"
          :span="3"
          :hint="summary.average_life === null
            ? 'За период никто не уходил'
            : `В среднем продержались ${tenure(summary.average_life)}`"
        />
        <AnalyticsStatTile
          label="Текучесть"
          :value="summary.turnover"
          format="percent"
          :span="3"
          :attention="summary.turnover >= 30"
          :hint="`От среднесписочной ${summary.average_headcount}`"
        />

        <AnalyticsChartCard
          title="Принято и уволено по месяцам"
          hint="Вверх — приём, вниз — уход"
          :span="12"
          :rows="2"
        >
          <AnalyticsMovementChart v-if="data.movement.length" :months="data.movement" />
          <UiEmptyState v-else title="Период пуст" description="Движения за этот срок не было." />
        </AnalyticsChartCard>

        <AnalyticsChartCard title="По подразделениям" :span="7" :rows="3">
          <table v-if="data.departments.length" class="data-table">
            <thead>
              <tr>
                <th>Подразделение</th>
                <th>Числятся</th>
                <th>Принято</th>
                <th>Уволено</th>
                <th>Текучесть</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in data.departments" :key="row.id">
                <td>{{ row.name }}</td>
                <td class="data-table__number" data-label="Числятся">
                  {{ row.headcount }}
                </td>
                <td class="data-table__number" data-label="Принято">
                  {{ row.hired }}
                </td>
                <td class="data-table__number" data-label="Уволено">
                  {{ row.left }}
                </td>
                <td class="data-table__number" data-label="Текучесть">
                  {{ row.turnover }}%
                </td>
              </tr>
            </tbody>
          </table>
          <UiEmptyState v-else title="Пусто" description="За этот срез движения не было." />
        </AnalyticsChartCard>

        <AnalyticsChartCard title="Распределение по стажу" :span="5" :rows="3">
          <AnalyticsBarList
            v-if="data.tenure.length"
            :rows="data.tenure.map(row => ({ name: row.label, value: row.count, meta: row.range }))"
            format="number"
          />
        </AnalyticsChartCard>

        <AnalyticsChartCard
          title="Причины ухода"
          :hint="data.reasons.unknown
            ? `У ${data.reasons.unknown} ушедших причина не проставлена — в доли они не входят`
            : 'Доли считаются от тех, у кого причина проставлена'"
          :span="12"
          :rows="2"
        >
          <AnalyticsBarList
            v-if="data.reasons.rows.length"
            :rows="data.reasons.rows.map(row => ({
              name: row.label,
              value: row.count,
              total: data!.reasons.total,
              meta: `${row.share}% ушедших`,
            }))"
            format="number"
          />
          <UiEmptyState v-else title="За период никто не уходил" description="И это лучший вид этой панели." />
        </AnalyticsChartCard>
      </AnalyticsBentoGrid>

      <p class="shared__footer muted">
        Ссылка действует до {{ when(data.expires_at) }}.
      </p>
    </template>
  </section>
</template>

<style scoped>
.shared__head {
  margin-bottom: 1.25rem;
}

.shared__refusal {
  max-width: 32rem;
  margin: 4rem auto;
  text-align: center;
}

.shared__footer {
  margin-top: 1.25rem;
  text-align: right;
}

.muted {
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

/* Телефон: заголовок и подпись прижимаются к тому же краю, что и панели. */
@media (max-width: 40rem) {
  .shared__head {
    margin-bottom: 1rem;
  }

  .shared__refusal {
    margin: 2rem auto;
  }
}
</style>
