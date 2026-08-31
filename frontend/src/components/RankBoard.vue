<script setup>
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import { CdxButton, CdxIcon, CdxMessage, CdxProgressBar, CdxTextInput } from '@wikimedia/codex'
import { cdxIconCollapse, cdxIconExpand, cdxIconNext, cdxIconPrevious } from '@wikimedia/codex-icons'
import Sortable from 'sortablejs'
import { api } from '@/api'

/**
 * Ranking rounds, as a gallery.
 *
 * Two ways to set a rank, both kept because they suit different moves:
 * typing a number is fastest for sending one photo straight to a precise
 * position ("this is #3"), while dragging is faster for small local swaps
 * ("these two are the wrong way round"). Shown 40 at a time — with
 * hundreds of entries a single unpaginated grid was both slow to render
 * and unwieldy to drag across — so a page always holds one contiguous
 * block of ranks, e.g. page 2 is ranks 41-80, and drag-reordering is
 * scoped to the visible page for the same reason the lightbox's Prev/Next
 * stops at a page edge rather than reaching across it.
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

const PAGE_SIZE = 40
const page = ref(1)

const pageCount = computed(() => Math.max(1, Math.ceil(entries.value.length / PAGE_SIZE)))

// entries is always kept in rank order (commitRank, rearrange and drag all
// maintain that), so a page is simply a contiguous slice of it — page 1 is
// ranks 1-40, page 2 is 41-80, and so on. pageStart is the global index
// (0-based) that page-local index 0 corresponds to.
const pageStart = computed(() => (page.value - 1) * PAGE_SIZE)
const pagedEntries = computed(() => entries.value.slice(pageStart.value, pageStart.value + PAGE_SIZE))

function goToPage(target) {
  page.value = Math.min(Math.max(1, target), pageCount.value)
}

/** Page numbers to show: every page when few, otherwise decade jumps. */
const pageLinks = computed(() => {
  const pages = []
  const last = pageCount.value

  for (let i = 1; i <= Math.min(10, last); i++) pages.push(i)
  for (let i = 20; i <= last; i += 10) pages.push(i)
  if (last > 10 && !pages.includes(last)) pages.push(last)

  return pages
})

// If entries shrinks (should not normally happen mid-round, but guards
// against it) or the page ref is otherwise left pointing past the end,
// pull it back onto a real page rather than rendering an empty grid.
watch(pageCount, (count) => {
  if (page.value > count) page.value = count
})

// The image id whose rank was just typed, so its tile can be highlighted
// while it moves to the new position — cleared after the highlight has
// had time to be seen, so it does not linger on a tile the juror is no
// longer looking at.
const justMovedId = ref(null)
let justMovedTimer = null

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
// entry wrapping it, found by its global index since commitRank needs a
// position to insert at, not just the entry — and that position shifts
// as other entries are renumbered while the lightbox stays open.
const lightboxIndex = computed(() =>
  entries.value.findIndex((entry) => entry.image.id === lightbox.value?.id),
)
const lightboxEntry = computed(() =>
  lightboxIndex.value === -1 ? null : entries.value[lightboxIndex.value],
)

// Separate from lightboxIndex: Prev/Next only steps within the page the
// lightbox was opened from, matching where drag-reordering and the grid
// itself are scoped — stepping past the last tile actually on screen
// would land on an image the juror cannot currently see in the grid
// behind the lightbox.
const lightboxPageIndex = computed(() =>
  pagedEntries.value.findIndex((entry) => entry.image.id === lightbox.value?.id),
)

// Moves the open lightbox to the neighbouring image on the current page,
// without closing it — scanning through the set is the point of a
// lightbox, and forcing a close-reopen for every image defeats that.
function lightboxStep(direction) {
  const next = pagedEntries.value[lightboxPageIndex.value + direction]

  if (next) openLightbox(next.image)
}

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
 * Updates the typed text only — no reordering. Reordering on every
 * keystroke made a two-digit rank impossible to type: entering "1" of a
 * planned "12" moved the tile (and the field along with it) to position 1
 * before the second digit could be typed. commitRank does the actual
 * move, once the juror presses Enter or leaves the field.
 */
function editRank(imageId, rawValue) {
  const entry = entries.value.find((item) => item.image.id === imageId)

  if (entry) entry.rank = rawValue
}

/**
 * Renumbers 1..N in array order, moves the highlighted tile's id onto
 * highlightId, and replaces entries with the reordered array in one go.
 *
 * entries is keyed by image id in the template, so assigning a new array
 * here moves each tile's existing DOM element rather than re-rendering
 * it — which is what lets the highlight travel with the tile instead of
 * flashing on whatever now happens to sit in its old spot. Shared by
 * commitRank and the drag handler, since both end with the same move: a
 * new order for entries, and something to point the highlight at.
 */
function applyOrder(ordered, highlightId) {
  for (const [index, item] of ordered.entries()) {
    const next = String(index + 1)

    if (item.rank !== next) {
      item.rank = next
    }
  }

  entries.value = ordered

  justMovedId.value = highlightId
  clearTimeout(justMovedTimer)
  justMovedTimer = setTimeout(() => {
    if (justMovedId.value === highlightId) justMovedId.value = null
  }, 1200)
}

/**
 * Applies a typed rank by inserting the image at that position and
 * shifting everything from there down — then moves its tile there too, so
 * the grid always shows the order the numbers describe.
 *
 * Without the renumbering, committing "1" on a second image would
 * silently leave two images sharing rank 1. Reassigning the whole
 * sequence keeps the ranks a genuine permutation at every commit, so a
 * duplicate can never be submitted in the first place.
 */
