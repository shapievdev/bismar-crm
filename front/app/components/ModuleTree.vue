<script setup lang="ts">
import type { CourseModule } from '~/types/lms'

const props = defineProps<{
  courseSlug: string
  modules: CourseModule[]
}>()

const emit = defineEmits<{ changed: [] }>()

const {
  addModule,
  updateModule,
  deleteModule,
  addLesson,
  updateLesson,
  deleteLesson,
} = useLmsApi()
const { can } = useAuth()

const busy = ref(false)
const error = ref<string | null>(null)

/** Id of the module or lesson whose inline form is open. */
const editingModuleId = ref<number | null>(null)
const addingLessonTo = ref<number | null>(null)
const isAddingModule = ref(false)

const moduleDraft = reactive({ title: '', description: '' })
const lessonDraft = reactive({ title: '' })

async function run(operation: () => Promise<unknown>) {
  busy.value = true
  error.value = null

  try {
    await operation()
    emit('changed')
  }
  catch (caught) {
    const failure = caught as { data?: { message?: string } }
    error.value = failure.data?.message ?? 'Не удалось сохранить изменения.'
  }
  finally {
    busy.value = false
  }
}

function startAddModule() {
  isAddingModule.value = true
  editingModuleId.value = null
  moduleDraft.title = ''
  moduleDraft.description = ''
}

function startEditModule(module: CourseModule) {
  editingModuleId.value = module.id
  isAddingModule.value = false
  moduleDraft.title = module.title
  moduleDraft.description = module.description ?? ''
}

async function saveModule() {
  const body = { title: moduleDraft.title, description: moduleDraft.description || null }

  await run(async () => {
    if (editingModuleId.value !== null) {
      await updateModule(editingModuleId.value, body)
    }
    else {
      await addModule(props.courseSlug, body)
    }
  })

  isAddingModule.value = false
  editingModuleId.value = null
}

async function removeModule(module: CourseModule) {
  await run(() => deleteModule(module.id))
}

function startAddLesson(module: CourseModule) {
  addingLessonTo.value = module.id
  lessonDraft.title = ''
}

async function saveLesson(module: CourseModule) {
  await run(() => addLesson(module.id, {
    title: lessonDraft.title,
    content: null,
    video_url: null,
    duration_minutes: null,
  }))

  addingLessonTo.value = null
}

async function removeLesson(lessonId: number) {
  await run(() => deleteLesson(lessonId))
}

/**
 * Reordering is expressed as a position swap, which the API accepts directly —
 * no drag-and-drop library, and it works with a keyboard.
 */
async function moveModule(index: number, delta: number) {
  const current = props.modules[index]
  const neighbour = props.modules[index + delta]

  if (!current || !neighbour) {
    return
  }

  await run(async () => {
    await updateModule(current.id, {
      title: current.title,
      description: current.description,
      position: neighbour.position,
    })
    await updateModule(neighbour.id, {
      title: neighbour.title,
      description: neighbour.description,
      position: current.position,
    })
  })
}

async function moveLesson(module: CourseModule, index: number, delta: number) {
  const lessons = module.lessons ?? []
  const current = lessons[index]
  const neighbour = lessons[index + delta]

  if (!current || !neighbour) {
    return
  }

  await run(async () => {
    await updateLesson(current.id, {
      title: current.title,
      content: current.content ?? null,
      video_url: current.video_url,
      duration_minutes: current.duration_minutes,
      position: neighbour.position,
    })
    await updateLesson(neighbour.id, {
      title: neighbour.title,
      content: neighbour.content ?? null,
      video_url: neighbour.video_url,
      duration_minutes: neighbour.duration_minutes,
      position: current.position,
    })
  })
}
</script>

