<script setup lang="ts">
import type { Course } from '~/types/lms'

const props = defineProps<{ course: Course }>()

const progress = computed(() => props.course.enrollment?.progress ?? null)
</script>

<template>
  <article class="card card--raised course">
    <div class="course__badges">
      <span v-if="course.status !== 'published'" class="badge badge--warning">
        {{ course.status_label }}
      </span>
      <span v-if="course.enrollment?.is_completed" class="badge badge--highlight">Пройден</span>
      <span v-else-if="course.enrollment" class="badge">В процессе</span>
      <span v-if="course.category" class="badge">{{ course.category.name }}</span>
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
  </article>
</template>

<style scoped>
.course {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
  padding: 1.25rem 1.35rem 1.4rem;
  transition: box-shadow 0.18s ease, transform 0.18s ease;
}

.course:hover {
  box-shadow: var(--shadow-md);
  transform: translateY(-3px);
}

.course__badges {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  min-height: 1.4rem;
}

.course__title {
  margin: 0;
  font-size: 1.15rem;
  font-weight: 500;
  letter-spacing: -0.015em;
}

.course__title a {
  color: inherit;
  text-decoration: none;
}

.course__title a::after {
  /* Makes the whole card clickable while keeping one real link for assistive
     technology and for opening in a new tab. */
  content: '';
  position: absolute;
  inset: 0;
}

.course__summary {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.course__meta {
  display: flex;
  gap: 0.35rem;
  margin-top: auto;
  padding-top: 0.35rem;
  color: var(--color-text-faint);
  font-size: 0.84rem;
}

.course__progress {
  margin-top: 0.15rem;
}

@media (prefers-reduced-motion: reduce) {
  .course,
  .course:hover {
    transition: none;
    transform: none;
  }
}
</style>