<script setup lang="ts">
import type { JourneyStage } from '~/types/onboarding'
import type { Quiz, QuizAttempt, QuizReview } from '~/types/lms'
defineProps<{
  stage: JourneyStage | null, next: JourneyStage | null, quiz: Quiz | null,
  result: QuizAttempt | null, submittingQuiz: boolean, submittingTaskId: number | null,
  interactive: boolean, passedMessage: string, failedMessage: string,
  loadReview?: (id: number) => Promise<QuizReview | null>
}>()
const emit = defineEmits<{
  submitTask: [id: number], openQuiz: [], closeQuiz: [], retry: [],
  submitQuiz: [answers: Record<number, number[] | string | string[][]>]
}>()
const api = useOnboardingApi()
</script>
<template>
  <div class="session">
    <template v-if="quiz && stage">
      <QuizRunner :quiz="quiz" :is-submitting="submittingQuiz" :result="result ? { score: result.score, passed: result.passed, review: result.review } : null"
        rule="" :passed-note="passedMessage" :failed-note="failedMessage" @submit="emit('submitQuiz',$event)" @retry="emit('retry')" />
      <button type="button" class="button-ghost" @click="emit('closeQuiz')">← К этапу</button>
    </template>
    <OnboardingStageCard v-else-if="stage" :stage="stage" :next="next" :load-review="loadReview ?? (async id => (await api.fetchAttempt(id)).data.review ?? null)"
      :interactive="interactive" :submitting-task-id="submittingTaskId" @submit-task="emit('submitTask',$event)" @open-quiz="emit('openQuiz')" />
  </div>
</template>
<style scoped>
.session { display:grid; grid-template-columns:minmax(0,1fr); min-width:0; max-width:100%; gap:12px; overflow-wrap:anywhere; }
.session :deep(.stage) { min-width:0; }
.session :deep(.stage__head) { display:grid; grid-template-columns:48px minmax(0,1fr); }
.session :deep(.stage__head > .badge) { grid-column:2; justify-self:start; white-space:normal; max-width:100%; }
.session :deep(.stage__heading) { min-width:0; }
.session :deep(.meeting__head) { flex-wrap:wrap; }
.session :deep(.meeting__head > .badge) { white-space:normal; }
.session :deep(.stage) { border:2px solid var(--color-border); border-radius:26px; box-shadow:0 5px 0 var(--color-border); }
.session :deep(.stage__title) { font-weight:700; }
.session :deep(.stage__icon) { border-radius:18px; }
.session :deep(.button-primary) { box-shadow:0 4px 0 color-mix(in srgb,var(--color-accent),#000 25%); font-weight:700; padding-top:12px;padding-bottom:12px; }
.session :deep(.button-primary:active) { transform:translateY(3px); box-shadow:none; }
@media(max-width:600px) { .session :deep(.stage) { padding:18px; } .session :deep(.stage__title) { font-size:19px; } }
</style>
