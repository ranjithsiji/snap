<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  CdxButton,
  CdxField,
  CdxIcon,
  CdxInfoChip,
  CdxMessage,
  CdxProgressBar,
} from '@wikimedia/codex'
import {
  cdxIconCheckAll,
  cdxIconEdit,
  cdxIconImageGallery,
  cdxIconNext,
  cdxIconSortVertical,
  cdxIconStar,
  cdxIconUserGroup,
} from '@wikimedia/codex-icons'
import CommonsChipInput from '@/components/CommonsChipInput.vue'
import { api } from '@/api'
import { formatDeadline, formatNumber } from '@/format'

const props = defineProps({ id: { type: String, required: true } })
const router = useRouter()

const campaign = ref(null)
const participants = ref({})
// Scoped to this campaign by the server, rather than read off the global
// role rank: leading one project says nothing about another's campaign,
// and an organizer appointed here may manage its rounds without being
// able to edit the campaign itself.
const canEditCampaign = ref(false)
const loading = ref(true)
const busy = ref(false)
const error = ref(null)
const notice = ref(null)

// Participant roles drive the round's "disqualify …" rules, so they are
// listed per campaign rather than per round. Jurors are not listed here:
// they are set per round, and the rules read them from the seats directly.
const roles = [
  { key: 'organizer', label: 'Organizers' },
  { key: 'coordinator', label: 'Coordinators' },
  { key: 'maintainer', label: 'Maintainers' },
]

const chips = ref({ organizer: [], coordinator: [], maintainer: [] })

// One icon per voting method, so the round type is legible at a glance
// instead of only in the smaller label text underneath the name.
const votingMethodIcons = {
  yesno: cdxIconCheckAll,
  rating: cdxIconStar,
  rank: cdxIconSortVertical,
  meeting: cdxIconUserGroup,
}

async function load() {
  loading.value = true

  try {
    const data = await api.get(`/campaigns/${props.id}`)
    campaign.value = data.campaign
    participants.value = data.participants
    canEditCampaign.value = data.canEditCampaign ?? false

    for (const { key } of roles) {
      chips.value[key] = [...(data.participants[key] ?? [])]
    }
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

onMounted(load)

async function saveParticipants(role) {
  error.value = null
  notice.value = null
  busy.value = true

  try {
    await api.put(`/campaigns/${props.id}/participants`, {
      role,
      usernames: chips.value[role],
    })
    notice.value = 'Participants saved.'
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function reimport() {
  error.value = null
  notice.value = null
  busy.value = true

  try {
    const data = await api.post(`/campaigns/${props.id}/reimport`)
    campaign.value = data.campaign
    notice.value = `Import finished: ${data.import.added} added, ${data.import.updated} updated.`
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <CdxProgressBar v-if="loading" aria-label="Loading campaign" />

  <template v-else-if="campaign">
    <div class="page-head">
      <div>
        <h1 class="page-title">{{ campaign.name }}</h1>
        <p class="page-subtitle">
          {{ campaign.sourceSummary || 'No source' }} ·
          {{ formatNumber(campaign.imageCount) }} image(s) in pool
        </p>
      </div>
      <div class="row">
        <CdxButton
          v-if="canEditCampaign"
          @click="router.push({ name: 'campaign-edit', params: { id: campaign.id } })"
        >
          <CdxIcon :icon="cdxIconEdit" /> Edit campaign
        </CdxButton>
        <CdxButton :disabled="busy" @click="reimport">
          {{ busy ? 'Importing…' : 'Re-import from source' }}
        </CdxButton>
        <CdxButton
          action="progressive"
          weight="primary"
          @click="router.push({ name: 'round-new', params: { campaignId: campaign.id } })"
        >
          Add Round
        </CdxButton>
      </div>
    </div>

    <CdxMessage v-if="error" type="error">{{ error }}</CdxMessage>
    <CdxMessage v-if="notice" type="success">{{ notice }}</CdxMessage>
    <!-- Not a warning: a round names its own Commons category and imports
         from it, so nothing here has to be imported first. The campaign
         pool is only the fallback for a round left without a category. -->
    <CdxMessage v-if="!campaign.importedAt" type="notice">
      No campaign-wide image pool has been imported. Rounds import from their own
      Commons category, so this is only needed for a round left without one.
    </CdxMessage>

    <div class="grid-2">
      <div>
        <h2 class="section-title">Rounds</h2>

        <!-- Guarded: an endpoint returning the campaign without its rounds
             would otherwise blank the whole page. -->
        <div v-if="(campaign.rounds ?? []).length === 0" class="card empty">
          <p>No rounds yet.</p>
        </div>

        <!-- A single rail runs down the left through every round, rather
             than separate arrows between each pair — a round that
             continues from the one before it is a stage in one pipeline,
             not a series of unrelated list items. -->
        <div v-else class="round-waterfall">
          <div
            v-for="round in campaign.rounds"
            :key="round.id"
            class="round-waterfall-item card"
            style="cursor: pointer"
            @click="router.push({ name: 'round', params: { id: round.id } })"
          >
            <span class="round-waterfall-dot"></span>

            <div class="row wrap">
              <div class="row" style="gap: 0.5rem">
                <CdxIcon
                  v-if="votingMethodIcons[round.votingMethod]"
                  :icon="votingMethodIcons[round.votingMethod]"
                  class="round-type-icon"
                />
                <div>
                  <strong>{{ round.name }}</strong>
                  <p class="muted" style="margin: 0.25rem 0 0; font-size: 0.875rem">
                    {{ round.votingMethodLabel }} · {{ formatDeadline(round.votingDeadline) }}
                  </p>
                </div>
              </div>
              <span class="spacer"></span>
              <CdxInfoChip :status="round.state === 'active' ? 'success' : 'notice'">
                {{ round.state }}
              </CdxInfoChip>
              <!-- Judging only when there is judging to do; View round
                   always, since the round's own page is where everything
                   else about it — including activating it — happens. -->
              <!-- A meeting round has its own screen — voting sends it
                   through the star-rating single-image flow, which its
                   method never uses. -->
              <CdxButton
                v-if="round.state === 'active'"
                weight="quiet"
                action="progressive"
                @click.stop="router.push({
                  name: round.votingMethod === 'meeting' ? 'meeting' : 'vote',
                  params: { id: round.id },
                })"
              >
                <CdxIcon :icon="cdxIconImageGallery" />
                {{ round.votingMethod === 'meeting' ? 'Join meeting' : 'Judge' }}
              </CdxButton>
              <CdxButton
                weight="quiet"
                @click.stop="router.push({ name: 'round', params: { id: round.id } })"
              >
                View round <CdxIcon :icon="cdxIconNext" />
              </CdxButton>
            </div>
          </div>
        </div>
      </div>

      <div>
        <h2 class="section-title">Campaign participants</h2>
        <p class="muted" style="margin-top: 0; font-size: 0.875rem">
          Uploads by these users can be disqualified automatically, per each round's file settings.
        </p>

        <div class="card">
          <CdxField v-for="role in roles" :key="role.key">
            <template #label>{{ role.label }}</template>
            <CommonsChipInput
              v-model="chips[role.key]"
              :placeholder="`Search Commons for ${role.label.toLowerCase()}…`"
            />
            <template #help-text>
              <CdxButton :disabled="busy" @click="saveParticipants(role.key)">Save</CdxButton>
            </template>
          </CdxField>
        </div>
      </div>
    </div>
  </template>
</template>
