<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import HelpScreenshot from '@/Components/HelpScreenshot.vue';
import { getHelpCategories, getHelpTopicsForRole } from '@/Support/helpContent.js';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import BookOpenVariant from 'vue-material-design-icons/BookOpenVariant.vue';

const page = usePage();
const user = page.props.auth.user || {};
const search = ref('');

const topics = computed(() => getHelpTopicsForRole(user.role));
const categories = computed(() => getHelpCategories(topics.value));
const selectedTopicId = ref(topics.value[0]?.id || null);

const topicMatchesSearch = (topic, term) => [
  topic.title,
  topic.description,
  topic.category,
  ...(topic.sections || []).flatMap((section) => [section.title, section.body, ...(section.steps || [])]),
].join(' ').toLowerCase().includes(term);

const visibleTopics = computed(() => {
  const term = search.value.trim().toLowerCase();
  if (!term) return topics.value;
  return topics.value.filter((topic) => topicMatchesSearch(topic, term));
});

const groupedTopics = computed(() => categories.value
  .map((category) => ({
    category,
    topics: visibleTopics.value.filter((topic) => (topic.category || 'Général') === category),
  }))
  .filter((group) => group.topics.length > 0));

const selectedTopic = computed(() => {
  return visibleTopics.value.find((topic) => topic.id === selectedTopicId.value)
    || visibleTopics.value[0]
    || topics.value[0];
});

watch(visibleTopics, (nextTopics) => {
  if (!nextTopics.some((topic) => topic.id === selectedTopicId.value)) {
    selectedTopicId.value = nextTopics[0]?.id || null;
  }
});
</script>

<template>
  <MainNavLayout :fullHeight="true">
    <div class="flex h-full min-h-0 flex-col bg-gray-50">
      <div class="shrink-0 border-b border-orange-200 bg-white px-6 py-5">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
          <div>
            <div class="flex items-center gap-2 text-xs font-black uppercase tracking-widest text-orange-600">
              <BookOpenVariant :size="18" />
              Centre d’aide
            </div>
            <h1 class="mt-2 text-3xl font-black text-gray-900">Documentation TIKETI</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600">
              Guides complets pour comprendre, configurer et exploiter la billetterie, la supervision, les rapports et la fidélité OKOHI.
            </p>
          </div>

          <div class="relative w-full xl:w-[420px]">
            <Magnify :size="20" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
            <input
              v-model="search"
              type="search"
              class="w-full rounded-xl border border-orange-200 bg-white py-3 pl-10 pr-4 text-sm shadow-sm focus:border-green-500 focus:ring-green-500"
              placeholder="Rechercher une rubrique, une procédure, un module"
            />
          </div>
        </div>
      </div>

      <div class="grid min-h-0 flex-1 grid-cols-12">
        <aside class="col-span-12 max-h-[280px] overflow-y-auto border-b border-orange-200 bg-white p-4 lg:col-span-3 lg:max-h-none lg:border-b-0 lg:border-r">
          <div v-if="groupedTopics.length" class="space-y-5">
            <section v-for="group in groupedTopics" :key="group.category">
              <h2 class="mb-2 px-2 text-[11px] font-black uppercase tracking-widest text-gray-400">
                {{ group.category }}
              </h2>
              <div class="space-y-1">
                <button
                  v-for="topic in group.topics"
                  :key="topic.id"
                  type="button"
                  @click="selectedTopicId = topic.id"
                  :class="[
                    'w-full rounded-lg px-3 py-3 text-left transition-all',
                    selectedTopic?.id === topic.id
                      ? 'bg-green-50 text-green-800 shadow-sm ring-1 ring-green-200'
                      : 'text-gray-600 hover:bg-orange-50 hover:text-orange-700'
                  ]"
                >
                  <div class="text-sm font-black leading-tight">{{ topic.title }}</div>
                  <div class="mt-1 line-clamp-2 text-xs leading-5 opacity-80">{{ topic.description }}</div>
                </button>
              </div>
            </section>
          </div>

          <div v-else class="rounded-lg border border-dashed border-orange-200 p-5 text-center">
            <div class="font-black text-gray-900">Aucun résultat</div>
            <p class="mt-2 text-sm text-gray-500">Essayez avec un autre mot-clé.</p>
          </div>
        </aside>

        <main class="col-span-12 min-h-0 overflow-y-auto p-5 lg:col-span-9 lg:p-8">
          <article v-if="selectedTopic" class="mx-auto max-w-5xl">
            <div class="grid gap-6 xl:grid-cols-[1fr_340px]">
              <div>
                <div class="text-xs font-black uppercase tracking-widest text-orange-600">
                  {{ selectedTopic.category || 'Général' }}
                </div>
                <h2 class="mt-2 text-3xl font-black leading-tight text-gray-900">
                  {{ selectedTopic.title }}
                </h2>
                <p class="mt-3 text-base leading-7 text-gray-600">
                  {{ selectedTopic.description }}
                </p>
              </div>

              <HelpScreenshot :src="selectedTopic.image" :title="selectedTopic.title" />
            </div>

            <div class="mt-8 space-y-6">
              <section
                v-for="section in selectedTopic.sections"
                :key="section.title"
                class="rounded-lg border border-orange-200 bg-white p-6 shadow-sm"
              >
                <h3 class="text-xl font-black text-gray-900">{{ section.title }}</h3>
                <p class="mt-3 text-sm leading-7 text-gray-600">{{ section.body }}</p>

                <div v-if="section.links?.length" class="mt-4 grid gap-2">
                  <a
                    v-for="link in section.links"
                    :key="link.url"
                    :href="link.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex w-fit rounded-lg bg-green-50 px-3 py-2 text-sm font-black text-green-700 hover:bg-green-100 hover:text-green-800"
                  >
                    {{ link.label }}
                  </a>
                </div>

                <ol v-if="section.steps?.length" class="mt-5 grid gap-3">
                  <li v-for="(step, index) in section.steps" :key="step" class="flex gap-3 rounded-lg bg-green-50 p-3 text-sm leading-6 text-gray-700">
                    <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-green-600 text-[11px] font-black text-white">
                      {{ index + 1 }}
                    </span>
                    <span>{{ step }}</span>
                  </li>
                </ol>
              </section>
            </div>

            <div class="mt-8 rounded-lg border border-green-200 bg-green-50 p-5">
              <div class="font-black text-green-900">Aide contextuelle</div>
              <p class="mt-2 text-sm leading-6 text-green-800">
                Depuis n’importe quelle page, cliquez sur l’icône d’aide dans la barre supérieure pour ouvrir directement la rubrique la plus proche de votre contexte.
              </p>
            </div>
          </article>
        </main>
      </div>
    </div>
  </MainNavLayout>
</template>
