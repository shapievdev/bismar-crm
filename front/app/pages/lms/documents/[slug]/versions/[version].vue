<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'documents.update' })

const route = useRoute()
const slug = computed(() => String(route.params.slug))
const copy = useMaterialSection('documents')

const { fetchRegulation } = useMaterialsApi('documents')

// Название документа — ради ссылки «назад»: редактор версии знает только про
// саму версию, и заголовок материала ему приносят снаружи.
const { data } = await useAsyncData(
  () => `lms.documents.${slug.value}.title`,
  async () => (await fetchRegulation(slug.value)).data,
)
</script>

<template>
  <MaterialVersionEditor
    :target="{ kind: 'documents', slug }"
    :material-title="data?.title ?? copy.materialLabel"
    :article-placeholder="copy.articlePlaceholder"
    credit-label="документ зачтётся прочитанным"
  />
</template>
