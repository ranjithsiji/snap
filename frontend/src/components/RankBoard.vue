<script setup>
import { computed, ref, watch } from 'vue'
import { CdxButton, CdxIcon, CdxMessage, CdxProgressBar, CdxTextInput } from '@wikimedia/codex'
import { cdxIconCollapse, cdxIconExpand } from '@wikimedia/codex-icons'
import { api } from '@/api'

/**
 * Ranking rounds, as a gallery.
 *
 * The juror types a rank number under each image rather than dragging:
 * with hundreds of photos, typing "1" on the best one is far quicker than
 * dragging it to the top. "Rearrange" then re-sorts the gallery by those
 * numbers so the current ordering can be seen and refined.
 */
const props = defineProps({
  roundId: { type: String, required: true },
  images: { type: Array, required: true },
})

const emit = defineEmits(['submitted'])

/** Local copy carrying each image's typed rank. */
const entries = ref([])
const busy = ref(false)
const error = ref(null)
const done = ref(false)
const lightbox = ref(null)

// Remembered across images rather than reset per-open — same reasoning as
// the gallery's lightbox: dismissing the panel should stay dismissed while
// flicking between photos, not reappear for every one.
const lightboxDetailsOpen = ref(true)

// True from openLightbox until the new image has actually loaded, so the
// previous photo does not sit on screen looking unchanged while the next
// one is still fetching.
const lightboxLoading = ref(false)

function openLightbox(image) {
  lightboxLoading.value = true
  lightbox.value = image
}

// The lightbox is opened with the image alone; its rank lives on the
// entry wrapping it, found by index since applyRank needs a position to
// insert at, not just the entry — and that position shifts as other
// entries are renumbered while the lightbox stays open.
const lightboxIndex = computed(() =>
  entries.value.findIndex((entry) => entry.image.id === lightbox.value?.id),
)
const lightboxEntry = computed(() =>
  lightboxIndex.value === -1 ? null : entries.value[lightboxIndex.value],
)

watch(
  () => props.images,
  (next) => {
    entries.value = next.map((image, index) => ({
      image,
      // Pre-seed with the current order so a juror who only wants to move
      // a few images does not have to number every one of them.
      rank: String(index + 1),
    }))
  },
  { immediate: true },
)

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

/**
 * Applies a typed rank by inserting the image at that position and
 * shifting everything from there down.
 *
 * Without this, typing "1" on a second image would silently leave two
 * images sharing rank 1. Reassigning the whole sequence keeps the ranks a
 * genuine permutation at every keystroke, so a duplicate can never be
 * submitted in the first place.
 */
function applyRank(changedIndex, rawValue) {
  const entry = entries.value[changedIndex]
  const requested = Number(rawValue)

  // Let the field be cleared or half-typed without reshuffling under the
  // juror's cursor; validation catches anything still blank at submit.
  if (!Number.isInteger(requested) || requested < 1) {
    entry.rank = rawValue
    return
  }

  const target = Math.min(requested, entries.value.length)

  // Order everything else by its current rank, then drop this image in at
  // the requested position and renumber the sequence.
  const others = entries.value
    .filter((_, index) => index !== changedIndex)
    .sort((a, b) => (Number(a.rank) || Number.MAX_SAFE_INTEGER) - (Number(b.rank) || Number.MAX_SAFE_INTEGER))

  others.splice(target - 1, 0, entry)

  const positions = new Map(others.map((item, index) => [item.image.id, index + 1]))

  // Mutated in place, and only where the rank actually changed, rather
  // than replacing all 200 entries with new objects on every keystroke.
  // A fresh array forced every tile to re-render on every digit typed
  // anywhere in the grid — with two hundred controlled number inputs
  // that showed up as visibly glitching, half-rendered fields while
  // typing. Assigning only the ranks that moved keeps Vue's reactivity
  // from touching the tiles that did not.
  for (const item of entries.value) {
    const next = String(positions.get(item.image.id))

    if (item.rank !== next) {
      item.rank = next
    }
  }
}

/** Field status: duplicates are an error, a blank rank a pending warning. */
function rankStatus(entry) {
  const value = Number(entry.rank)

  if (!Number.isInteger(value) || value < 1) return 'warning'
  if (duplicates.value.has(value)) return 'error'

  return 'default'
}

/** Re-sorts the gallery by the typed ranks and renumbers them 1..N. */
function rearrange() {
  const sorted = [...entries.value].sort((a, b) => {
    const left = Number(a.rank) || Number.MAX_SAFE_INTEGER
    const right = Number(b.rank) || Number.MAX_SAFE_INTEGER

    return left - right
  })

  entries.value = sorted.map((entry, index) => ({ ...entry, rank: String(index + 1) }))
}

