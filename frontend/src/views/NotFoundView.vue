<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <div class="card">
    <h1>Seite nicht gefunden</h1>
    <p class="muted">Diese Adresse gibt es nicht.</p>
    <RouterLink to="/">Zurueck zum Dashboard</RouterLink>
  </div>
</template>
