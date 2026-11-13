<script setup lang="ts">
/** Kleine Kunden-Pille neben dem Aufgabentitel (Aufgabenliste, Kalender). */
defineProps<{
  customer: string
}>()
</script>

<template>
  <span class="customer-tag" :title="`Kunde: ${customer}`">
    <svg
      class="customer-tag__icon"
      width="10"
      height="10"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="2.4"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      <circle cx="12" cy="8" r="4" />
      <path d="M4 21v-1a7 7 0 0 1 16 0v1" />
    </svg>
    <span class="customer-tag__name">{{ customer }}</span>
  </span>
</template>

<style scoped>
.customer-tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  max-width: 45%;
  flex-shrink: 0;
  overflow: hidden;
  padding: 1px 6px;
  border: 1px solid var(--primary-tint);
  border-radius: var(--radius-sm);
  background: var(--primary-soft);
  color: var(--primary);
  font-size: 11px;
  font-weight: 600;
  line-height: 1.5;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.customer-tag__icon {
  flex-shrink: 0;
}

.customer-tag__name {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
