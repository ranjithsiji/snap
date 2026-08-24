<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { CdxButton, CdxIcon, CdxInfoChip, CdxMessage, CdxProgressBar } from '@wikimedia/codex'
import { cdxIconNext } from '@wikimedia/codex-icons'
import { api } from '@/api'
import { useSession } from '@/stores/session'
import { formatNumber } from '@/format'

const session = useSession()
const router = useRouter()

const campaigns = ref([])
const loading = ref(true)
const error = ref(null)

onMounted(async () => {
  try {
    campaigns.value = (await api.get('/campaigns')).campaigns
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
      <h1 class="page-title">Campaigns</h1>
      <p class="page-subtitle">Contests and their judging rounds</p>
    </div>
    <CdxButton
      v-if="session.isAdministrator"
      action="progressive"
      weight="primary"
      @click="router.push({ name: 'campaign-new' })"
    >
      New campaign
    </CdxButton>
  </div>

  <CdxMessage v-if="error" type="error">{{ error }}</CdxMessage>
  <CdxProgressBar v-if="loading" aria-label="Loading campaigns" />

  <div v-else-if="campaigns.length === 0" class="card empty">
    <p>No campaigns yet.</p>
    <CdxButton
      v-if="session.isAdministrator"
      action="progressive"
      weight="primary"
      @click="router.push({ name: 'campaign-new' })"
    >
      Create the first campaign
    </CdxButton>
  </div>

  <div v-else class="stack">
    <div
      v-for="campaign in campaigns"
      :key="campaign.id"
      class="card"
      style="cursor: pointer"
      @click="router.push({ name: 'campaign', params: { id: campaign.id } })"
    >
      <div class="row wrap">
        <div>
          <h2 class="section-title">{{ campaign.name }}</h2>
          <p class="muted" style="margin: 0; font-size: 0.875rem">
            {{ campaign.sourceSummary || 'No source configured' }} ·
            {{ formatNumber(campaign.imageCount) }} image(s)
          </p>
        </div>

        <span class="spacer"></span>

        <CdxInfoChip v-if="campaign.year">{{ campaign.year }}</CdxInfoChip>
        <CdxInfoChip v-if="!campaign.importedAt" status="warning">Not imported</CdxInfoChip>
        <CdxInfoChip v-if="campaign.isArchived">Archived</CdxInfoChip>
        <CdxButton
          weight="quiet"
          @click.stop="router.push({ name: 'campaign', params: { id: campaign.id } })"
        >
          View rounds <CdxIcon :icon="cdxIconNext" />
        </CdxButton>
      </div>
    </div>
  </div>
</template>
