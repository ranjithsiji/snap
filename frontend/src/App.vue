<script setup>
import { onMounted } from 'vue'
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { CdxButton, CdxIcon, CdxInfoChip } from '@wikimedia/codex'
import { cdxIconBright, cdxIconLogIn, cdxIconLogOut, cdxIconMoon } from '@wikimedia/codex-icons'
import { useSession } from '@/stores/session'
import { useTheme } from '@/stores/theme'

const session = useSession()
const theme = useTheme()
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
        <img src="/logo.svg" alt="" class="brand-mark" width="28" height="28" />
        <span>Snap</span>
      </RouterLink>

      <nav class="topnav" aria-label="Main">
        <template v-if="session.isAuthenticated">
          <RouterLink :to="{ name: 'my-rounds' }">My rounds</RouterLink>
          <RouterLink :to="{ name: 'projects' }">Projects</RouterLink>
          <RouterLink v-if="session.isOrganizer" :to="{ name: 'campaigns' }">Campaigns</RouterLink>
          <RouterLink v-if="session.isAdministrator" :to="{ name: 'admin' }">Administration</RouterLink>
        </template>
        <RouterLink :to="{ name: 'about' }">About</RouterLink>
      </nav>

      <div v-if="session.isAuthenticated" class="topbar-user">
        <strong>{{ session.user.username }}</strong>
        <CdxInfoChip>{{ session.user.role }}</CdxInfoChip>
        <CdxButton
          weight="quiet"
          class="bar-button"
          :aria-label="theme.mode === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
          @click="theme.toggle()"
        >
          <CdxIcon :icon="theme.mode === 'dark' ? cdxIconBright : cdxIconMoon" />
        </CdxButton>
        <CdxButton weight="quiet" class="bar-button" @click="logout">
          <CdxIcon :icon="cdxIconLogOut" /> Log out
        </CdxButton>
      </div>

      <!-- Without this a signed-out visitor has no way in from the page
           they land on. -->
      <div v-else class="topbar-user">
        <CdxButton
          weight="quiet"
          class="bar-button"
          :aria-label="theme.mode === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
          @click="theme.toggle()"
        >
          <CdxIcon :icon="theme.mode === 'dark' ? cdxIconBright : cdxIconMoon" />
        </CdxButton>
        <CdxButton
          action="progressive"
          weight="primary"
          @click="router.push({ name: 'login' })"
        >
          <CdxIcon :icon="cdxIconLogIn" /> Sign in
        </CdxButton>
      </div>
    </header>

    <main class="content">
      <RouterView />
    </main>

    <footer class="sitefooter">
      <p class="sitefooter-line">
        <strong>Snap</strong> — judging for Wiki Loves campaigns on
        <a href="https://commons.wikimedia.org/">Wikimedia Commons</a>.
      </p>
      <nav class="sitefooter-links" aria-label="Footer">
        <RouterLink :to="{ name: 'about' }">About</RouterLink>
        <a href="https://github.com/ranjithsiji/snap">Source</a>
        <a href="https://phabricator.wikimedia.org/tag/tool-snap/">Report a problem</a>
      </nav>
    </footer>
  </div>
</template>
