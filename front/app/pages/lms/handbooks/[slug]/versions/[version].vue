<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'handbooks.update' })

const route = useRoute()
const slug = computed(() => String(route.params.slug))
const copy = useMaterialSection('handbooks')

const { fetchRegulation } = useMaterialsApi('handbooks')

// Название справочника — ради ссылки «назад», см. страницу версии документа.
const { data } = await useAsyncData(
  () => `lms.handbooks.${slug.value}.title`,
  async () => (await fetchRegulation(slug.value)).data,
)
</script>

<template>
  <MaterialVersionEditor
    :target="{ kind: 'handbooks', slug }"
    :material-title="data?.title ?? copy.materialLabel"
    :article-placeholder="copy.articlePlaceholder"
    credit-label="справочник зачтётся прочитанным"
  />
</template>
