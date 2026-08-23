<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { CdxButton, CdxMessage, CdxProgressBar, CdxTabs, CdxTab } from '@wikimedia/codex'
import {
  cdxIconClose,
  cdxIconDownload,
  cdxIconHeart,
  cdxIconImage,
  cdxIconLink,
  cdxIconNext,
} from '@wikimedia/codex-icons'
import { api } from '@/api'
import { formatNumber } from '@/format'
import GalleryGrid from '@/components/GalleryGrid.vue'
import RankBoard from '@/components/RankBoard.vue'

const props = defineProps({ id: { type: String, required: true } })
const router = useRouter()

const round = ref(null)
const stats = ref(null)
const counts = ref({ unrated: 0, selected: 0, rejected: 0, all: 0 })
const remaining = ref(0)
const handover = ref(null)

const images = ref([])
const loading = ref(true)
const busy = ref(false)
const error = ref(null)
const exhausted = ref(false)

// "single" walks one image at a time; "gallery" is the fast grid pass.
const mode = ref('single')
const showFullSize = ref(false)

const current = computed(() => images.value[0] ?? null)
const isRanking = computed(() => round.value?.votingMethod === 'rank')
const isYesNo = computed(() => round.value?.votingMethod === 'yesno')
const stars = computed(() => Array.from({ length: round.value?.maxRating ?? 5 }, (_, i) => i + 1))

async function loadRound() {
  const data = await api.get(`/my/rounds/${props.id}`)
  round.value = data.round
  stats.value = data.statistics ?? null
  counts.value = data.counts
  remaining.value = data.remaining
  handover.value = data.handover ?? null
}

async function loadQueue() {
  const limit = isRanking.value ? 200 : 1
  const data = await api.get(`/my/rounds/${props.id}/queue?limit=${limit}`)

  images.value = data.images
  exhausted.value = data.exhausted
}

