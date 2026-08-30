<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Главная' })

const { can } = useAuth()
const { fetchFeed } = useNewsApi()

const { data, pending, error } = await useAsyncData('news.feed', () => fetchFeed())

const news = computed(() => data.value?.data ?? [])

/**
 * Что ещё ждёт ознакомления.
 *
 * Считается по загруженной странице, а не запросом: значок на рельсе спрашивает
 * число у сервера, а здесь речь о том, что человек видит прямо сейчас.
 */
const pending_ = computed(() => news.value.filter(item => item.awaits_acknowledgement))

function day(value: string | null): string {
  return value ? new Date(value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' }) : ''
}
</script>

<template>
  <section>
    <header class="head">
      <div>
        <h1 class="page-title">
          Новости компании
        </h1>
        <p class="page-subtitle">
          <template v-if="pending_.length">
            Ждут ознакомления: {{ pending_.length }}.
          </template>
          <template v-else-if="!news.length">
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

    <div v-else-if="pending" class="tiles">
      <div v-for="n in 3" :key="n" class="card item">
        <div class="skeleton skeleton-line" />
      </div>
    </div>

    <UiEmptyState
      v-else-if="!news.length"
      title="Новостей пока нет"
      description="Когда что-то важное появится, оно будет здесь."
    />

    <div v-else class="tiles">
      <NuxtLink
        v-for="item in news"
        :key="item.id"
        :to="`/news/${item.slug}`"
        class="card item"
        :class="{ 'item--unread': item.awaits_acknowledgement }"
      >
        <div class="item__head">
          <span v-if="item.is_pinned" class="badge" title="Закреплена">Закреплена</span>
          <span v-if="item.awaits_acknowledgement" class="badge badge--warning">
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

        <!-- Автор прижат к низу плитки: в ряду разной высоты подписи иначе
             оказываются на разных уровнях и ряд читается рвано. -->
        <span v-if="item.author" class="faint item__author">{{ item.author.name }}</span>
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

/*
 * Плиткой: новости просматривают выборочно — по заголовку, метке и дате, — а в
 * один столбец их помещалось на экран три штуки. Колонок столько, сколько
 * влезает по 18rem; на телефоне это одна, и лента там выглядит как раньше.
 */
.tiles {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr));
  gap: 0.9rem;
}

.item {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  /* Во всю высоту ячейки: соседи по ряду выравниваются друг по другу. */
  height: 100%;
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

/*
 * Заголовок и выжимка обрезаются по строкам, а не по буквам: плитки в ряду
 * равняются по самой высокой, и одна многословная новость иначе растягивала бы
 * весь ряд.
 */
.item__title {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  overflow: hidden;
  margin: 0;
  font-size: 1.1rem;
  font-weight: 600;
}

.item__excerpt {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 3;
  line-clamp: 3;
  overflow: hidden;
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.item__author {
  margin-top: auto;
  padding-top: 0.2rem;
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
