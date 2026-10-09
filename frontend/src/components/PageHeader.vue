<script setup lang="ts">
defineProps<{ title: string; subtitle?: string }>()
defineSlots<{ default?(): unknown; actions?(): unknown }>()
</script>

<template>
  <header class="page-header">
    <div class="page-header__titles">
      <h1 class="page-title">{{ title }}</h1>
      <p v-if="subtitle" class="page-sub">{{ subtitle }}</p>
    </div>
    <slot />
    <div v-if="$slots.actions" class="page-header__actions">
      <slot name="actions" />
    </div>
  </header>
</template>

<style scoped>
.page-header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px 20px;
  padding: 20px 28px 16px;
}

.page-header__titles {
  min-width: 0;
  margin-right: auto;
}

.page-header__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

@media (max-width: 699px) {
  .page-header {
    padding: 16px 16px 12px;
  }
}
</style>
