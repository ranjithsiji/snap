<script setup>
import { RouterLink } from 'vue-router'
import { CdxButton, CdxIcon } from '@wikimedia/codex'
import { cdxIconImageGallery, cdxIconLogIn } from '@wikimedia/codex-icons'
import { useSession } from '@/stores/session'

const session = useSession()

// What the tool actually does, in the order a campaign meets it.
const does = [
  {
    title: 'Brings the entries in',
    body:
      'Point a round at a Commons category and every file arrives with ' +
      'its metadata — resolution, uploader, upload date. Nothing is ' +
      'copied: photographs are loaded from Wikimedia servers when they ' +
      'are shown.',
  },
  {
    title: 'Divides them among the jury',
    body:
      'Set how many jurors must see each photograph and Snap shares the ' +
      'images out evenly, so every file gets the votes it needs and no ' +
      'juror is shown the same one twice.',
  },
  {
    title: 'Narrows the field round by round',
    body:
      'Judge yes/no, by star rating, or by rank. Each round can inherit ' +
      'from the one before it by threshold, taking a campaign from ' +
      'thousands of entries down to a shortlist.',
  },
  {
    title: 'Settles the result',
    body:
      'In the final meeting every juror’s ranking is kept and shown side ' +
      'by side. Where they disagree, jurors argue the case against a ' +
      'specific image and the outcome is recorded.',
  },
]
</script>

<template>
  <div class="landing">
    <!-- Two columns: the claim on the left, and on the right a sketch of
         what judging actually looks like. A tool for looking at
         photographs should show one before asking anyone to sign in. -->
    <section class="hero">
      <div class="hero-text">
        <img src="/logo.svg" alt="" class="hero-logo" width="72" height="72" />

        <h1 class="hero-title">Judging for Wiki&nbsp;Loves campaigns</h1>

        <p class="hero-lede">
          From a Commons category to a final result — importing the entries,
          sharing them fairly among the jury, and collecting the votes round
          by round.
        </p>

        <div class="hero-actions">
          <!-- Signed in, the useful destination is the juror's own work
               rather than this page. -->
          <CdxButton
            v-if="session.isAuthenticated"
            action="progressive"
            weight="primary"
            size="large"
            @click="$router.push({ name: 'my-rounds' })"
          >
            <CdxIcon :icon="cdxIconImageGallery" /> Go to my rounds
          </CdxButton>
          <CdxButton
            v-else
            action="progressive"
            weight="primary"
            size="large"
            @click="$router.push({ name: 'login' })"
          >
            <CdxIcon :icon="cdxIconLogIn" /> Sign in
          </CdxButton>

          <CdxButton size="large" @click="$router.push({ name: 'about' })">
            About Snap
          </CdxButton>
        </div>
      </div>

      <!-- A still of the voting screen, drawn rather than screenshotted so
           it needs no real photographs and stays true after redesigns. -->
      <div class="hero-panel" aria-hidden="true">
        <div class="panel-bar">
          <span class="panel-dot"></span>
          <span class="panel-dot"></span>
          <span class="panel-dot"></span>
          <span class="panel-title">Round 2 · Rating</span>
        </div>

        <div class="panel-grid">
          <div v-for="n in 6" :key="n" class="panel-tile" :class="`tile-${n}`">
            <span class="panel-stars">{{ '★'.repeat(((n * 2) % 3) + 3) }}</span>
          </div>
        </div>

        <div class="panel-foot">
          <span>1,248 of 6,658 judged</span>
          <span class="panel-meter"><span :style="{ width: '19%' }"></span></span>
        </div>
      </div>
    </section>

    <section class="about">
      <h2 class="about-title">What the tool does</h2>
      <p class="about-intro">
        A Wiki Loves campaign can gather tens of thousands of photographs.
        Snap is what turns that into a result a jury can stand behind.
      </p>

      <div class="about-grid">
        <div v-for="item in does" :key="item.title" class="about-item">
          <h3 class="about-item-title">{{ item.title }}</h3>
          <p class="about-item-body">{{ item.body }}</p>
        </div>
      </div>

      <p class="about-more">
        <RouterLink :to="{ name: 'about' }">
          More about how Snap works
        </RouterLink>
      </p>
    </section>

    <section class="tech">
      <h2 class="tech-title">Technical details</h2>

      <p class="tech-prose">
        Snap is deployed on
        <a href="https://wikitech.wikimedia.org/wiki/Portal:Toolforge">Wikimedia Toolforge</a>
        and built with the
        <a href="https://doc.wikimedia.org/codex/latest/">Codex</a>
        design system. It is maintained by the
        <a href="https://w.wiki/t9">Wikimedians of Kerala User Group</a>
        and developed by
        <a href="https://w.wiki/tN">Ranjithsiji</a>, and released under the
        <a href="https://www.gnu.org/licenses/gpl-3.0.html">GNU GPL v3.0</a>
        or later.
      </p>
    </section>
  </div>
</template>

<style scoped>
.landing {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-200);
}

/* --- Hero ------------------------------------------------------------ */

/* Two columns on a desktop, stacked below. The text column is narrower
   than the panel so the claim stays readable rather than stretching. */
.hero {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1.05fr);
  align-items: center;
  gap: var(--spacing-200);
  padding: var(--spacing-200) 0 var(--spacing-150);
}

