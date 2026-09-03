<script setup lang="ts">
import { ApiValidationError } from '~/composables/useAuth'
import type { Attestation } from '~/types/lms'

/**
 * Работы, сданные этому человеку на аттестацию.
 *
 * Права на страницу нет и не нужно: сюда попадает то, что автор теста назначил
 * проверять именно ему. Назначение и есть право — у того, кому ничего не
 * сдавали, страница просто пуста.
 */
definePageMeta({ middleware: 'auth', permission: 'courses.view' })
useHead({ title: 'Аттестация' })

const { fetchAttestations, fetchAttestation, judgeAttestation } = useLmsApi()
const { refreshBadges } = useNavigation()

const { data, refresh } = await useAsyncData('lms.attestations', () => fetchAttestations())

const works = computed<Attestation[]>(() => data.value?.data ?? [])
const waiting = computed(() => works.value.filter(work => work.status === 'pending'))
const judged = computed(() => works.value.filter(work => work.status !== 'pending'))

/* ---------- Открытая работа ---------- */

const opened = ref<Attestation | null>(null)
const isLoading = ref(false)
const errorMessage = ref<string | null>(null)

/** Комментарий пишется один на открытую работу и стирается вместе с ней. */
const comment = ref('')
const isJudging = ref(false)

async function open(work: Attestation) {
  if (opened.value?.id === work.id) {
    close()

    return
  }

  isLoading.value = true
  errorMessage.value = null
  comment.value = ''

  try {
    opened.value = (await fetchAttestation(work.id)).data
  }
  catch {
    errorMessage.value = 'Не удалось открыть работу.'
  }
  finally {
    isLoading.value = false
  }
}

function close() {
  opened.value = null
  comment.value = ''
}

async function judge(isAccepted: boolean) {
  const work = opened.value

  if (!work) {
    return
  }

  isJudging.value = true
  errorMessage.value = null

  try {
    await judgeAttestation(work.id, { is_accepted: isAccepted, comment: comment.value.trim() || null })

    close()
    await refresh()
    // Значок в навигации считает ждущие работы — после вердикта их стало меньше.
    await refreshBadges()
  }
  catch (caught) {
    errorMessage.value = caught instanceof ApiValidationError
      ? caught.errors.comment?.[0] ?? 'Проверьте заполненное.'
      : 'Не удалось сохранить вердикт.'
  }
  finally {
    isJudging.value = false
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
        Аттестация
      </h1>
      <p class="page-subtitle">
        Работы, которые сдали вам на проверку. Приложение их не оценивает — оно только
        доносит: верны ли числа в таблице, знаете вы, а не оно.
      </p>
    </header>

    <p v-if="errorMessage" class="alert alert--danger" role="alert">
      {{ errorMessage }}
    </p>

    <p v-if="!works.length" class="faint empty">
      Вам пока ничего не сдавали. Работы появятся здесь, когда автор теста назначит вас
      проверяющим, а сотрудник отправит ответы.
    </p>

    <template v-else>
      <h2 v-if="waiting.length" class="group">
        Ждут ответа · {{ waiting.length }}
      </h2>

      <ul v-if="waiting.length" class="works">
        <li v-for="work in waiting" :key="work.id" class="card work">
          <button type="button" class="work__row" :aria-expanded="opened?.id === work.id" @click="open(work)">
            <span class="work__who">{{ work.learner.name }}</span>
            <span class="work__what">
              {{ work.material?.title ?? work.quiz.title }}
              <span v-if="work.material?.course" class="faint">· {{ work.material.course }}</span>
            </span>
            <span class="faint work__when">{{ when(work.completed_at) }}</span>
            <span class="work__open">{{ opened?.id === work.id ? 'Свернуть' : 'Открыть' }}</span>
          </button>

          <template v-if="opened?.id === work.id">
            <p v-if="isLoading" class="faint">
              Открываем…
            </p>

            <template v-else-if="opened.review">
              <!-- Ключ и эталоны открыты: проверяющий сверяет работу с ними, а
                   не угадывает замысел автора теста. -->
              <QuizReviewPanel :review="opened.review" />

              <div class="verdict">
                <label :for="`comment-${work.id}`">Что написать сотруднику</label>
                <textarea
                  :id="`comment-${work.id}`"
                  v-model="comment"
                  class="input"
                  rows="3"
                  placeholder="При отказе обязательно: без объяснения человек не поймёт, что исправлять"
                />

                <div class="verdict__actions">
                  <button type="button" class="button-primary" :disabled="isJudging" @click="judge(true)">
                    Зачесть
                  </button>
                  <button type="button" class="button-secondary" :disabled="isJudging" @click="judge(false)">
                    Не зачесть
                  </button>
                  <NuxtLink v-if="work.material?.url" :to="work.material.url" class="link faint">
                    {{ work.material.kind === 'document' ? 'Открыть документ' : 'Открыть урок' }}
                  </NuxtLink>
                </div>
              </div>
            </template>
          </template>
        </li>
      </ul>

      <h2 v-if="judged.length" class="group">
        Разобранные
      </h2>

      <ul v-if="judged.length" class="works">
        <li v-for="work in judged" :key="work.id" class="card work work--done">
          <div class="work__row work__row--static">
            <span class="work__who">{{ work.learner.name }}</span>
            <span class="work__what">{{ work.material?.title ?? work.quiz.title }}</span>
            <span class="faint work__when">{{ when(work.reviewed_at) }}</span>
            <span :class="work.status === 'passed' ? 'pass' : 'fail'">{{ work.status_label }}</span>
          </div>

          <p v-if="work.comment" class="work__comment faint">
            {{ work.comment }}
          </p>
        </li>
      </ul>
    </template>
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

.group {
  margin: 1.5rem 0 0.6rem;
  font-size: 1rem;
  font-weight: 550;
}

.works {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.work {
  padding: 0.9rem 1.1rem;
}

.work__row {
  display: grid;
  grid-template-columns: minmax(8rem, 14rem) minmax(0, 1fr) auto auto;
  align-items: baseline;
  gap: 0.9rem;
  width: 100%;
  border: none;
  background: none;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.work__row--static {
  cursor: default;
}

.work__who {
  font-weight: 550;
}

.work__when {
  font-size: 0.82rem;
  font-variant-numeric: tabular-nums;
}

.work__open {
  text-decoration: underline;
  text-underline-offset: 0.2em;
  font-size: 0.87rem;
}

.work__comment {
  margin: 0.5rem 0 0;
  white-space: pre-wrap;
  font-size: 0.87rem;
}

.verdict {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  margin-top: 1rem;
  padding-top: 1rem;
  border-top: 1px solid var(--color-border);
}

.verdict label {
  font-size: 0.875rem;
  font-weight: 500;
}

.verdict__actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.6rem;
  margin-top: 0.35rem;
}

.pass { color: var(--color-success); }
.fail { color: var(--color-danger); }

@media (max-width: 48rem) {
  .work__row {
    grid-template-columns: 1fr;
    gap: 0.2rem;
  }
}
</style>
