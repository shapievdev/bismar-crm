<script setup lang="ts">
/**
 * Предложение включить уведомления — полосой над страницей.
 *
 * Спрашивает приложение, а не браузер: окно разрешения открывается только по
 * нажатию человека. Спросить самим, без жеста, нельзя — на iOS такой вызов не
 * сработает вовсе, а Chrome за спонтанные запросы переводит сайт в «тихий
 * режим», после которого окно не покажется уже никогда.
 *
 * «Не сейчас» откладывает разговор на сутки — и по-настоящему, а не до
 * перезагрузки: отметка живёт в браузере. Полоса, всплывающая на каждой
 * странице после отказа, раздражает сильнее, чем помогает, и её начинают
 * закрывать не глядя — вместе с тем случаем, когда согласились бы.
 */
const push = usePushNotifications()
const { isAuthenticated } = useAuth()

/** Где браузер помнит, до какого времени полосу не показывать. */
const SNOOZED_UNTIL = 'push-prompt.snoozed-until'

/** На сколько откладывает «Не сейчас». */
const SNOOZE_MS = 24 * 60 * 60 * 1000

const snoozedUntil = ref(0)

onMounted(async () => {
  snoozedUntil.value = Number(localStorage.getItem(SNOOZED_UNTIL) ?? 0)

  if (!isAuthenticated.value) {
    return
  }

  await push.refresh()
})

function snooze() {
  snoozedUntil.value = Date.now() + SNOOZE_MS
  localStorage.setItem(SNOOZED_UNTIL, String(snoozedUntil.value))
}

// Вошли уже после загрузки страницы — спрашиваем состояние заново.
watch(isAuthenticated, (value) => {
  if (value) {
    void push.refresh()
  }
})

/** Что показать: предложение, подсказку про домашний экран или запрет. */
const shown = computed<'ask' | 'install' | 'denied' | null>(() => {
  if (!isAuthenticated.value || !push.asked.value || Date.now() < snoozedUntil.value) {
    return null
  }

  if (push.worthAsking.value) {
    return 'ask'
  }

  if (push.needsInstall.value) {
    return 'install'
  }

  // Запрет снимают в настройках браузера — сказать об этом стоит, но только
  // тому, кто уведомления не включил и не запретил их насовсем в прошлый раз.
  return push.permission.value === 'denied' && !push.enabled.value ? 'denied' : null
})
</script>

<template>
  <aside v-if="shown" class="card prompt" role="status">
    <span class="prompt__icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
        <path d="M13.7 21a2 2 0 0 1-3.4 0" />
      </svg>
    </span>

    <p class="prompt__text">
      <template v-if="shown === 'ask'">
        Включите уведомления — сообщения из мессенджера и новости компании будут приходить, даже когда приложение закрыто.
      </template>
      <template v-else-if="shown === 'install'">
        Добавьте приложение на домашний экран — тогда уведомления о сообщениях и новостях можно будет включить.
      </template>
      <template v-else>
        Уведомления запрещены в настройках браузера. Снимите запрет для этого сайта, и мы снова сможем предупреждать о сообщениях.
      </template>
    </p>

    <span v-if="push.error.value && shown === 'ask'" class="prompt__error">{{ push.error.value }}</span>

    <div class="prompt__actions">
      <button
        v-if="shown === 'ask'"
        type="button"
        class="button-primary button-sm"
        :disabled="push.isBusy.value"
        @click="push.enable()"
      >
        {{ push.isBusy.value ? 'Включаем…' : 'Включить' }}
      </button>

      <button type="button" class="button-ghost button-sm" @click="snooze">
        {{ shown === 'ask' ? 'Не сейчас' : 'Понятно' }}
      </button>
    </div>
  </aside>
</template>

<style scoped>
/*
 * Полоса, а не окно посреди экрана: она сообщает о возможности, а не требует
 * решения — и работать поверх неё можно, не отвечая.
 */
.prompt {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.6rem 0.9rem;
  padding: 0.7rem 1rem;
  margin-bottom: 1.1rem;
}

.prompt__icon {
  display: grid;
  place-items: center;
  width: 2.1rem;
  height: 2.1rem;
  flex-shrink: 0;
  border-radius: 50%;
  background: var(--color-surface-sunken);
  color: var(--color-text-muted);
}

.prompt__text {
  flex: 1;
  min-width: 14rem;
  margin: 0;
  font-size: 0.9rem;
  line-height: 1.4;
}

.prompt__error {
  width: 100%;
  color: var(--color-danger);
  font-size: 0.82rem;
}

.prompt__actions {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin-left: auto;
}

@media (max-width: 40rem) {
  .prompt__actions {
    margin-left: 0;
    width: 100%;
    justify-content: flex-end;
  }
}
</style>
