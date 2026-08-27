<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Главная' })

const { user, can } = useAuth()
const { fetchFeed } = useNewsApi()

const { data, pending, error } = await useAsyncData('news.feed', () => fetchFeed())

const news = computed(() => data.value?.data ?? [])

/**
 * Что ещё ждёт ознакомления.
 *
 * Считается по загруженной странице, а не запросом: значок на рельсе спрашивает
 * число у сервера, а здесь речь о том, что человек видит прямо сейчас.
 */
const pending_ = computed(() => news.value.filter(item => item.requires_acknowledgement && !item.is_acknowledged))

function day(value: string | null): string {
  return value ? new Date(value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' }) : ''
}
</script>

<template>
  <section>
    <header class="head">
      <div>
        <!-- По имени: приветствие, начатое с фамилии, читается как вызов. -->
        <h1 class="page-title">
          Здравствуйте, {{ user?.first_name }}
        </h1>
        <p class="page-subtitle">
          <template v-if="pending_.length">
            Ждут ознакомления: {{ pending_.length }}.
          </template>
          <template v-else-if="news.length">
            Новости компании.
          </template>
          <template v-else>
            Здесь появляются новости компании.
          </template>
        </p>
      </div>

      <NuxtLink v-if="can('news.manage')" to="/news/new" class="button-primary">
        Написать новость
      </NuxtLink>
    </header>

    <p v-if="error" class="alert alert--danger" role="alert">
      Не удалось загрузить новости.
    </p>

    <div v-else-if="pending" class="stack">
      <div v-for="n in 2" :key="n" class="card item">
        <div class="skeleton skeleton-line" />
      </div>
    </div>

    <UiEmptyState
      v-else-if="!news.length"
      title="Новостей пока нет"
      description="Когда что-то важное появится, оно будет здесь."
    />

    <div v-else class="stack">
      <NuxtLink
        v-for="item in news"
        :key="item.id"
        :to="`/news/${item.slug}`"
        class="card item"
        :class="{ 'item--unread': item.requires_acknowledgement && !item.is_acknowledged }"
      >
        <div class="item__head">
          <span v-if="item.is_pinned" class="badge" title="Закреплена">Закреплена</span>
          <span v-if="item.requires_acknowledgement && !item.is_acknowledged" class="badge badge--warning">
            Нужно ознакомиться
          </span>
          <span v-else-if="item.is_acknowledged" class="badge badge--success">Ознакомлен</span>
          <span class="faint item__date">{{ day(item.published_at) }}</span>
        </div>

        <h2 class="item__title">
          {{ item.title }}
        </h2>

        <p v-if="item.excerpt" class="item__excerpt">
          {{ item.excerpt }}
        </p>

        <span v-if="item.author" class="faint">{{ item.author.name }}</span>
      </NuxtLink>
    </div>

    <p v-if="news.length" class="more">
      <NuxtLink to="/news" class="button-secondary button-sm">Все новости</NuxtLink>
    </p>
  </section>
</template>

<style scoped>
.head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.75rem;
}

.head a {
  text-decoration: none;
}

.stack {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.item {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  padding: 1.1rem 1.25rem;
  color: inherit;
  text-decoration: none;
  transition: box-shadow 0.15s ease;
}

.item:hover {
  box-shadow: var(--shadow-md);
}

/* Непрочитанное обязательное помечается краем, а не цветом всей карточки:
   лента из десяти таких перестала бы читаться. */
.item--unread {
  border-left: 3px solid var(--color-warning);
}

.item__head {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.item__date {
  margin-left: auto;
  font-size: 0.825rem;
}

.item__title {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 600;
}

.item__excerpt {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.more {
  margin: 1.25rem 0 0;
}

.more a {
  text-decoration: none;
}

.skeleton-line {
  width: 100%;
  height: 2.5rem;
}

@media (prefers-reduced-motion: reduce) {
  .item { transition: none; }
}
</style>
