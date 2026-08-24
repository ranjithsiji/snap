<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { CdxButton, CdxIcon, CdxMessage, CdxProgressBar, CdxTextInput } from '@wikimedia/codex'
import { cdxIconClose } from '@wikimedia/codex-icons'
import { api } from '@/api'
import { useSession } from '@/stores/session'

/**
 * A juror's own proposed ranking for the meeting, as a gallery — the same
 * pattern an ordinary ranking round uses (RankBoard.vue), so the panel
 * sees every image at once and types a position under each rather than
 * working through a narrow dialog one row at a time.
 *
 * Submits through the same /meetings/{id}/proposals endpoint MeetingView's
 * dialog always used; only how the ranking is entered changed.
 */
const props = defineProps({ id: { type: String, required: true } })
const router = useRouter()
const session = useSession()

const round = ref(null)
const entries = ref([])
const loading = ref(true)
const busy = ref(false)
const error = ref(null)
const done = ref(false)
const lightbox = ref(null)

async function load() {
  loading.value = true
  error.value = null

  try {
    const [detail, matrix] = await Promise.all([
      api.get(`/meetings/${props.id}`),
      api.get(`/meetings/${props.id}/proposals`),
    ])

    round.value = detail.round

    // Seeded from this juror's own existing proposal where they have one,
    // and the agreed order otherwise — adjusting a ranking already made
    // is the common case, not starting from nothing.
    entries.value = matrix.images.map((image) => {
      const mine = image.proposals.find((p) => p.juror === session.user?.username)

      return { image, rank: String(mine ? mine.position : image.position) }
    })
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

onMounted(load)

const ranks = computed(() =>
  entries.value.map((entry) => Number(entry.rank)).filter((n) => Number.isInteger(n) && n > 0),
)

const duplicates = computed(() => {
  const seen = new Set()
  const dupes = new Set()

  for (const rank of ranks.value) {
    if (seen.has(rank)) dupes.add(rank)
    seen.add(rank)
  }

  return dupes
})

const missing = computed(() => entries.value.length - ranks.value.length)
const isValid = computed(() => missing.value === 0 && duplicates.value.size === 0)

// Paged at 25, same as the meeting's film strip — a meeting can carry
// over far more images than a panel can usefully rank in one screen, and
// rendering all of them at once is what hung the page before this was
// added. applyRank still takes the image's real index into the full
// entries array, so reordering keeps working correctly across pages —
// only which tiles are drawn is paged, not the ranking logic itself.
const PAGE_SIZE = 25
const page = ref(0)
const pageCount = computed(() => Math.max(1, Math.ceil(entries.value.length / PAGE_SIZE)))
const pagedEntries = computed(() => {
  const start = page.value * PAGE_SIZE

  return entries.value
    .slice(start, start + PAGE_SIZE)
    .map((entry, offset) => ({ entry, index: start + offset }))
})

/**
 * Applies a typed rank by inserting the image at that position and
 * shifting everything from there down — the images reorder in the grid
 * as the number changes, not just the label under them.
 */
function applyRank(changedIndex, rawValue) {
  const entry = entries.value[changedIndex]
  const requested = Number(rawValue)

  if (!Number.isInteger(requested) || requested < 1) {
    entry.rank = rawValue
    return
  }

  const target = Math.min(requested, entries.value.length)

  const others = entries.value
    .filter((_, index) => index !== changedIndex)
    .sort((a, b) => (Number(a.rank) || Number.MAX_SAFE_INTEGER) - (Number(b.rank) || Number.MAX_SAFE_INTEGER))

  others.splice(target - 1, 0, entry)

  // Reordered here, not just renumbered: entries drives the grid's own
  // v-for, so moving an item's position in this array is what actually
  // moves its tile — a juror sees the image itself slide to its new
  // rank rather than only the number under it changing.
  entries.value = others

  // A move can carry the tile onto a different page than the one it was
  // typed from; the pager follows it there, so the tile a juror just
  // repositioned does not simply vanish from what they are looking at.
  const newIndex = entries.value.indexOf(entry)
  page.value = Math.floor(newIndex / PAGE_SIZE)
}

function rankStatus(entry) {
  const value = Number(entry.rank)

  if (!Number.isInteger(value) || value < 1) return 'warning'
  if (duplicates.value.has(value)) return 'error'

  return 'default'
}

async function submit() {
  error.value = null
  busy.value = true

  try {
    const order = [...entries.value]
      .sort((a, b) => Number(a.rank) - Number(b.rank))
      .map((entry) => entry.image.imageId)

    await api.post(`/meetings/${props.id}/proposals`, { order })

    done.value = true
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <CdxProgressBar v-if="loading" aria-label="Loading meeting" />

  <template v-else-if="round">
    <div class="page-head">
      <div>
        <h1 class="page-title">My ranking</h1>
        <p class="page-subtitle">{{ round.name }} — recorded separately, so disagreements stay visible</p>
      </div>
      <CdxButton @click="router.push({ name: 'meeting', params: { id } })">Back to meeting</CdxButton>
    </div>

    <CdxMessage v-if="error" type="error">{{ error }}</CdxMessage>

    <CdxMessage v-if="done" type="success">
      Your ranking has been recorded. The agreed order has been recalculated.
      <CdxButton style="margin-top: 0.5rem" @click="router.push({ name: 'meeting', params: { id } })">
        Back to meeting
      </CdxButton>
    </CdxMessage>

    <template v-else>
      <CdxMessage type="notice">
        Enter a rank for each image, 1 being the best. Images reorder as you type.
      </CdxMessage>

      <CdxMessage v-if="duplicates.size > 0" type="error" inline>
        Rank {{ [...duplicates].join(', ') }} {{ duplicates.size === 1 ? 'is' : 'are' }} used more than
        once. Each image needs its own rank before you can submit.
      </CdxMessage>

      <CdxMessage v-else-if="missing > 0" type="warning" inline>
        {{ missing }} image{{ missing === 1 ? '' : 's' }} still {{ missing === 1 ? 'has' : 'have' }} no
        rank.
      </CdxMessage>

      <div class="gallery-toolbar">
        <span class="muted">{{ entries.length }} image(s)</span>
        <span class="spacer"></span>
        <!-- A reorder can move an image onto a different page than the
             one it was typed from — the pager follows the count, not a
             fixed position, so it always reflects where things actually
             ended up. -->
        <CdxButton weight="quiet" :disabled="page === 0" @click="page--">
          «
        </CdxButton>
        <span class="muted">Page {{ page + 1 }} of {{ pageCount }}</span>
        <CdxButton weight="quiet" :disabled="page >= pageCount - 1" @click="page++">
          »
        </CdxButton>
        <CdxButton
          action="progressive"
          weight="primary"
          :disabled="busy || !isValid"
          @click="submit"
        >
          {{ busy ? 'Submitting…' : 'Submit my ranking' }}
        </CdxButton>
      </div>

      <div class="gallery-grid">
        <figure
          v-for="{ entry, index } in pagedEntries"
          :key="entry.image.imageId"
          class="gallery-tile rank-tile"
          :class="{ 'rank-tile-invalid': rankStatus(entry) === 'error' }"
        >
          <img :src="entry.image.thumbUrl" :alt="entry.image.title" loading="lazy" />

          <button
            type="button"
            class="tile-action tile-zoom"
            aria-label="View larger"
            @click="lightbox = entry.image"
          >
            ⤢
          </button>

          <figcaption class="rank-caption">
            <CdxTextInput
              :model-value="entry.rank"
              input-type="number"
              min="1"
              :max="entries.length"
              :aria-label="`Rank for ${entry.image.title}`"
              :status="rankStatus(entry)"
              @update:model-value="applyRank(index, $event)"
            />
          </figcaption>
        </figure>
      </div>
    </template>
  </template>

  <!-- Detailed view of one image without leaving the grid. -->
  <div v-if="lightbox" class="lightbox" @click="lightbox = null">
    <img
      class="lightbox-image"
      :src="lightbox.lightboxUrl ?? lightbox.fileUrl ?? lightbox.thumbUrl"
      :alt="lightbox.title"
    />
    <button
      type="button"
      class="lightbox-restore"
      aria-label="Close"
      @click.stop="lightbox = null"
    >
      <CdxIcon :icon="cdxIconClose" />
    </button>
  </div>
</template>