onMounted(async () => {
  try {
    await loadRound()

    // Ranking is inherently comparative, so it is always shown as a
    // gallery; yes/no and rating start on the single-image flow.
    mode.value = isRanking.value ? 'gallery' : 'single'

    await loadQueue()
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
})

async function vote(score) {
  if (!current.value || busy.value) return

  error.value = null
  busy.value = true
  showFullSize.value = false

  try {
    const data = await api.post(`/my/images/${current.value.id}/vote`, { score })
    if (data.statistics) stats.value = data.statistics

    remaining.value = Math.max(0, remaining.value - 1)
    counts.value.unrated = Math.max(0, counts.value.unrated - 1)
    if (score >= 1) counts.value.selected++
    else counts.value.rejected++

    images.value.shift()
    if (images.value.length === 0) await loadQueue()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function skip() {
  if (!current.value || busy.value) return

  busy.value = true
  showFullSize.value = false

  try {
    await api.post(`/my/images/${current.value.id}/skip`, { skipped: true })

    // Move it out of the local queue; the server sorts skipped images last
    // so it will come back once the rest are done.
    images.value.shift()
    if (images.value.length === 0) await loadQueue()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function toggleFavorite() {
  if (!current.value) return

  const next = !current.value.isFavorite

  try {
    await api.post(`/my/images/${current.value.id}/favorite`, { favorite: next })
    current.value.isFavorite = next
  } catch (e) {
    error.value = e.message
  }
}

function onKey(event) {
  if (busy.value || isRanking.value || mode.value !== 'single' || !current.value) return
  if (event.target.tagName === 'INPUT' || event.target.tagName === 'TEXTAREA') return

  if (event.key === 'ArrowRight' || event.key === 's') {
    skip()
    return
  }

  if (isYesNo.value) {
    if (event.key === 'ArrowUp' || event.key === 'y') vote(1)
    if (event.key === 'ArrowDown' || event.key === 'n') vote(0)
    return
  }

  const digit = Number(event.key)
  if (Number.isInteger(digit) && digit >= 1 && digit <= round.value.maxRating) vote(digit)
}

onMounted(() => window.addEventListener('keydown', onKey))
onUnmounted(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <CdxProgressBar v-if="loading" aria-label="Loading round" />

  <template v-else-if="round">
    <div class="page-head">
      <div>
        <h1 class="page-title">{{ round.name }}</h1>
        <p class="page-subtitle">{{ round.campaignName }} · {{ round.votingMethodLabel }}</p>
      </div>

      <div class="row">
        <CdxButton
          v-if="!isRanking"
          :aria-pressed="mode === 'gallery'"
          @click="mode = mode === 'single' ? 'gallery' : 'single'"
        >
          {{ mode === 'single' ? 'Gallery view' : 'Single image view' }}
        </CdxButton>
        <CdxButton @click="router.push({ name: 'home' })">Back to my rounds</CdxButton>
      </div>
    </div>

    <CdxMessage v-if="error" type="error">{{ error }}</CdxMessage>

    <!-- Shown when this juror took over another juror's seat, so they know
         the inherited votes are now theirs to stand behind or change. -->
    <CdxMessage v-if="handover" type="warning" :dismiss-button-label="'Dismiss'">
      <strong>You have taken over from {{ handover.replacedUsername }}.</strong>
      {{ handover.notice }}
      <template v-if="handover.inheritedVotes > 0">
        <br />
        <CdxButton weight="quiet" style="margin-top: 0.5rem" @click="mode = 'gallery'">
          Review inherited votes
        </CdxButton>
      </template>
    </CdxMessage>

    <CdxMessage v-if="round.details" type="notice">{{ round.details }}</CdxMessage>
    <CdxMessage v-if="!round.acceptsVotes" type="warning">
      This round is not currently accepting votes.
    </CdxMessage>

    <!-- Ranking: order everything, submit once -->
    <RankBoard
      v-else-if="isRanking"
      :round-id="props.id"
      :images="images"
      @submitted="exhausted = true"
    />

    <!-- Gallery: fast grid pass with per-tile accept/reject -->
    <GalleryGrid
      v-else-if="mode === 'gallery'"
      :round="round"
      :counts="counts"
      @voted="loadRound"
    />

    <!-- All caught up -->
    <div v-else-if="exhausted || !current" class="card empty">
      <h2>All done</h2>
      <p>You have judged every image in this round. Thank you.</p>
      <CdxButton action="progressive" weight="primary" @click="router.push({ name: 'home' })">
        Back to my rounds
      </CdxButton>
    </div>

    <!-- Single image: stage on the left, details and actions on the right -->
    <div v-else class="vote-layout">
      <div class="vote-stage">
        <img
          :src="showFullSize ? current.fileUrl : current.thumbUrl"
          :alt="current.name ?? 'Image awaiting judgement'"
        />
      </div>

      <aside class="vote-panel">
        <h2 class="vote-filename">{{ current.name ?? 'Image awaiting judgement' }}</h2>
        <p class="muted" style="margin: 0">{{ formatNumber(remaining) }} image remaining</p>

        <div class="row wrap" style="margin-top: 0.75rem">
          <CdxButton weight="quiet" :icon="cdxIconImage" @click="showFullSize = !showFullSize">
            {{ showFullSize ? 'Show fitted' : 'Show full-size' }}
          </CdxButton>
          <CdxButton
            v-if="current.descriptionUrl"
            weight="quiet"
            :icon="cdxIconLink"
            @click="window.open(current.descriptionUrl, '_blank', 'noopener')"
          >
            Commons page
          </CdxButton>
        </div>

        <h3 class="vote-section">Vote</h3>

        <div v-if="isYesNo" class="row">
          <CdxButton action="progressive" weight="primary" :disabled="busy" @click="vote(1)">
            Accept
          </CdxButton>
          <CdxButton action="destructive" :disabled="busy" @click="vote(0)">Decline</CdxButton>
        </div>

        <div v-else class="star-row">
          <button
            v-for="star in stars"
            :key="star"
            type="button"
            class="star"
            :aria-label="`Rate ${star}`"
            :disabled="busy"
            @click="vote(star)"
          >
            ★
          </button>
        </div>

        <p class="muted vote-hint">
          You can also use the keyboard to vote.<br />
          <template v-if="isYesNo"><kbd>↑</kbd> <kbd>↓</kbd> — Accept / Decline<br /></template>
          <template v-else><kbd>1</kbd>–<kbd>{{ round.maxRating }}</kbd> — Rate<br /></template>
          <kbd>→</kbd> — Skip (vote later)
        </p>

        <h3 class="vote-section">Actions</h3>

        <div class="stack" style="gap: 0.5rem">
          <CdxButton weight="quiet" :icon="cdxIconHeart" @click="toggleFavorite">
            {{ current.isFavorite ? 'Remove from favorites' : 'Add to favorites' }}
          </CdxButton>
          <CdxButton weight="quiet" :icon="cdxIconNext" :disabled="busy" @click="skip">
            Skip (vote later)
          </CdxButton>
          <CdxButton weight="quiet" @click="mode = 'gallery'">Edit previous votes</CdxButton>
        </div>

        <template v-if="current.width || current.megapixels">
          <h3 class="vote-section">Description</h3>
          <dl class="stat-list">
            <div v-if="current.megapixels">
              <dt>{{ current.megapixels }} Mpix</dt>
              <dd>{{ current.width }} × {{ current.height }}</dd>
            </div>
          </dl>
        </template>

        <template v-if="stats">
          <h3 class="vote-section">Your statistics</h3>
          <dl class="stat-list">
            <div><dt>Votes cast</dt><dd>{{ formatNumber(stats.totalVotes) }}</dd></div>
            <template v-if="isYesNo">
              <div><dt>Accepted</dt><dd>{{ formatNumber(stats.accepted) }}</dd></div>
              <div><dt>Declined</dt><dd>{{ formatNumber(stats.declined) }}</dd></div>
            </template>
          </dl>
        </template>
      </aside>
    </div>
  </template>
</template>
