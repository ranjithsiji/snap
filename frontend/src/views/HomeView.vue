<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { CdxButton, CdxInfoChip, CdxMessage, CdxProgressBar } from '@wikimedia/codex'
import { api } from '@/api'
import { useSession } from '@/stores/session'
import { formatDeadline } from '@/format'

const session = useSession()
const router = useRouter()

const rounds = ref([])
const loading = ref(true)
const error = ref(null)

onMounted(async () => {
  try {
    const data = await api.get('/my/rounds')
    rounds.value = data.rounds
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="page-head">
    <div>
      <h1 class="page-title">My rounds</h1>
      <p class="page-subtitle">Rounds you have been invited to judge</p>
    </div>
    <CdxButton v-if="session.isOrganizer" @click="router.push({ name: 'campaigns' })">
      Manage campaigns
    </CdxButton>
  </div>

  <CdxMessage v-if="error" type="error">{{ error }}</CdxMessage>
  <CdxProgressBar v-if="loading" aria-label="Loading rounds" />

  <div v-else-if="rounds.length === 0" class="card empty">
    <p>You are not currently a juror in any active round.</p>
    <CdxButton
      v-if="session.isOrganizer"
      action="progressive"
      weight="primary"
      @click="router.push({ name: 'campaigns' })"
    >
      Go to campaigns
    </CdxButton>
  </div>

  <div v-else class="stack">
    <div v-for="round in rounds" :key="round.id" class="card">
      <div class="row wrap">
        <div>
          <h2 class="section-title">{{ round.name }}</h2>
          <p class="muted" style="margin: 0; font-size: 0.875rem">
            {{ round.campaignName }} · {{ round.votingMethodLabel }} ·
            {{ formatDeadline(round.votingDeadline) }}
          </p>
        </div>

        <span class="spacer"></span>

        <CdxInfoChip :status="round.state === 'active' ? 'success' : 'notice'">
          {{ round.state }}
        </CdxInfoChip>

        <CdxButton
          v-if="round.acceptsVotes"
          action="progressive"
          weight="primary"
          @click="router.push({ name: 'vote', params: { id: round.id } })"
        >
          Start judging
        </CdxButton>
        <span v-else class="muted">Not open for voting</span>
      </div>

      <p v-if="round.details" class="muted" style="margin: 0.75rem 0 0; font-size: 0.875rem">
        {{ round.details }}
      </p>
    </div>
  </div>
</template>
