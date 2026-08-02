<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import MainNavLayout from '@/Layouts/MainNavLayout.vue';
import HelpScreenshot from '@/Components/HelpScreenshot.vue';
import {
  getHelpCategories,
  getHelpTopicsForRole,
  helpLevels,
  helpRoleLabels,
} from '@/Support/helpContent.js';
import Magnify from 'vue-material-design-icons/Magnify.vue';
import BookOpenVariant from 'vue-material-design-icons/BookOpenVariant.vue';
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue';
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue';
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue';
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue';

const page = usePage();
const user = page.props.auth.user || {};
const search = ref('');
const selectedLevel = ref('all');
const completedTopicIds = ref([]);

const topics = computed(() => getHelpTopicsForRole(user.role));
const requestedTopicId = typeof window !== 'undefined'
  ? new URLSearchParams(window.location.search).get('topic')
  : null;
const selectedTopicId = ref(
  topics.value.some((topic) => topic.id === requestedTopicId)
    ? requestedTopicId
    : topics.value[0]?.id || null,
);
const roleLabel = computed(() => helpRoleLabels[user.role] || 'Utilisateur');
const storageKey = computed(() => `tiketi.help.completed.${user.id || user.role || 'user'}`);

const rolePromises = {
  admin: 'Configurez l’entreprise, sécurisez les accès et accompagnez l’exploitation.',
  fleet_manager: 'Préparez les véhicules, les plans de sièges et les équipages.',
  supervisor: 'Suivez les départs, contrôlez les ventes et traitez les alertes.',
  seller: 'Vendez rapidement, imprimez les tickets et gérez les cas courants.',
  accountant: 'Contrôlez les recettes et produisez des rapports fiables.',
  executive: 'Lisez les indicateurs utiles pour décider rapidement.',
};

const topicMatchesSearch = (topic, term) => [
  topic.title,
  topic.description,
  topic.outcome,
  topic.category,
  ...(topic.sections || []).flatMap((section) => [section.title, section.body, ...(section.steps || [])]),
].join(' ').toLowerCase().includes(term);

const visibleTopics = computed(() => {
  const term = search.value.trim().toLowerCase();

  return topics.value.filter((topic) => {
    const matchesLevel = selectedLevel.value === 'all' || topic.level === selectedLevel.value;
    return matchesLevel && (!term || topicMatchesSearch(topic, term));
  });
});

const categories = computed(() => getHelpCategories(visibleTopics.value));
const groupedTopics = computed(() => categories.value
  .map((category) => ({
    category,
    topics: visibleTopics.value.filter((topic) => (topic.category || 'Général') === category),
  }))
  .filter((group) => group.topics.length > 0));

const selectedTopic = computed(() => visibleTopics.value.find((topic) => topic.id === selectedTopicId.value)
  || visibleTopics.value[0]
  || null);

const selectedTopicIndex = computed(() => visibleTopics.value.findIndex((topic) => topic.id === selectedTopic.value?.id));
const previousTopic = computed(() => selectedTopicIndex.value > 0 ? visibleTopics.value[selectedTopicIndex.value - 1] : null);
const nextTopic = computed(() => selectedTopicIndex.value >= 0 ? visibleTopics.value[selectedTopicIndex.value + 1] || null : null);

const completedCount = computed(() => topics.value.filter((topic) => completedTopicIds.value.includes(topic.id)).length);
const progress = computed(() => topics.value.length ? Math.round((completedCount.value / topics.value.length) * 100) : 0);

const levelCount = (levelId) => topics.value.filter((topic) => topic.level === levelId).length;
const levelLabel = (levelId) => helpLevels.find((level) => level.id === levelId)?.label || levelId;
const isCompleted = (topicId) => completedTopicIds.value.includes(topicId);

const selectTopic = (topicId) => {
  selectedTopicId.value = topicId;
};

const selectLevel = (levelId) => {
  selectedLevel.value = levelId;
  const firstTopic = topics.value.find((topic) => levelId === 'all' || topic.level === levelId);
  selectedTopicId.value = firstTopic?.id || null;
};

const toggleCompleted = (topicId) => {
  completedTopicIds.value = isCompleted(topicId)
    ? completedTopicIds.value.filter((id) => id !== topicId)
    : [...completedTopicIds.value, topicId];

  if (typeof window !== 'undefined') {
    window.localStorage.setItem(storageKey.value, JSON.stringify(completedTopicIds.value));
  }
};

onMounted(() => {
  try {
    const saved = JSON.parse(window.localStorage.getItem(storageKey.value) || '[]');
    completedTopicIds.value = Array.isArray(saved) ? saved : [];
  } catch (error) {
    completedTopicIds.value = [];
  }
});

