<script setup lang="ts">
import type { Course } from '~/types/lms'

const props = defineProps<{ course: Course }>()

/**
 * Courses without a cover get a deterministic gradient derived from the title,
 * so the catalogue still reads as a grid of distinct cards rather than a list
 * of grey boxes — and the same course always looks the same.
 */
const fallbackGradient = computed(() => {
  const seed = [...props.course.title].reduce((total, char) => total + char.charCodeAt(0), 0)
  const hue = seed % 360

  return `linear-gradient(135deg, hsl(${hue} 62% 52%), hsl(${(hue + 48) % 360} 58% 42%))`
})

const initials = computed(() =>
  props.course.title
    .split(/\s+/)
    .slice(0, 2)
    .map(word => word[0]?.toUpperCase() ?? '')
    .join(''),
)

const progress = computed(() => props.course.enrollment?.progress ?? null)
</script>

<template>
  <article class="card course">
    <NuxtLink :to="`/lms/${course.slug}`" class="course__cover-link" :aria-label="course.title">
      <div class="course__cover" :style="course.cover_url ? undefined : { background: fallbackGradient }">
        <img v-if="course.cover_url" :src="course.cover_url" :alt="''" loading="lazy">
        <span v-else class="course__initials" aria-hidden="true">{{ initials }}</span>
      </div>
    </NuxtLink>

    <div class="course__body">
      <div class="course__badges">
        <span v-if="course.status !== 'published'" class="badge badge--warning">
          {{ course.status_label }}
        </span>
        <span v-if="course.enrollment?.is_completed" class="badge badge--success">Пройден</span>
        <span v-else-if="course.enrollment" class="badge badge--accent">В процессе</span>
      </div>

      <h3 class="course__title">
        <NuxtLink :to="`/lms/${course.slug}`">
          {{ course.title }}
        </NuxtLink>
      </h3>

      <p v-if="course.summary" class="course__summary">
        {{ course.summary }}
      </p>

      <div class="course__meta">
        <span>{{ course.lessons_count ?? 0 }} {{ pluralise(course.lessons_count ?? 0, 'урок', 'урока', 'уроков') }}</span>
        <span>·</span>
        <span>{{ course.enrollments_count ?? 0 }} {{ pluralise(course.enrollments_count ?? 0, 'участник', 'участника', 'участников') }}</span>
      </div>

      <UiProgressBar
        v-if="progress !== null"
        :value="progress"
        size="sm"
        :label="`${progress}%`"
        class="course__progress"
      />
    </div>
  </article>
</template>

<style scoped>
.course {
  display: flex;
  flex-direction: column;
  overflow: hidden;
  transition: box-shadow 0.15s ease, transform 0.15s ease;
}

.course:hover {
  box-shadow: var(--shadow-md);
  transform: translateY(-2px);
}

.course__cover-link {
  display: block;
}

.course__cover {
  position: relative;
  display: grid;
  place-items: center;
  aspect-ratio: 16 / 9;
  background: var(--color-surface-sunken);
}

.course__cover img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.course__initials {
  color: #fff;
  font-size: 2rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  text-shadow: 0 1px 3px rgb(0 0 0 / 25%);
}

.course__body {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.95rem 1.05rem 1.1rem;
}

.course__badges {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  min-height: 1.2rem;
}

.course__title {
  margin: 0;
  font-size: 1.02rem;
  font-weight: 600;
}

.course__title a {
  color: inherit;
  text-decoration: none;
}

.course__title a:hover {
  color: var(--color-accent);
}

.course__summary {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.88rem;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.course__meta {
  display: flex;
  gap: 0.35rem;
  margin-top: auto;
  color: var(--color-text-faint);
  font-size: 0.82rem;
}

.course__progress {
  margin-top: 0.2rem;
}

@media (prefers-reduced-motion: reduce) {
  .course,
  .course:hover {
    transition: none;
    transform: none;
  }
}
</style>