@media (max-width: 60rem) {
  .hero {
    grid-template-columns: 1fr;
    text-align: center;
    padding-top: var(--spacing-150);
  }

  .hero-text {
    justify-items: center;
  }

  .hero-actions {
    justify-content: center;
  }
}

.hero-logo {
  width: 4.5rem;
  height: auto;
  margin-bottom: var(--spacing-100);
}

.hero-title {
  margin: 0 0 var(--spacing-75);
  font-size: clamp(2rem, 4vw, 3rem);
  font-weight: var(--font-weight-bold);
  line-height: 1.1;
  letter-spacing: -0.02em;
}

.hero-lede {
  margin: 0 0 var(--spacing-150);
  max-width: 32rem;
  font-size: var(--font-size-large);
  line-height: var(--line-height-medium);
  color: var(--color-subtle);
}

.hero-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--spacing-75);
}

/* --- Hero panel ------------------------------------------------------ */

.hero-panel {
  border-radius: 12px;
  overflow: hidden;
  background-color: var(--snap-navy);
  box-shadow: 0 12px 32px var(--snap-shadow), 0 2px 8px var(--snap-shadow);
}

.panel-bar {
  display: flex;
  align-items: center;
  gap: var(--spacing-25);
  padding: var(--spacing-50) var(--spacing-75);
  background-color: rgba(0, 0, 0, 0.25);
}

.panel-dot {
  width: 0.5rem;
  height: 0.5rem;
  border-radius: var(--border-radius-circle);
  background-color: rgba(255, 255, 255, 0.25);
}

.panel-title {
  margin-left: var(--spacing-50);
  color: rgba(255, 255, 255, 0.7);
  font-size: var(--font-size-x-small);
}

.panel-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: var(--spacing-50);
  padding: var(--spacing-75);
}

/* Stand-ins for photographs: tinted blocks, so the panel needs no real
   images and cannot misrepresent anyone's work. */
.panel-tile {
  aspect-ratio: 4 / 3;
  border-radius: 6px;
  display: flex;
  align-items: flex-end;
  padding: var(--spacing-25);
  background: linear-gradient(140deg, #2b4a8f, #1b2360);
}

.tile-2 { background: linear-gradient(140deg, #3f5aa6, #232a6b); }
.tile-3 { background: linear-gradient(140deg, #24427f, #141c50); }
.tile-4 { background: linear-gradient(140deg, #35508f, #1d2560); }
.tile-5 { background: linear-gradient(140deg, #4a63b0, #263070); }
.tile-6 { background: linear-gradient(140deg, #1f3a72, #121a4a); }

.panel-stars {
  font-size: 0.625rem;
  color: var(--snap-periwinkle);
  letter-spacing: 0.05em;
}

.panel-foot {
  display: flex;
  align-items: center;
  gap: var(--spacing-75);
  padding: var(--spacing-50) var(--spacing-75) var(--spacing-75);
  color: rgba(255, 255, 255, 0.65);
  font-size: var(--font-size-x-small);
}

.panel-meter {
  flex: 1;
  height: 0.25rem;
  border-radius: var(--border-radius-pill);
  background-color: rgba(255, 255, 255, 0.15);
  overflow: hidden;
}

.panel-meter span {
  display: block;
  height: 100%;
  background-color: var(--snap-periwinkle);
}

/* --- About ----------------------------------------------------------- */

.about {
  max-width: 60rem;
  margin: 0 auto;
  padding-bottom: var(--spacing-150);
  text-align: center;
}

.about-title {
  margin: 0 0 var(--spacing-50);
  font-size: var(--font-size-xx-large);
  font-weight: var(--font-weight-bold);
}

.about-intro {
  margin: 0 auto var(--spacing-150);
  max-width: 36rem;
  color: var(--color-subtle);
}

/* Two columns rather than auto-fit: there are four cards, and auto-fit
   settles on three at this width, leaving one stranded on its own row. */
.about-grid {
  display: grid;
  gap: var(--spacing-100);
  grid-template-columns: repeat(2, 1fr);
  text-align: left;
}

@media (max-width: 40rem) {
  .about-grid {
    grid-template-columns: 1fr;
  }
}

.about-item {
  padding: var(--spacing-125);
  background-color: var(--background-color-base);
  border: var(--border-base);
  border-radius: var(--border-radius-base);
}

.about-item-title {
  margin: 0 0 var(--spacing-50);
  font-size: var(--font-size-medium);
  font-weight: var(--font-weight-bold);
}

.about-item-body {
  margin: 0;
  font-size: var(--font-size-small);
  color: var(--color-subtle);
}

.about-more {
  margin: var(--spacing-150) 0 0;
}

/* --- Technical details ----------------------------------------------- */

/* Full width rather than centred on a column: the section is a short
   list of facts, and it reads as a footer to the page. */
.tech {
  width: 100%;
  padding: var(--spacing-150) 0 var(--spacing-200);
  border-top: var(--border-base);
}

.tech-title {
  margin: 0 0 var(--spacing-100);
  font-size: var(--font-size-x-large);
  font-weight: var(--font-weight-bold);
}

/* Prose rather than a label/value grid: these are five short facts that
   read as one sentence, and a definition list gave each its own row of
   scaffolding for no gain. */
.tech-prose {
  max-width: 44rem;
  margin: 0;
  color: var(--color-subtle);
  line-height: var(--line-height-medium);
}
</style>
