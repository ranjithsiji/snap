<script setup>
import { onMounted } from 'vue'
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { CdxButton, CdxInfoChip } from '@wikimedia/codex'
import { useSession } from '@/stores/session'

const session = useSession()
const router = useRouter()

onMounted(() => {
  if (!session.loaded) session.load()
})

async function logout() {
  await session.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="app">
    <header class="topbar">
      <RouterLink :to="{ name: 'home' }" class="brand">
        <span class="brand-mark">S</span>
        <span>Snap</span>
      </RouterLink>

      <nav v-if="session.isAuthenticated" class="topnav">
        <RouterLink :to="{ name: 'home' }">My rounds</RouterLink>
        <RouterLink :to="{ name: 'projects' }">Projects</RouterLink>
        <RouterLink v-if="session.isOrganizer" :to="{ name: 'campaigns' }">Campaigns</RouterLink>
        <RouterLink v-if="session.isAdministrator" :to="{ name: 'admin' }">Administration</RouterLink>
        <RouterLink :to="{ name: 'about' }">About</RouterLink>
      </nav>

      <!-- Signed-out visitors land on the login page, so About is the only
           thing to offer them in the bar. -->
      <nav v-else class="topnav">
        <RouterLink :to="{ name: 'about' }">About</RouterLink>
      </nav>

      <div v-if="session.isAuthenticated" class="topbar-user">
        <strong>{{ session.user.username }}</strong>
        <CdxInfoChip>{{ session.user.role }}</CdxInfoChip>
        <CdxButton weight="quiet" @click="logout">Log out</CdxButton>
      </div>
    </header>

    <main class="content">
      <RouterView />
    </main>
  </div>
</template>
