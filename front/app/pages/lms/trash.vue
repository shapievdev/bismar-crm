<script setup lang="ts">
import type { TrashedMaterial } from '~/types/lms'

/**
 * Корзина: удалённое, но ещё не стёртое.
 *
 * Удаление курса и документа всегда было мягким — за одним стоит чужой
 * прогресс, за другим отметки об ознакомлении. Здесь это наконец видно: что
 * выброшено, кем и когда, и как вернуть.
 */
definePageMeta({ middleware: 'auth', permission: 'courses.delete' })
useHead({ title: 'Корзина' })

const { isAdmin } = useAuth()
const { fetchTrash, restoreTrashed, purgeTrashed } = useLmsApi()
const dialog = useAppDialog()

const { data, refresh } = await useAsyncData('lms.trash', () => fetchTrash())

const items = computed<TrashedMaterial[]>(() => data.value?.data ?? [])

const busyId = ref<string | null>(null)
const errorMessage = ref<string | null>(null)

/** Курс №3 и документ №3 — разные строки: ключ собирается из вида и номера. */
function keyOf(item: TrashedMaterial): string {
  return `${item.kind}-${item.id}`
}

async function restore(item: TrashedMaterial) {
  busyId.value = keyOf(item)
  errorMessage.value = null

  try {
    await restoreTrashed(item.kind, item.id)
    await refresh()
  }
  catch {
    errorMessage.value = 'Не удалось вернуть — попробуйте ещё раз.'
  }
  finally {
    busyId.value = null
  }
}

/**
 * Стереть насовсем — с подтверждением и названием в вопросе.
 *
 * Это единственное действие во всей базе знаний, после которого возвращать
 * нечего: уйдут уроки, тесты, попытки сотрудников и приложенные файлы.
 */
async function purge(item: TrashedMaterial) {
  const isSure = await dialog.confirm({
    title: `Стереть «${item.title}» насовсем?`,
    message: item.kind === 'course' && item.lessons
      ? `Уйдут ${item.lessons} ${pluralise(item.lessons, 'урок', 'урока', 'уроков')}, тесты, попытки сотрудников и приложенные файлы. Вернуть будет нельзя.`
      : 'Уйдут проверка, попытки сотрудников и приложенные файлы. Вернуть будет нельзя.',
    confirmLabel: 'Стереть насовсем',
    danger: true,
  })

  if (!isSure) {
    return
  }

  busyId.value = keyOf(item)
  errorMessage.value = null

  try {
    await purgeTrashed(item.kind, item.id)
    await refresh()
  }
  catch {
    errorMessage.value = 'Не удалось стереть — попробуйте ещё раз.'
  }
  finally {
    busyId.value = null
  }
}

function when(value: string | null): string {
  return value ? new Date(value).toLocaleString('ru-RU') : ''
}
</script>

<template>
  <section>
    <header class="head">
      <h1 class="page-title">
        Корзина
      </h1>
      <p class="page-subtitle">
        Удалённые курсы и документы. Они не видны никому, кроме этой страницы, но
        цело всё: уроки, тесты и прогресс сотрудников. Вернуть можно в любой момент.
      </p>
    </header>

    <p v-if="errorMessage" class="alert alert--danger" role="alert">
      {{ errorMessage }}
    </p>

    <p v-if="!items.length" class="faint empty">
      Корзина пуста.
    </p>

    <ul v-else class="items">
      <li v-for="item in items" :key="keyOf(item)" class="card item">
        <div class="item__body">
          <p class="item__title">
            {{ item.title }}
            <span class="badge">{{ item.kind === 'course' ? 'курс' : 'документ' }}</span>
          </p>

          <p class="faint item__meta">
            Удалил {{ item.deleted_by ?? 'неизвестно кто' }}, {{ when(item.deleted_at) }}
            <template v-if="item.author"> · автор {{ item.author }}</template>
            <template v-if="item.lessons">
              · {{ item.lessons }} {{ pluralise(item.lessons, 'урок', 'урока', 'уроков') }}
            </template>
          </p>
        </div>

        <div class="item__actions">
          <button
            type="button"
            class="button-secondary button-sm"
            :disabled="busyId === keyOf(item)"
            @click="restore(item)"
          >
            Вернуть
          </button>

          <!-- Стирает только администратор: после этого возвращать нечего. -->
          <button
            v-if="isAdmin"
            type="button"
            class="button-ghost button-sm item__purge"
            :disabled="busyId === keyOf(item)"
            @click="purge(item)"
          >
            Стереть насовсем
          </button>
        </div>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.head {
  margin-bottom: 1.5rem;
}

.page-subtitle {
  max-width: 62ch;
}

.empty {
  max-width: 62ch;
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
  align-items: center;
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
  gap: 0.5rem;
  margin: 0;
  font-weight: 550;
}

.item__meta {
  margin: 0.2rem 0 0;
  font-size: 0.82rem;
}

.item__actions {
  display: flex;
  gap: 0.35rem;
  flex-shrink: 0;
}

/* Красным — только под курсором: стирание стоит последним в ряду и кричать о
   себе из каждой строки не должно. */
.item__purge:hover {
  color: var(--color-danger);
}

@media (max-width: 40rem) {
  .item {
    flex-wrap: wrap;
  }

  .item__actions {
    width: 100%;
    justify-content: flex-end;
  }
}
</style>
