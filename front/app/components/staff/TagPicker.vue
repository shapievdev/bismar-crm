<script setup lang="ts">
import type { StaffTag, User } from '~/types/auth'

/**
 * Ручные теги на карточке сотрудника.
 *
 * Отдельным управлением, а не полем формы: тег вешают по ходу разговора —
 * «этого в резерв», «этому продлили испытательный», — и ради одной пометки
 * открывать правку всей карточки незачем. Сохраняется сразу, без кнопки: список
 * из шести галочек не тот случай, когда нужен черновик.
 *
 * Теги по стажу сюда не попадают. «Стажёр», «новичок», «старожил» считаются из
 * даты приёма и показываются в отчёте о движении персонала; поставь их рядом с
 * ручными — и однажды кто-нибудь попробовал бы снять «старожила» галочкой.
 */
const props = defineProps<{
  person: User
  /** Может ли смотрящий вешать теги, или ему остаётся их читать. */
  editable: boolean
}>()

const emit = defineEmits<{ changed: [User] }>()

const { fetchStaffTags, updateStaffTagsOf, createStaffTag } = useAdminApi()

const own = computed<StaffTag[]>(() => props.person.tags ?? [])

const open = ref(false)
const catalogue = ref<StaffTag[]>([])
const loaded = ref(false)
const saving = ref(false)
const failure = ref<string | null>(null)
const fresh = ref('')

// Справочник приезжает, только когда список раскрыли: держать его наготове
// ради кнопки, до которой доходят изредка, незачем.
watch(open, async (isOpen) => {
  if (isOpen && !loaded.value) {
    catalogue.value = (await fetchStaffTags()).data
    loaded.value = true
  }
})

const chosen = computed(() => new Set(own.value.map(tag => tag.id)))

async function toggle(tag: StaffTag): Promise<void> {
  const next = chosen.value.has(tag.id)
    ? own.value.filter(one => one.id !== tag.id).map(one => one.id)
    : [...own.value.map(one => one.id), tag.id]

  await save(next)
}

async function add(): Promise<void> {
  const name = fresh.value.trim()

  if (name === '') {
    return
  }

  saving.value = true
  failure.value = null

  try {
    const tag = (await createStaffTag(name)).data

    catalogue.value = [...catalogue.value, tag]
    fresh.value = ''

    await save([...own.value.map(one => one.id), tag.id])
  }
  catch {
    // Чаще всего — тег с таким названием уже есть. Об этом и говорим: гадать,
    // почему кнопка не сработала, человек не должен.
    failure.value = 'Не удалось завести тег: возможно, такой уже есть.'
  }
  finally {
    saving.value = false
  }
}

async function save(ids: number[]): Promise<void> {
  saving.value = true
  failure.value = null

  try {
    emit('changed', (await updateStaffTagsOf(props.person, ids)).data)
  }
  catch {
    failure.value = 'Не удалось сохранить теги.'
  }
  finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="tags">
    <span v-for="tag in own" :key="tag.id" class="badge badge--accent">{{ tag.name }}</span>
    <span v-if="!own.length" class="tags__none">не отмечены</span>

    <button
      v-if="editable"
      type="button"
      class="button-ghost button-sm"
      :aria-expanded="open"
      @click="open = !open"
    >
      {{ open ? 'Свернуть' : 'Изменить' }}
    </button>

    <div v-if="open && editable" class="tags__editor card card--raised">
      <p v-if="failure" class="alert alert--danger" role="alert">
        {{ failure }}
      </p>

      <p v-if="!catalogue.length && loaded" class="tags__none">
        Справочник пуст. Заведите первый тег — он станет доступен всем карточкам.
      </p>

      <label v-for="tag in catalogue" :key="tag.id" class="tags__option">
        <input
          type="checkbox"
          :checked="chosen.has(tag.id)"
          :disabled="saving"
          @change="toggle(tag)"
        >
        <span>{{ tag.name }}</span>
      </label>

      <!-- Новый тег заводится здесь же: отправлять кадровика в отдельный
           справочник ради одного слова значит не завести его вовсе. -->
      <form class="tags__new" @submit.prevent="add">
        <input
          v-model="fresh"
          class="input"
          placeholder="Новый тег"
          maxlength="255"
        >
        <button type="submit" class="button-secondary button-sm" :disabled="saving || !fresh.trim()">
          Завести
        </button>
      </form>
    </div>
  </div>
</template>

<style scoped>
.tags {
  position: relative;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem;
}

.tags__none {
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.tags__editor {
  position: absolute;
  z-index: 20;
  top: calc(100% + 0.4rem);
  left: 0;
  display: flex;
  width: min(20rem, calc(100vw - 2rem));
  flex-direction: column;
  gap: 0.4rem;
  padding: 0.85rem 0.95rem;
  box-shadow: var(--shadow-lg);
}

.tags__option {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
  cursor: pointer;
}

.tags__new {
  display: flex;
  gap: 0.4rem;
  padding-top: 0.5rem;
  border-top: 1px solid var(--color-border);
}

.tags__new .input {
  min-width: 0;
  flex: 1;
}
</style>
