<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { CdxButton, CdxInfoChip, CdxMessage, CdxProgressBar } from '@wikimedia/codex'
import { api } from '@/api'
import { useSession } from '@/stores/session'
import { formatDeadline, formatNumber } from '@/format'

const session = useSession()
const router = useRouter()

const rounds = ref([])

/** What is actually waiting, so the greeting says something useful. */
const waiting = computed(() => {
  if (rounds.value.length === 0) {
    return 'Rounds you have been invited to judge'
  }

  const left = rounds.value.reduce((sum, r) => sum + (r.myProgress?.remaining ?? 0), 0)

  if (left === 0) {
    return 'You are up to date on every round.'
  }

  const open = rounds.value.filter((r) => r.acceptsVotes).length

  return `${formatNumber(left)} image${left === 1 ? '' : 's'} waiting across ` +
    `${open} open round${open === 1 ? '' : 's'}.`
})
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
      <h1 class="page-title">
        Welcome back, {{ session.user?.username }}
      </h1>
      <p class="page-subtitle">{{ waiting }}</p>
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

  <!-- Cards rather than rows: each carries its own progress, and a juror
       scanning this page is choosing which round to work on. -->
  <div v-else class="round-cards">
    <div v-for="round in rounds" :key="round.id" class="card round-card">
      <div class="row wrap round-card-head">
        <CdxInfoChip :status="round.state === 'active' ? 'success' : 'notice'">
          {{ round.state }}
        </CdxInfoChip>
        <span class="spacer"></span>
        <span class="muted round-card-method">{{ round.votingMethodLabel }}</span>
      </div>

      <h2 class="round-card-title">{{ round.name }}</h2>
      <p class="muted round-card-campaign">{{ round.campaignName }}</p>

      <template v-if="round.myProgress">
        <div class="meter round-card-meter">
          <span :style="{ width: `${round.myProgress.percentComplete}%` }"></span>
        </div>
        <p class="round-card-progress">
          <strong>{{ round.myProgress.percentComplete }}%</strong>
          <span class="muted">
            — {{ formatNumber(round.myProgress.remaining) }} of
            {{ formatNumber(round.myProgress.expected) }} left to judge
          </span>
        </p>
      </template>

      <p v-if="round.details" class="muted round-card-details">{{ round.details }}</p>

      <div class="row round-card-foot">
        <span class="muted round-card-deadline">
          {{ formatDeadline(round.votingDeadline) }}
        </span>
        <span class="spacer"></span>
        <!-- A jury meeting has its own screen — a shared discussion and
             ranking, not the independent per-juror voting flow. Sending
             a meeting round through 'vote' landed a juror in the
             star-rating single-image view, which the round's method
             never actually uses. -->
        <CdxButton
          v-if="round.acceptsVotes"
          action="progressive"
          weight="primary"
          @click="router.push({
            name: round.votingMethod === 'meeting' ? 'meeting' : 'vote',
            params: { id: round.id },
          })"
        >
          {{
            round.votingMethod === 'meeting'
              ? 'Join meeting'
              : round.myProgress?.voted ? 'Continue judging' : 'Start judging'
          }}
        </CdxButton>
        <span v-else class="muted">Not open for voting</span>
      </div>
    </div>
  </div>
</template>
