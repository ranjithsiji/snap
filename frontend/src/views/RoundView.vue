<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  CdxButton,
  CdxDialog,
  CdxField,
  CdxInfoChip,
  CdxLookup,
  CdxMessage,
  CdxProgressBar,
  CdxTextInput,
} from '@wikimedia/codex'
import { api } from '@/api'
import { formatDeadline, formatNumber, formatPixels } from '@/format'

const props = defineProps({ id: { type: String, required: true } })
const router = useRouter()

const round = ref(null)
const stats = ref(null)
const jurors = ref([])
const loading = ref(true)
const error = ref(null)
const notice = ref(null)
const busy = ref(false)

const deriveOpen = ref(false)
const derive = ref({ name: '', minAcceptCount: '', minAverageScore: '', topN: '' })
const derivePreview = ref(null)

const replaceOpen = ref(false)
const replaceTarget = ref(null)
const replaceUsername = ref('')
const replaceSuggestions = ref([])

let replaceDebounce = null

function openReplace(juror) {
  replaceTarget.value = juror
  replaceUsername.value = ''
  replaceSuggestions.value = []
  replaceOpen.value = true
}

watch(replaceUsername, (value) => {
  clearTimeout(replaceDebounce)

  if (value.trim().length < 2) {
    replaceSuggestions.value = []
    return
  }

  replaceDebounce = setTimeout(async () => {
    try {
      const data = await api.get(`/commons/users?q=${encodeURIComponent(value.trim())}`)
      replaceSuggestions.value = data.users.map((name) => ({ label: name, value: name }))
    } catch {
      replaceSuggestions.value = []
    }
  }, 250)
})

