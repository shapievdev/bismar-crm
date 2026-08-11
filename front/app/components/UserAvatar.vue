<script setup lang="ts">
const props = withDefaults(defineProps<{
  name?: string | null
  src?: string | null
  size?: number
}>(), { size: 36 })

/** Falls back to initials, which still tell one colleague from another. */
const initials = computed(() =>
  (props.name ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map(word => word[0]?.toUpperCase() ?? '')
    .join(''),
)

/**
 * Картинка может не загрузиться: ссылка на файл подписана на срок, хранилище
 * стороннее и бывает недоступно. Тогда показываются инициалы — то же, что и у
 * человека без аватарки, — а не значок битого изображения, по которому нельзя
 * даже понять, чьё это место.
 */
const failed = ref(false)

watch(() => props.src, () => {
  failed.value = false
})
</script>

<template>
  <span
    class="avatar"
    :style="{ width: `${size}px`, height: `${size}px`, fontSize: `${Math.round(size * 0.34)}px` }"
    :title="name ?? undefined"
  >
    <img
      v-if="src && !failed"
      :src="src"
      :alt="name ?? ''"
      loading="lazy"
      @error="failed = true"
    >
    <span v-else aria-hidden="true">{{ initials }}</span>
  </span>
</template>

<style scoped>
.avatar {
  display: grid;
  place-items: center;
  flex-shrink: 0;
  overflow: hidden;
  border-radius: var(--radius-pill);
  background: var(--color-surface-sunken);
  color: var(--color-text-muted);
  font-weight: 500;
  line-height: 1;
}

.avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
</style>