async function submit() {
  error.value = null
  busy.value = true

  try {
    // The API takes the image ids best-first, so send them in rank order.
    const order = [...entries.value]
      .sort((a, b) => Number(a.rank) - Number(b.rank))
      .map((entry) => entry.image.id)

    await api.post(`/my/rounds/${props.roundId}/rank`, { order })

    done.value = true
    emit('submitted')
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <CdxMessage v-if="error" type="error">{{ error }}</CdxMessage>
  <CdxMessage v-if="done" type="success">Your ranking has been saved.</CdxMessage>

  <template v-else>
    <CdxMessage type="notice">
      Enter a rank for each image, 1 being the best. Use <strong>Rearrange</strong> to sort the
      gallery by the ranks you have entered.
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
      <CdxButton @click="rearrange">Rearrange</CdxButton>

      <span class="muted">{{ entries.length }} image(s)</span>

      <span class="spacer"></span>

      <CdxButton
        action="progressive"
        weight="primary"
        :disabled="busy || !isValid"
        @click="submit"
      >
        {{ busy ? 'Submitting…' : 'Submit ranking' }}
      </CdxButton>
    </div>

    <div class="gallery-grid">
      <figure
        v-for="(entry, index) in entries"
        :key="entry.image.id"
        class="gallery-tile rank-tile"
        :class="{ 'rank-tile-invalid': rankStatus(entry) === 'error' }"
      >
        <img
          :src="entry.image.thumbUrl"
          :alt="entry.image.name ?? 'Contest image'"
          loading="lazy"
        />

        <button
          type="button"
          class="tile-action tile-zoom"
          aria-label="View larger"
          @click="openLightbox(entry.image)"
        >
          ⤢
        </button>

        <figcaption class="rank-caption">
          <CdxTextInput
            :model-value="entry.rank"
            input-type="number"
            min="1"
            :max="entries.length"
            :aria-label="`Rank for ${entry.image.name ?? 'this image'}`"
            :aria-invalid="duplicates.has(Number(entry.rank)) ? 'true' : undefined"
            :status="rankStatus(entry)"
            @update:model-value="applyRank(index, $event)"
          />
        </figcaption>
      </figure>
    </div>
  </template>

  <!-- Same lightbox as the gallery grid, not the bare image-and-caption
       overlay this used to open: a juror comparing full-resolution detail
       wants the resolution, uploader and original-file link here too,
       rather than a different, thinner view depending on which round type
       they are judging. -->
  <div v-if="lightbox" class="lightbox" @click="lightbox = null">
    <CdxProgressBar v-if="lightboxLoading" class="lightbox-progress" aria-label="Loading image" />

    <img
      v-show="!lightboxLoading"
      class="lightbox-image"
      :src="lightbox.lightboxUrl ?? lightbox.fileUrl ?? lightbox.thumbUrl"
      :alt="lightbox.name ?? 'Contest image'"
      @load="lightboxLoading = false"
      @error="lightboxLoading = false"
    />

    <aside v-if="lightboxDetailsOpen" class="lightbox-panel" @click.stop>
      <div class="row lightbox-panel-head">
        <h2 class="vote-filename">{{ lightbox.name ?? 'Contest image' }}</h2>
        <CdxButton
          weight="quiet"
          aria-label="Minimise details"
          title="Minimise details"
          @click="lightboxDetailsOpen = false"
        >
          <CdxIcon :icon="cdxIconCollapse" />
        </CdxButton>
      </div>

      <template v-if="lightbox.width || lightbox.megapixels || lightbox.uploader">
        <h3 class="vote-section">Image</h3>
        <dl class="stat-list">
          <div v-if="lightbox.width">
            <dt>Resolution</dt>
            <dd>{{ lightbox.width }} × {{ lightbox.height }}</dd>
          </div>
          <div v-if="lightbox.megapixels">
            <dt>Megapixels</dt>
            <dd>{{ lightbox.megapixels }} Mpix</dd>
          </div>
          <div v-if="lightbox.uploader">
            <dt>Uploader</dt>
            <dd>{{ lightbox.uploader }}</dd>
          </div>
        </dl>
      </template>

      <template v-if="lightboxEntry">
        <h3 class="vote-section">Rank</h3>
        <CdxTextInput
          class="lightbox-rank-input"
          :model-value="lightboxEntry.rank"
          input-type="number"
          min="1"
          :max="entries.length"
          :aria-label="`Rank for ${lightbox.name ?? 'this image'}`"
          :status="rankStatus(lightboxEntry)"
          @click.stop
          @update:model-value="applyRank(lightboxIndex, $event)"
        />
      </template>

      <a
        v-if="lightbox.descriptionUrl"
        class="lightbox-panel-link"
        :href="lightbox.descriptionUrl"
        target="_blank"
        rel="noopener"
      >
        Open the Commons page
      </a>

      <a
        v-if="lightbox.fileUrl"
        class="lightbox-panel-link"
        :href="lightbox.fileUrl"
        target="_blank"
        rel="noopener"
      >
        Open the original file
      </a>
    </aside>

    <!-- Only shown once minimised: with the panel open, the collapse
         button inside it already does this job. -->
    <CdxButton
      v-else
      weight="quiet"
      class="lightbox-restore"
      aria-label="Show details"
      title="Show details"
      @click.stop="lightboxDetailsOpen = true"
    >
      <CdxIcon :icon="cdxIconExpand" />
    </CdxButton>
  </div>
</template>
