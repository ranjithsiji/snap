<script setup>
import { computed, ref, watch } from 'vue'
import { CdxButton, CdxTextInput } from '@wikimedia/codex'

/**
 * A table with search, sorting and paging.
 *
 * Codex deliberately has no datatable — it is a component library for
 * MediaWiki interfaces, not an admin framework — and every admin list
 * here wants the same three behaviours. Rather than repeat them per
 * table, or add a second CSS framework whose tables are styling only and
 * would still leave the behaviour to write, they live here once.
 *
 * Columns are declared, not slotted, so sorting knows what it is sorting:
 *
 *   columns: [
 *     { key: 'name',  label: 'Round', sortable: true },
 *     { key: 'state', label: 'State', sortable: true, align: 'end' },
 *     { key: 'actions', label: '', sortable: false },
 *   ]
 *
 * A cell may still be rendered freely with a #cell-<key> slot; the
 * declaration only tells the table how to search and order the value.
 */
const props = defineProps({
  /** @type {{key: string, label: string, sortable?: boolean, align?: string}[]} */
  columns: { type: Array, required: true },
  rows: { type: Array, required: true },
  /** Row property to key on; falls back to the array index. */
  rowKey: { type: String, default: 'id' },
  searchable: { type: Boolean, default: true },
  searchPlaceholder: { type: String, default: 'Search…' },
  /** Keys searched. Defaults to every column key. */
  searchKeys: { type: Array, default: null },
  pageSize: { type: Number, default: 25 },
  emptyText: { type: String, default: 'Nothing here yet.' },
  noMatchText: { type: String, default: 'Nothing matches that search.' },
})

const query = ref('')
const sortKey = ref(null)
const sortAsc = ref(true)
const page = ref(1)

const searchFields = computed(
  () => props.searchKeys ?? props.columns.map((column) => column.key),
)

const filtered = computed(() => {
  const needle = query.value.trim().toLowerCase()

  if (needle === '') {
    return props.rows
  }

  return props.rows.filter((row) =>
    searchFields.value.some((key) =>
      String(row[key] ?? '').toLowerCase().includes(needle),
    ),
  )
})

const sorted = computed(() => {
  if (sortKey.value === null) {
    return filtered.value
  }

  // Copied before sorting: sort() mutates, and the source array belongs
  // to the caller.
  return [...filtered.value].sort((a, b) => {
    const left = a[sortKey.value]
    const right = b[sortKey.value]

    // Numbers compared as numbers, so 9 sorts before 10.
    if (typeof left === 'number' && typeof right === 'number') {
      return sortAsc.value ? left - right : right - left
    }

    const result = String(left ?? '').localeCompare(String(right ?? ''), undefined, {
      numeric: true,
      sensitivity: 'base',
    })

    return sortAsc.value ? result : -result
  })
})

const pageCount = computed(() => Math.max(1, Math.ceil(sorted.value.length / props.pageSize)))

const paged = computed(() => {
  const start = (page.value - 1) * props.pageSize

  return sorted.value.slice(start, start + props.pageSize)
})

// Searching or re-sorting can leave the current page past the end of the
// results, which would show an empty table with rows available.
watch([filtered, sorted], () => {
  if (page.value > pageCount.value) {
    page.value = 1
  }
})

function toggleSort(column) {
  if (column.sortable === false) {
    return
  }

  if (sortKey.value === column.key) {
    sortAsc.value = !sortAsc.value
  } else {
    sortKey.value = column.key
    sortAsc.value = true
  }
}

function ariaSort(column) {
  if (sortKey.value !== column.key) {
    return 'none'
  }

  return sortAsc.value ? 'ascending' : 'descending'
}
</script>

<template>
  <div class="datatable">
    <div v-if="searchable || $slots.toolbar" class="datatable-toolbar">
      <CdxTextInput
        v-if="searchable"
        v-model="query"
        :placeholder="searchPlaceholder"
        class="datatable-search"
      />

      <slot name="toolbar"></slot>

      <span class="spacer"></span>

      <span class="muted datatable-count">
        <template v-if="filtered.length === rows.length">
          {{ rows.length }} {{ rows.length === 1 ? 'row' : 'rows' }}
        </template>
        <template v-else>
          {{ filtered.length }} of {{ rows.length }}
        </template>
      </span>
    </div>

    <div v-if="rows.length === 0" class="card empty">
      <p>{{ emptyText }}</p>
    </div>

    <div v-else-if="filtered.length === 0" class="card empty">
      <p>{{ noMatchText }}</p>
    </div>

    <div v-else class="card table-scroll">
      <table>
        <thead>
          <tr>
            <th
              v-for="column in columns"
              :key="column.key"
              :aria-sort="ariaSort(column)"
              :class="{
                'is-sortable': column.sortable !== false,
                'is-sorted': sortKey === column.key,
                'align-end': column.align === 'end',
              }"
              @click="toggleSort(column)"
            >
              {{ column.label }}
              <span v-if="column.sortable !== false" class="datatable-arrow" aria-hidden="true">
                {{ sortKey === column.key ? (sortAsc ? '▲' : '▼') : '↕' }}
              </span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, index) in paged" :key="row[rowKey] ?? index">
            <td
              v-for="column in columns"
              :key="column.key"
              :class="{ 'align-end': column.align === 'end' }"
            >
              <slot :name="`cell-${column.key}`" :row="row" :value="row[column.key]">
                {{ row[column.key] }}
              </slot>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="pageCount > 1" class="datatable-pager">
      <CdxButton weight="quiet" :disabled="page === 1" @click="page--">Previous</CdxButton>
      <span class="muted">Page {{ page }} of {{ pageCount }}</span>
      <CdxButton weight="quiet" :disabled="page === pageCount" @click="page++">Next</CdxButton>
    </div>
  </div>
</template>

<style scoped>
.datatable-toolbar {
  display: flex;
  align-items: center;
  gap: var(--spacing-75);
  margin-bottom: var(--spacing-100);
  flex-wrap: wrap;
}

.datatable-search {
  max-width: 22rem;
}

.datatable-count {
  font-size: var(--font-size-small);
  white-space: nowrap;
}

.datatable :deep(th.is-sortable) {
  cursor: pointer;
  user-select: none;
}

.datatable :deep(th.is-sortable:hover) {
  color: var(--color-progressive);
}

.datatable :deep(th.is-sorted) {
  color: var(--color-progressive);
}

/* Dimmed until the column is the one being sorted, so the arrows read as
   an affordance rather than as noise across every heading. */
.datatable-arrow {
  opacity: 0.35;
  font-size: 0.75em;
}

.datatable :deep(th.is-sorted) .datatable-arrow {
  opacity: 1;
}

.datatable :deep(.align-end) {
  text-align: end;
}

.datatable-pager {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--spacing-100);
  margin-top: var(--spacing-100);
  font-size: var(--font-size-small);
}
</style>