<template>
  <section class="tree">
    <header class="tree__header">
      <h2>Программа курса</h2>
      <button type="button" class="button-plain" :disabled="busy" @click="startAddModule">
        Добавить модуль
      </button>
    </header>

    <p v-if="error" class="auth-alert" role="alert">
      {{ error }}
    </p>

    <form v-if="isAddingModule || editingModuleId !== null" class="inline-form" @submit.prevent="saveModule">
      <input v-model.trim="moduleDraft.title" placeholder="Название модуля" required>
      <input v-model.trim="moduleDraft.description" placeholder="Описание (необязательно)">
      <button type="submit" class="button-primary" :disabled="busy || !moduleDraft.title">
        Сохранить
      </button>
      <button
        type="button"
        class="button-plain"
        @click="isAddingModule = false; editingModuleId = null"
      >
        Отмена
      </button>
    </form>

    <p v-if="!modules.length" class="empty">
      Модулей пока нет. Начните с первого — уроки добавляются внутрь модуля.
    </p>

    <article v-for="(module, moduleIndex) in modules" :key="module.id" class="module">
      <header class="module__header">
        <div>
          <h3>{{ module.title }}</h3>
          <p v-if="module.description" class="muted">
            {{ module.description }}
          </p>
        </div>

        <div class="module__actions">
          <button type="button" :disabled="busy || moduleIndex === 0" title="Выше" @click="moveModule(moduleIndex, -1)">
            ↑
          </button>
          <button
            type="button"
            :disabled="busy || moduleIndex === modules.length - 1"
            title="Ниже"
            @click="moveModule(moduleIndex, 1)"
          >
            ↓
          </button>
          <button type="button" :disabled="busy" @click="startEditModule(module)">
            Изменить
          </button>
          <button
            v-if="can('courses.delete')"
            type="button"
            class="danger"
            :disabled="busy"
            @click="removeModule(module)"
          >
            Удалить
          </button>
        </div>
      </header>

      <ol class="lessons">
        <li v-for="(lesson, lessonIndex) in module.lessons ?? []" :key="lesson.id" class="lesson">
          <NuxtLink :to="`/lms/${courseSlug}/lessons/${lesson.id}/edit`" class="lesson__title">
            {{ lesson.title }}
          </NuxtLink>

          <span v-if="lesson.has_quiz" class="badge">тест</span>

          <div class="lesson__actions">
            <button type="button" :disabled="busy || lessonIndex === 0" title="Выше" @click="moveLesson(module, lessonIndex, -1)">
              ↑
            </button>
            <button
              type="button"
              :disabled="busy || lessonIndex === (module.lessons?.length ?? 0) - 1"
              title="Ниже"
              @click="moveLesson(module, lessonIndex, 1)"
            >
              ↓
            </button>
            <button
              v-if="can('courses.delete')"
              type="button"
              class="danger"
              :disabled="busy"
              @click="removeLesson(lesson.id)"
            >
              Удалить
            </button>
          </div>
        </li>
      </ol>

      <form v-if="addingLessonTo === module.id" class="inline-form" @submit.prevent="saveLesson(module)">
        <input v-model.trim="lessonDraft.title" placeholder="Название урока" required>
        <button type="submit" class="button-primary" :disabled="busy || !lessonDraft.title">
          Добавить
        </button>
        <button type="button" class="button-plain" @click="addingLessonTo = null">
          Отмена
        </button>
      </form>

      <button v-else type="button" class="button-plain" :disabled="busy" @click="startAddLesson(module)">
        Добавить урок
      </button>
    </article>
  </section>
</template>

<style scoped>
.tree {
  margin-top: 2.5rem;
  padding-top: 1.5rem;
  border-top: 1px solid var(--color-border);
}

.tree__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
}

.tree__header h2 {
  margin: 0;
  font-size: 1.2rem;
}

.muted {
  margin: 0.15rem 0 0;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.empty {
  padding: 1.5rem;
  border: 1px dashed var(--color-border);
  border-radius: var(--radius);
  color: var(--color-text-muted);
  text-align: center;
}

.inline-form {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin: 0.75rem 0;
}

.inline-form input {
  flex: 1;
  min-width: 12rem;
  padding: 0.5rem 0.7rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
}

.module {
  padding: 1rem 1.25rem;
  margin-bottom: 0.75rem;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
}

.module__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

.module__header h3 {
  margin: 0;
  font-size: 1rem;
}

.module__actions,
.lesson__actions {
  display: flex;
  gap: 0.35rem;
}

.module__actions button,
.lesson__actions button {
  padding: 0.25rem 0.55rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
  font-size: 0.8rem;
  cursor: pointer;
}

.module__actions button:disabled,
.lesson__actions button:disabled {
  opacity: 0.4;
  cursor: default;
}

.danger {
  color: var(--color-danger);
  border-color: var(--color-danger);
}

.lessons {
  margin: 0.75rem 0;
  padding: 0;
  list-style: none;
}

.lesson {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.45rem 0;
  border-top: 1px solid var(--color-border);
}

.lesson__title {
  flex: 1;
  color: inherit;
  text-decoration: none;
}

.lesson__title:hover {
  color: var(--color-accent);
}

.badge {
  padding: 0.05rem 0.45rem;
  border: 1px solid var(--color-border);
  border-radius: 999px;
  font-size: 0.72rem;
}

.button-plain {
  padding: 0.4rem 0.85rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
  font-size: 0.9rem;
  cursor: pointer;
}
</style>
