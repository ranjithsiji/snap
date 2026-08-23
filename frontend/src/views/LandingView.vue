<script setup>
import { RouterLink } from 'vue-router'
import { CdxButton } from '@wikimedia/codex'
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
    <section class="hero">
      <img src="/logo.svg" alt="" class="hero-logo" width="160" height="160" />

      <h1 class="hero-title">Snap</h1>
      <p class="hero-tagline">Judging for Wiki Loves photography campaigns</p>

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
          Go to my rounds
        </CdxButton>
        <CdxButton
          v-else
          action="progressive"
          weight="primary"
          size="large"
          @click="$router.push({ name: 'login' })"
        >
          Sign in
        </CdxButton>

        <CdxButton size="large" @click="$router.push({ name: 'about' })">
          About Snap
        </CdxButton>
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

      <div class="tech-grid">
        <div class="tech-block">
          <h3 class="tech-block-title">Built with</h3>
          <ul class="tech-list">
            <li>PHP 8.4 with Slim 4 and Doctrine ORM</li>
            <li>Vue 3 and <a href="https://doc.wikimedia.org/codex/latest/">Codex</a>,
              Wikimedia's design system</li>
            <li>MariaDB, on the Toolforge tools cluster</li>
            <li>Wikimedia OAuth 2.0 for sign-in</li>
          </ul>
        </div>

        <div class="tech-block">
          <h3 class="tech-block-title">Where the data comes from</h3>
          <ul class="tech-list">
            <li>Entries are read from
              <a href="https://commons.wikimedia.org/">Wikimedia Commons</a></li>
            <li>Categories are queried against the Commons replica database,
              falling back to the public API</li>
            <li>Photographs are never copied — they are served from
              Wikimedia and remain under their own licences</li>
          </ul>
        </div>

        <div class="tech-block">
          <h3 class="tech-block-title">Source and issues</h3>
          <ul class="tech-list">
            <li><a href="https://github.com/ranjithsiji/snap">Source code on GitHub</a></li>
            <li>
              <a href="https://phabricator.wikimedia.org/tag/tool-snap/">Bugs and tasks on Phabricator</a>
            </li>
            <li><a href="https://toolsadmin.wikimedia.org/tools/id/snap">Tool page on Toolsadmin</a></li>
            <li>Free software, for the Wikimedia community</li>
          </ul>
        </div>

        <div class="tech-block">
          <h3 class="tech-block-title">Maintained by</h3>
          <ul class="tech-list">
            <li>
              <a href="https://w.wiki/t9">Wikimedians of Kerala User Group</a>
            </li>
            <li>
              Developed by <a href="https://w.wiki/tN">Ranjithsiji</a>
            </li>
            <li>
              Hosted on
              <a href="https://wikitech.wikimedia.org/wiki/Portal:Toolforge">Wikimedia Toolforge</a>
            </li>
          </ul>
        </div>
      </div>
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

.hero {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  max-width: 46rem;
  margin: 0 auto;
  padding: var(--spacing-200) 0 var(--spacing-150);
}

.hero-logo {
  width: 10rem;
  max-width: 45vw;
  height: auto;
  margin-bottom: var(--spacing-125);
}

.hero-title {
  margin: 0;
  font-size: 2.75rem;
  font-weight: var(--font-weight-bold);
  line-height: 1.1;
}

.hero-tagline {
  margin: var(--spacing-25) 0 var(--spacing-100);
  font-size: var(--font-size-x-large);
  color: var(--color-subtle);
}

.hero-lede {
  margin: 0 0 var(--spacing-150);
  max-width: 34rem;
  font-size: var(--font-size-large);
  line-height: var(--line-height-medium);
  color: var(--color-subtle);
}

.hero-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: var(--spacing-75);
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

.tech {
  max-width: 60rem;
  margin: 0 auto;
  padding: var(--spacing-150) 0 var(--spacing-200);
  border-top: var(--border-base);
}

.tech-title {
  margin: 0 0 var(--spacing-125);
  font-size: var(--font-size-x-large);
  font-weight: var(--font-weight-bold);
}

/* Four short blocks, so they sit on one row on a desktop and fold to two
   and then one as the viewport narrows. */
.tech-grid {
  display: grid;
  gap: var(--spacing-150);
  grid-template-columns: repeat(4, 1fr);
}

@media (max-width: 64rem) {
  .tech-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 36rem) {
  .tech-grid {
    grid-template-columns: 1fr;
  }
}

.tech-block-title {
  margin: 0 0 var(--spacing-50);
  font-size: var(--font-size-medium);
  font-weight: var(--font-weight-bold);
}

.tech-list {
  margin: 0;
  padding-left: var(--spacing-100);
  font-size: var(--font-size-small);
  color: var(--color-subtle);
}

.tech-list li {
  margin-bottom: var(--spacing-25);
}
</style>