function commitRank(imageId) {
  const entry = entries.value.find((item) => item.image.id === imageId)
  if (!entry) return

  const requested = Number(entry.rank)

  // Leave a cleared or invalid field as it is rather than reshuffling —
  // validation catches anything still blank or duplicated at submit.
  if (!Number.isInteger(requested) || requested < 1) {
    return
  }

  const target = Math.min(requested, entries.value.length)

  // Order everything else by its current rank, then drop this image in at
  // the requested position and renumber the sequence.
  const others = entries.value
    .filter((item) => item.image.id !== imageId)
    .sort((a, b) => (Number(a.rank) || Number.MAX_SAFE_INTEGER) - (Number(b.rank) || Number.MAX_SAFE_INTEGER))

  others.splice(target - 1, 0, entry)

  applyOrder(others, entry.image.id)
}

/**
 * Turns a drag on the current page into a global reorder.
 *
 * SortableJS reports positions within the DOM list it manages, which here
 * is only the visible page — so oldIndex/newIndex are page-local and have
 * to be translated into positions in the full, rank-ordered entries array
 * before the rest of the app (submit, the rank fields, page 2 onward) sees
 * anything different.
 */
function reorderByDrag(oldPageIndex, newPageIndex) {
  if (oldPageIndex === newPageIndex) return

  const moved = pagedEntries.value[oldPageIndex]
  if (!moved) return

  const ordered = [...entries.value]
  const fromGlobal = pageStart.value + oldPageIndex
  const toGlobal = pageStart.value + newPageIndex

  ordered.splice(fromGlobal, 1)
  ordered.splice(toGlobal, 0, moved)

  applyOrder(ordered, moved.image.id)
}

const gridRef = ref(null)
let sortable = null

// (Re)creates the Sortable instance bound to the current page's DOM. Torn
// down and rebuilt on every page change rather than left running across
// pages: Sortable tracks the list of child elements it was given at
// creation, and after Vue swaps in a new page's tiles that list is stale.
function setUpSortable() {
  sortable?.destroy()
  sortable = null

  if (!gridRef.value) return

  sortable = new Sortable(gridRef.value, {
    handle: '.tile-drag-handle',
    animation: 150,
    ghostClass: 'rank-tile-drag-ghost',
    onEnd(event) {
      reorderByDrag(event.oldIndex, event.newIndex)
    },
  })
}

// Rebuilds after every reorder, not just a page change: pagedEntries is a
// fresh array whenever entries or page changes, which covers a typed
// commit, Rearrange and a drag alike. nextTick so this runs against the
// DOM Vue has already patched to match the new order, rather than the
// stale list of children Sortable was handed when it was last created.
watch(pagedEntries, () => nextTick(setUpSortable), { immediate: true })

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

onUnmounted(() => {
  clearTimeout(justMovedTimer)
  sortable?.destroy()
})
</script>

<template>
  <CdxMessage v-if="error" type="error">{{ error }}</CdxMessage>
  <CdxMessage v-if="done" type="success">Your ranking has been saved.</CdxMessage>

  <template v-else>
    <CdxMessage type="notice">
      Enter a rank for each image, 1 being the best, or drag a tile by its handle to reorder it
      against the rest of the page. Use <strong>Rearrange</strong> to sort the gallery by the ranks
      you have entered.
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

    <div ref="gridRef" class="gallery-grid">
      <figure
        v-for="entry in pagedEntries"
        :key="entry.image.id"
        class="gallery-tile rank-tile"
        :class="{
          'rank-tile-invalid': rankStatus(entry) === 'error',
          'rank-tile-moved': justMovedId === entry.image.id,
        }"
      >
        <!-- The only draggable part of the tile: dragging from the image
             or the rank field itself would fight with viewing it larger
             or selecting the field's text to retype a number. -->
        <span class="tile-drag-handle" aria-hidden="true" title="Drag to reorder">⠿</span>

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
            @update:model-value="editRank(entry.image.id, $event)"
            @keydown.enter="commitRank(entry.image.id)"
            @blur="commitRank(entry.image.id)"
          />
        </figcaption>
      </figure>
    </div>

    <!-- Same pager as the gallery grid. Purely local — every entry is
         already loaded, this just limits how many tiles render and how
         many a drag has to reorder among at once. -->
    <nav v-if="pageCount > 1" class="pager">
      <CdxButton
        weight="quiet"
        :disabled="page === 1"
        aria-label="Previous page"
        @click="goToPage(page - 1)"
      >
        «
      </CdxButton>

      <CdxButton
        v-for="link in pageLinks"
        :key="link"
        weight="quiet"
        :aria-current="link === page ? 'page' : undefined"
        :class="{ 'pager-current': link === page }"
        @click="goToPage(link)"
      >
        {{ link }}
      </CdxButton>

      <CdxButton
        weight="quiet"
        :disabled="page === pageCount"
        aria-label="Next page"
        @click="goToPage(page + 1)"
      >
        »
      </CdxButton>
    </nav>
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
          @update:model-value="editRank(lightbox.id, $event)"
          @keydown.enter="commitRank(lightbox.id)"
          @blur="commitRank(lightbox.id)"
        />
      </template>

      <div class="row lightbox-panel-nav">
        <CdxButton :disabled="lightboxPageIndex <= 0" @click="lightboxStep(-1)">
          <CdxIcon :icon="cdxIconPrevious" /> Prev
        </CdxButton>
        <CdxButton
          :disabled="lightboxPageIndex === -1 || lightboxPageIndex >= pagedEntries.length - 1"
          @click="lightboxStep(1)"
        >
          Next <CdxIcon :icon="cdxIconNext" />
        </CdxButton>
      </div>

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