watch(visibleTopics, (nextTopics) => {
  if (!nextTopics.some((topic) => topic.id === selectedTopicId.value)) {
    selectedTopicId.value = nextTopics[0]?.id || null;
  }
});
</script>

<template>
  <MainNavLayout :fullHeight="true">
    <div class="flex h-full min-h-0 flex-col overflow-y-auto bg-slate-50 dark:bg-slate-950 lg:overflow-hidden">
      <header class="shrink-0 border-b border-slate-200 bg-white px-4 py-5 dark:border-slate-800 dark:bg-slate-900 sm:px-6">
        <div class="mx-auto max-w-[1500px]">
          <div class="flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
            <div class="max-w-3xl">
              <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center gap-2 text-xs font-black uppercase tracking-[0.18em] text-emerald-600 dark:text-emerald-400">
                  <BookOpenVariant :size="18" />
                  Guide TIKETI
                </span>
                <span class="rounded-full bg-green-50 px-3 py-1 text-xs font-black text-green-700 ring-1 ring-green-200 dark:bg-green-950/50 dark:text-green-300 dark:ring-green-800">
                  Parcours {{ roleLabel }}
                </span>
              </div>
              <h1 class="mt-3 text-3xl font-black tracking-tight text-slate-950 dark:text-white sm:text-4xl">
                Apprenez à votre rythme
              </h1>
              <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300 sm:text-base">
                {{ rolePromises[user.role] || 'Retrouvez les procédures utiles à votre travail, du premier pas aux fonctions avancées.' }}
              </p>
            </div>

            <div class="grid w-full gap-3 sm:grid-cols-[minmax(280px,420px)_180px] xl:w-auto">
              <label class="relative block">
                <span class="sr-only">Rechercher dans le guide</span>
                <Magnify :size="20" class="absolute left-3 top-1/2 -translate-y-1/2 text-emerald-500 dark:text-emerald-400" />
                <input
                  v-model="search"
                  type="search"
                  class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3 pl-10 pr-4 text-sm text-slate-900 shadow-sm focus:border-green-500 focus:ring-green-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                  placeholder="Que voulez-vous faire ?"
                />
              </label>
              <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 dark:border-slate-700 dark:bg-slate-800">
                <div class="flex items-center justify-between text-xs font-bold text-slate-500 dark:text-slate-400">
                  <span>Votre progression</span>
                  <span>{{ progress }} %</span>
                </div>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                  <div class="h-full rounded-full bg-green-600 transition-all" :style="{ width: `${progress}%` }"></div>
                </div>
              </div>
            </div>
          </div>

          <div class="mt-5 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
            <button
              type="button"
              @click="selectLevel('all')"
              :class="[
                'rounded-xl border px-4 py-3 text-left transition-all',
                selectedLevel === 'all'
                  ? 'border-slate-900 bg-slate-900 text-white shadow-md dark:border-white dark:bg-white dark:text-slate-900'
                  : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200'
              ]"
            >
              <div class="text-sm font-black">Tout le parcours</div>
              <div class="mt-1 text-xs opacity-70">{{ topics.length }} guides pour votre rôle</div>
            </button>
            <button
              v-for="(level, index) in helpLevels"
              :key="level.id"
              type="button"
              @click="selectLevel(level.id)"
              :class="[
                'rounded-xl border px-4 py-3 text-left transition-all',
                selectedLevel === level.id
                  ? 'border-green-600 bg-green-600 text-white shadow-md'
                  : 'border-slate-200 bg-white text-slate-700 hover:border-green-300 hover:bg-green-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-green-950/30'
              ]"
            >
              <div class="flex items-center justify-between gap-2">
                <span class="text-sm font-black">{{ index + 1 }}. {{ level.shortLabel }}</span>
                <span class="rounded-full bg-black/10 px-2 py-0.5 text-[10px] font-black">{{ levelCount(level.id) }}</span>
              </div>
              <div class="mt-1 text-xs opacity-70">Niveau {{ level.label.toLowerCase() }}</div>
            </button>
          </div>
        </div>
      </header>

      <div class="mx-auto grid w-full max-w-[1500px] grid-cols-12 lg:min-h-0 lg:flex-1">
        <aside class="col-span-12 max-h-[300px] overflow-y-auto border-b border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 lg:col-span-3 lg:max-h-none lg:border-b-0 lg:border-r">
          <div class="mb-4 flex items-center justify-between px-2">
            <div>
              <div class="text-[11px] font-black uppercase tracking-widest text-slate-400">Sommaire</div>
              <div class="mt-1 text-sm font-bold text-slate-700 dark:text-slate-200">{{ visibleTopics.length }} rubrique{{ visibleTopics.length > 1 ? 's' : '' }}</div>
            </div>
            <button
              v-if="search || selectedLevel !== 'all'"
              type="button"
              class="text-xs font-black text-emerald-600 hover:text-emerald-700 dark:text-emerald-400"
              @click="search = ''; selectLevel('all')"
            >
              Réinitialiser
            </button>
          </div>

          <div v-if="groupedTopics.length" class="space-y-5">
            <section v-for="group in groupedTopics" :key="group.category">
              <h2 class="mb-2 px-2 text-[11px] font-black uppercase tracking-widest text-slate-400">
                {{ group.category }}
              </h2>
              <div class="space-y-1">
                <button
                  v-for="topic in group.topics"
                  :key="topic.id"
                  type="button"
                  @click="selectTopic(topic.id)"
                  :class="[
                    'group w-full rounded-xl px-3 py-3 text-left transition-all',
                    selectedTopic?.id === topic.id
                      ? 'bg-green-50 text-green-900 ring-1 ring-green-200 dark:bg-green-950/40 dark:text-green-200 dark:ring-green-800'
                      : 'text-slate-600 hover:bg-emerald-50 hover:text-emerald-800 dark:text-slate-300 dark:hover:bg-slate-800'
                  ]"
                >
                  <div class="flex items-start gap-2">
                    <span :class="['mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full text-[10px] font-black', isCompleted(topic.id) ? 'bg-green-600 text-white' : 'bg-slate-100 text-slate-400 dark:bg-slate-700']">
                      {{ isCompleted(topic.id) ? '✓' : '•' }}
                    </span>
                    <span class="min-w-0">
                      <span class="block text-sm font-black leading-tight">{{ topic.title }}</span>
                      <span class="mt-1 flex items-center gap-2 text-[11px] opacity-70">
                        <span>{{ levelLabel(topic.level) }}</span>
                        <span>·</span>
                        <span>{{ topic.duration }} min</span>
                      </span>
                    </span>
                  </div>
                </button>
              </div>
            </section>
          </div>

          <div v-else class="rounded-xl border border-dashed border-slate-200 p-5 text-center dark:border-slate-700">
            <div class="font-black text-slate-900 dark:text-white">Aucun résultat</div>
            <p class="mt-2 text-sm text-slate-500">Essayez un autre mot ou affichez tous les niveaux.</p>
          </div>
        </aside>

        <main class="col-span-12 overflow-visible p-4 sm:p-6 lg:col-span-9 lg:min-h-0 lg:overflow-y-auto lg:p-8">
          <article v-if="selectedTopic" class="mx-auto max-w-5xl">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-7">
              <div class="grid gap-7 xl:grid-cols-[1fr_340px] xl:items-start">
                <div>
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full bg-emerald-50 px-3 py-1 text-[11px] font-black uppercase tracking-wider text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                      {{ selectedTopic.category || 'Général' }}
                    </span>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-[11px] font-black text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                      Niveau {{ levelLabel(selectedTopic.level).toLowerCase() }}
                    </span>
                    <span class="inline-flex items-center gap-1 text-xs font-bold text-slate-500 dark:text-slate-400">
                      <ClockOutline :size="15" /> {{ selectedTopic.duration }} min
                    </span>
                  </div>
                  <h2 class="mt-4 text-3xl font-black leading-tight tracking-tight text-slate-950 dark:text-white">
                    {{ selectedTopic.title }}
                  </h2>
                  <p class="mt-3 text-base leading-7 text-slate-600 dark:text-slate-300">
                    {{ selectedTopic.description }}
                  </p>
                  <div class="mt-5 rounded-xl border border-green-200 bg-green-50 p-4 dark:border-green-900 dark:bg-green-950/30">
                    <div class="text-[11px] font-black uppercase tracking-widest text-green-700 dark:text-green-400">À la fin de ce guide</div>
                    <p class="mt-1 text-sm font-bold leading-6 text-green-900 dark:text-green-200">{{ selectedTopic.outcome }}</p>
                  </div>
                </div>

                <HelpScreenshot :src="selectedTopic.image" :title="selectedTopic.title" />
              </div>
            </div>

            <div class="mt-6 space-y-4">
              <section
                v-for="(section, sectionIndex) in selectedTopic.sections"
                :key="section.title"
                class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-6"
              >
                <div class="flex gap-3">
                  <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-emerald-50 text-xs font-black text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                    {{ sectionIndex + 1 }}
                  </span>
                  <div class="min-w-0 flex-1">
                    <h3 class="text-xl font-black text-slate-950 dark:text-white">{{ section.title }}</h3>
                    <p class="mt-2 text-sm leading-7 text-slate-600 dark:text-slate-300">{{ section.body }}</p>

                    <div v-if="section.links?.length" class="mt-4 flex flex-wrap gap-2">
                      <a
                        v-for="link in section.links"
                        :key="link.url"
                        :href="link.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex rounded-lg bg-green-50 px-3 py-2 text-sm font-black text-green-700 hover:bg-green-100 dark:bg-green-950/40 dark:text-green-300"
                      >
                        {{ link.label }}
                      </a>
                    </div>

                    <ol v-if="section.steps?.length" class="mt-5 grid gap-3">
                      <li v-for="(step, index) in section.steps" :key="step" class="flex gap-3 rounded-xl bg-slate-50 p-3 text-sm leading-6 text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                        <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-green-600 text-[11px] font-black text-white">
                          {{ index + 1 }}
                        </span>
                        <span>{{ step }}</span>
                      </li>
                    </ol>
                  </div>
                </div>
              </section>
            </div>

            <div class="mt-6 rounded-2xl border border-green-200 bg-green-50 p-5 dark:border-green-900 dark:bg-green-950/30 sm:flex sm:items-center sm:justify-between sm:gap-4">
              <div>
                <div class="flex items-center gap-2 font-black text-green-950 dark:text-green-100">
                  <CheckCircleOutline :size="21" />
                  Avez-vous terminé cette rubrique ?
                </div>
                <p class="mt-1 text-sm leading-6 text-green-800 dark:text-green-300">Votre progression reste enregistrée sur cet appareil.</p>
              </div>
              <button
                type="button"
                @click="toggleCompleted(selectedTopic.id)"
                :class="[
                  'mt-3 shrink-0 rounded-xl px-4 py-3 text-sm font-black transition sm:mt-0',
                  isCompleted(selectedTopic.id)
                    ? 'bg-white text-green-700 ring-1 ring-green-300 hover:bg-green-100 dark:bg-slate-900 dark:text-green-300'
                    : 'bg-green-700 text-white shadow-sm hover:bg-green-800'
                ]"
              >
                {{ isCompleted(selectedTopic.id) ? 'Marqué comme terminé ✓' : 'Marquer comme terminé' }}
              </button>
            </div>

            <nav class="mt-6 grid gap-3 sm:grid-cols-2" aria-label="Navigation entre les guides">
              <button
                v-if="previousTopic"
                type="button"
                @click="selectTopic(previousTopic.id)"
                class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 text-left hover:border-emerald-300 hover:bg-emerald-50 dark:border-slate-800 dark:bg-slate-900 dark:hover:bg-slate-800"
              >
                <ChevronLeft :size="24" class="shrink-0 text-emerald-600" />
                <span><span class="block text-xs font-bold text-slate-400">Guide précédent</span><span class="mt-1 block text-sm font-black text-slate-800 dark:text-white">{{ previousTopic.title }}</span></span>
              </button>
              <button
                v-if="nextTopic"
                type="button"
                @click="selectTopic(nextTopic.id)"
                class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 text-left hover:border-green-300 hover:bg-green-50 dark:border-slate-800 dark:bg-slate-900 dark:hover:bg-slate-800 sm:col-start-2"
              >
                <span><span class="block text-xs font-bold text-slate-400">Guide suivant</span><span class="mt-1 block text-sm font-black text-slate-800 dark:text-white">{{ nextTopic.title }}</span></span>
                <ChevronRight :size="24" class="shrink-0 text-green-600" />
              </button>
            </nav>

            <div class="mt-6 rounded-xl border border-slate-200 bg-white p-4 text-sm leading-6 text-slate-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">
              <strong class="text-slate-900 dark:text-white">Besoin d’une réponse pendant votre travail ?</strong>
              Cliquez sur l’icône d’aide dans la barre supérieure : TIKETI ouvre directement la rubrique liée à la page courante.
            </div>
          </article>

          <div v-else class="mx-auto max-w-xl rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center dark:border-slate-700 dark:bg-slate-900">
            <div class="text-xl font-black text-slate-900 dark:text-white">Aucun guide ne correspond</div>
            <p class="mt-2 text-sm leading-6 text-slate-500">Modifiez votre recherche ou revenez à tout le parcours.</p>
            <button type="button" class="mt-5 rounded-xl bg-slate-900 px-4 py-3 text-sm font-black text-white dark:bg-white dark:text-slate-900" @click="search = ''; selectLevel('all')">
              Afficher tous les guides
            </button>
          </div>
        </main>
      </div>
    </div>
  </MainNavLayout>
</template>
