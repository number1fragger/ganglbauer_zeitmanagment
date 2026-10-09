<template>
  <main class="not-found">
    <h1 class="page-title">Diese Seite gibt es nicht</h1>
    <p class="page-sub">Der Link ist veraltet oder falsch geschrieben.</p>
    <RouterLink class="btn btn--primary" to="/">Zur Startseite</RouterLink>
  </main>
</template>

<style scoped>
.not-found {
  display: grid;
  justify-items: start;
  gap: 16px;
  max-width: 420px;
  margin: 15vh auto;
  padding: 24px;
}
</style>