async function submitReplacement() {
  if (!replaceUsername.value.trim()) return

  error.value = null
  busy.value = true

  try {
    const data = await api.post(
      `/rounds/${props.id}/jurors/${replaceTarget.value.id}/replace`,
      { username: replaceUsername.value.trim() },
    )

    jurors.value = data.jurors
    notice.value = data.message
    replaceOpen.value = false
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

const settings = computed(() => round.value?.fileSettings ?? {})

async function load() {
  loading.value = true

  try {
    const data = await api.get(`/rounds/${props.id}`)
    round.value = data.round
    stats.value = data.statistics
    jurors.value = data.jurors
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

onMounted(load)

async function transition(state) {
  error.value = null
  notice.value = null
  busy.value = true

  try {
    const data = await api.post(`/rounds/${props.id}/state/${state}`)
    round.value = data.round
    stats.value = data.statistics
    notice.value = `Round is now ${data.round.state}.`
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

function download(format) {
  window.location.href = `/api/rounds/${props.id}/export?format=${format}`
}

/** Criteria payload with blank fields stripped, as the API treats absent as "no filter". */
function deriveCriteria() {
  const criteria = {}

  for (const key of ['minAcceptCount', 'minAverageScore', 'topN']) {
    if (derive.value[key] !== '') criteria[key] = Number(derive.value[key])
  }

  return criteria
}

async function previewDerivation() {
  try {
    const data = await api.post(`/rounds/${props.id}/derive/preview`, deriveCriteria())
    derivePreview.value = data
  } catch (e) {
    error.value = e.message
  }
}

async function submitDerivation() {
  busy.value = true

  try {
    const data = await api.post(`/rounds/${props.id}/derive`, {
      name: derive.value.name,
      ...deriveCriteria(),
    })

    deriveOpen.value = false
    router.push({ name: 'round', params: { id: data.round.id } })
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <CdxProgressBar v-if="loading" aria-label="Loading round" />

  <template v-else-if="round">
    <div class="page-head">
      <div>
        <h1 class="page-title">{{ round.name }}</h1>
        <p class="page-subtitle">
          {{ round.votingMethodLabel }} · {{ round.state }}
          <template v-if="round.derivedFromRoundName">
            · from {{ round.derivedFromRoundName }}
          </template>
        </p>
      </div>
      <CdxButton @click="router.push({ name: 'round-edit', params: { id: round.id } })">
        Edit round
      </CdxButton>
    </div>

    <CdxMessage v-if="error" type="error">{{ error }}</CdxMessage>
    <CdxMessage v-if="notice" type="success">{{ notice }}</CdxMessage>

    <div class="card">
      <div class="grid-2">
        <!-- Left: configuration and jury progress -->
        <div>
          <h2 class="section-title">Voting deadline</h2>
          <p class="muted" style="margin-top: 0">{{ formatDeadline(round.votingDeadline) }}</p>

          <template v-if="round.details">
            <h2 class="section-title">Directions</h2>
            <p class="muted" style="margin-top: 0">{{ round.details }}</p>
          </template>

          <h2 class="section-title">Quorum</h2>
          <p class="muted" style="margin-top: 0">
            {{ round.effectiveQuorum }} juror(s) per photo
          </p>

          <template v-if="round.derivationCriteria">
            <h2 class="section-title">Selection criteria</h2>
            <p class="muted" style="margin-top: 0">{{ round.derivationCriteria }}</p>
          </template>

          <h2 class="section-title">Jurors</h2>
          <div v-if="jurors.length === 0" class="muted">No jurors assigned yet.</div>

          <div v-else class="juror-grid">
            <div v-for="juror in jurors" :key="juror.id">
              <div class="row" style="gap: 0.375rem">
                <strong>{{ juror.username }}</strong>
                <CdxInfoChip v-if="!juror.isActive">withdrawn</CdxInfoChip>
                <CdxInfoChip v-if="juror.replacedUsername" status="warning">
                  replaced {{ juror.replacedUsername }}
                </CdxInfoChip>
              </div>

              <div class="juror-progress">
                {{ juror.expected > 0 ? `${juror.percentComplete}%` : 'N/A' }}
              </div>

              <div class="meter">
                <span :style="{ width: `${Math.min(100, juror.percentComplete)}%` }"></span>
              </div>

              <div class="muted" style="font-size: 0.8125rem">
                {{ formatNumber(juror.votesCast) }} out of {{ formatNumber(juror.expected) }}<br />
                {{ formatNumber(juror.remaining) }} image(s) remaining
              </div>

              <CdxButton
                v-if="round.state !== 'finalized'"
                weight="quiet"
                style="margin-top: 0.25rem"
                @click="openReplace(juror)"
              >
                Replace
              </CdxButton>
            </div>
          </div>
        </div>

        <!-- Right: file information and settings -->
        <div>
          <h2 class="section-title">Round file information</h2>

          <dl class="stat-list">
            <div><dt>Files</dt><dd>{{ formatNumber(stats.files) }}</dd></div>
            <div><dt>Qualified files</dt><dd>{{ formatNumber(stats.qualifiedFiles) }}</dd></div>
            <div><dt>Disqualified files</dt><dd>{{ formatNumber(stats.disqualifiedFiles) }}</dd></div>
            <div><dt>Uploaders</dt><dd>{{ formatNumber(stats.uploaders) }}</dd></div>
            <div><dt>Tasks</dt><dd>{{ formatNumber(stats.tasks) }}</dd></div>
            <div><dt>Open tasks</dt><dd>{{ formatNumber(stats.openTasks) }}</dd></div>
            <div><dt>Percentage complete</dt><dd>{{ stats.percentComplete }}%</dd></div>
            <div>
              <dt>Images at quorum</dt>
              <dd>{{ formatNumber(stats.imagesAtQuorum) }}</dd>
            </div>
          </dl>

          <details class="disclosure" style="margin-top: 1rem">
            <summary>Round file settings</summary>
            <dl class="stat-list">
              <div>
                <dt>Disqualify jurors</dt>
                <dd>{{ settings.disqualifyJurors ? 'Yes' : 'No' }}</dd>
              </div>
              <div>
                <dt>Min. resolution</dt>
                <dd>
                  {{
                    settings.disqualifyByResolution
                      ? formatPixels(settings.minResolutionPixels)
                      : 'Not enforced'
                  }}
                </dd>
              </div>
              <div>
                <dt>Upload date window</dt>
                <dd>
                  {{
                    settings.disqualifyByUploadDate
                      ? `${settings.uploadDateFrom?.slice(0, 10) ?? '—'} → ${settings.uploadDateTo?.slice(0, 10) ?? '—'}`
                      : 'Not enforced'
                  }}
                </dd>
              </div>
              <div>
                <dt>Disqualify coordinators</dt>
                <dd>{{ settings.disqualifyCoordinators ? 'Yes' : 'No' }}</dd>
              </div>
              <div>
                <dt>Disqualify maintainers</dt>
                <dd>{{ settings.disqualifyMaintainers ? 'Yes' : 'No' }}</dd>
              </div>
              <div>
                <dt>Disqualify organizers</dt>
                <dd>{{ settings.disqualifyOrganizers ? 'Yes' : 'No' }}</dd>
              </div>
              <div>
                <dt>Shown to jurors</dt>
                <dd>
                  {{
                    [
                      settings.showFilename && 'filename',
                      settings.showLink && 'link',
                      settings.showResolution && 'resolution',
                    ]
                      .filter(Boolean)
                      .join(', ') || 'image only'
                  }}
                </dd>
              </div>
            </dl>
          </details>
        </div>
      </div>

      <div class="row row-end wrap" style="margin-top: 1.5rem">
        <CdxButton
          v-if="round.state === 'draft' || round.state === 'paused'"
          action="progressive"
          :disabled="busy"
          @click="transition('active')"
        >
          Activate
        </CdxButton>

        <CdxButton v-if="round.state === 'active'" :disabled="busy" @click="transition('paused')">
          Pause
        </CdxButton>

        <CdxButton
          v-if="round.state === 'active' || round.state === 'paused'"
          action="progressive"
          :disabled="busy"
          @click="transition('finalized')"
        >
          Finalize round
        </CdxButton>

        <CdxButton @click="router.push({ name: 'round-results', params: { id: round.id } })">
          View results
        </CdxButton>

        <CdxButton @click="download('csv')">Download results</CdxButton>
        <CdxButton @click="download('txt')">Download entries</CdxButton>

        <CdxButton
          v-if="round.state === 'finalized'"
          action="progressive"
          weight="primary"
          @click="deriveOpen = true"
        >
          Create next round
        </CdxButton>
      </div>
    </div>

    <CdxDialog
      v-model:open="deriveOpen"
      title="Create next round"
      subtitle="Carry forward the images that met your criteria"
      :primary-action="{ label: 'Create round', actionType: 'progressive', disabled: busy }"
      :default-action="{ label: 'Cancel' }"
      @primary="submitDerivation"
      @default="deriveOpen = false"
    >
      <CdxField>
        <template #label>New round name</template>
        <CdxTextInput v-model="derive.name" placeholder="Round 2" />
      </CdxField>

      <CdxField v-if="round.votingMethod === 'yesno'">
        <template #label>Minimum accept votes</template>
        <template #description>Keep images at least this many jurors accepted.</template>
        <CdxTextInput v-model="derive.minAcceptCount" input-type="number" min="1" />
      </CdxField>

      <CdxField v-else>
        <template #label>Minimum average score</template>
        <CdxTextInput v-model="derive.minAverageScore" input-type="number" step="0.1" />
      </CdxField>

      <CdxField>
        <template #label>Keep only the top N</template>
        <template #description>Leave blank to keep everything that met the criteria.</template>
        <CdxTextInput v-model="derive.topN" input-type="number" min="1" />
      </CdxField>

      <CdxButton @click="previewDerivation">Preview selection</CdxButton>

      <CdxMessage v-if="derivePreview" type="notice" style="margin-top: 0.75rem">
        {{ formatNumber(derivePreview.count) }} image(s) would carry over
        ({{ derivePreview.criteria }}).
      </CdxMessage>
    </CdxDialog>

    <CdxDialog
      v-model:open="replaceOpen"
      title="Replace juror"
      :subtitle="replaceTarget ? `Hand ${replaceTarget.username}'s seat to someone else` : ''"
      :primary-action="{
        label: 'Replace juror',
        actionType: 'progressive',
        disabled: busy || !replaceUsername.trim(),
      }"
      :default-action="{ label: 'Cancel' }"
      @primary="submitReplacement"
      @default="replaceOpen = false"
    >
      <CdxMessage v-if="replaceTarget" type="warning" inline>
        The {{ formatNumber(replaceTarget.votesCast) }} vote(s) already cast on this seat will be
        transferred to the new juror and will continue to count towards the quorum. The new juror
        can review and change any of them.
      </CdxMessage>

      <CdxField style="margin-top: 0.75rem">
        <template #label>New juror's Wikimedia username</template>
        <CdxLookup
          v-model:selected="replaceUsername"
          v-model:input-value="replaceUsername"
          :menu-items="replaceSuggestions"
          placeholder="Start typing a username…"
        />
      </CdxField>
    </CdxDialog>
  </template>
</template>
