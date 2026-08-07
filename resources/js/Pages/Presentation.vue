<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useTheme } from '@/Composables/useTheme.js';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import LocaleSwitcher from '@/Components/LocaleSwitcher.vue';
import { toastStore } from '@/Stores/toastStore.js';
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue';
import TicketConfirmation from 'vue-material-design-icons/TicketConfirmation.vue';
import Printer from 'vue-material-design-icons/Printer.vue';
import Radar from 'vue-material-design-icons/Radar.vue';
import TransferRight from 'vue-material-design-icons/TransferRight.vue';
import Bus from 'vue-material-design-icons/Bus.vue';
import FileChart from 'vue-material-design-icons/FileChart.vue';
import MapMarkerRadius from 'vue-material-design-icons/MapMarkerRadius.vue';
import ChartLine from 'vue-material-design-icons/ChartLine.vue';
import Qrcode from 'vue-material-design-icons/Qrcode.vue';
import PhoneInTalk from 'vue-material-design-icons/PhoneInTalk.vue';
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue';
import Timer from 'vue-material-design-icons/Timer.vue';
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue';
import ShieldCheck from 'vue-material-design-icons/ShieldCheck.vue';

const { t, tm } = useI18n();
const { isDark } = useTheme();

const navItems = computed(() => [
  { href: '#fonctionnalites', label: t('presentation.nav.features') },
  { href: '#billetterie', label: t('presentation.nav.ticketing') },
  { href: '#correspondances', label: t('presentation.nav.connections') },
  { href: '#tiketi-control', label: t('presentation.nav.control') },
  { href: '#roles', label: t('presentation.nav.teams') },
  { href: '#fidelite', label: t('presentation.nav.loyalty') },
  { href: '#contact', label: t('presentation.nav.contact') },
]);

const features = computed(() => tm('presentation.features.items').map((item, index) => ({
  icon: [TicketConfirmation, Printer, Radar, TransferRight, Bus, FileChart][index],
  title: item.title,
  desc: item.desc,
})));

const useCaseImages = [
  '/images/help/help-seller-ticketing.png',
  '/images/help/help-control-tower.png',
  '/images/help/help-tids-board.png',
  '/images/help/help-accountant-reports.png',
];

const useCases = computed(() => tm('presentation.cases.items').map((item, index) => ({
  image: useCaseImages[index] || '',
  alt: item.title,
  tag: item.tag,
  title: item.title,
  points: item.points,
})));

const roles = computed(() => tm('presentation.roles.items').map((item, index) => ({
  name: item.name,
  desc: item.desc,
  icon: [TicketConfirmation, Radar, FileChart, ChartLine][index],
})));

const faqs = computed(() => tm('presentation.faq.items'));

// Sections à structure imbriquée (objets/tableaux) : accès via tm()
const connections = computed(() => tm('presentation.connections'));
const control = computed(() => tm('presentation.control'));
const loyalty = computed(() => tm('presentation.loyalty'));
const why = computed(() => tm('presentation.why'));

// Coordonnées de contact
const CONTACT_EMAIL = 'contact@tiketi.ci';
const WHATSAPP_NUMBER = '2250719160119';
const whatsappLink = computed(() => `https://wa.me/${WHATSAPP_NUMBER}?text=${encodeURIComponent(t('presentation.contact.wa_message'))}`);

const contactForm = ref({ company: '', email: '', phone: '', message: '' });

const submitContact = () => {
  router.post(route('contact.send'), {
    company: contactForm.value.company,
    email: contactForm.value.email,
    phone: contactForm.value.phone,
    message: contactForm.value.message,
  }, {
    preserveState: true,
    preserveScroll: true,
    onSuccess: () => {
      toastStore.success(t('presentation.contact.success'));
      contactForm.value = { company: '', email: '', phone: '', message: '' };
    },
    onError: () => {
      toastStore.error(t('presentation.contact.error'));
    },
  });
};
</script>

<template>
  <div class="min-h-screen bg-white text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    <!-- ============ EN-TÊTE ============ -->
    <header class="sticky top-0 z-50 border-b border-slate-200/70 bg-white/85 backdrop-blur dark:border-slate-800 dark:bg-slate-950/85">
      <nav class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8" aria-label="Navigation">
        <Link href="/" class="flex shrink-0 items-center gap-2">
          <img :src="isDark ? '/images/logo-white.png' : '/images/logo.png'" alt="TIKÊTI Logo" class="h-10 w-auto object-contain" />
        </Link>

        <div class="hidden items-center gap-7 lg:flex">
          <a v-for="item in navItems" :key="item.href" :href="item.href" class="text-sm font-semibold text-slate-600 transition hover:text-emerald-600 dark:text-slate-300 dark:hover:text-emerald-400">
            {{ item.label }}
          </a>
        </div>

        <div class="flex shrink-0 items-center gap-2">
          <LocaleSwitcher />
          <ThemeToggle />
          <a href="#contact" class="hidden rounded-full bg-emerald-600 px-5 py-2.5 text-sm font-black text-white shadow-md shadow-emerald-500/20 transition hover:bg-emerald-700 sm:block">
            {{ t('presentation.header.demo') }}
          </a>
          <Link v-if="$page.props.isTenant" :href="route('login')" class="hidden text-sm font-bold text-slate-500 transition hover:text-emerald-600 dark:text-slate-300 sm:block">
            {{ t('presentation.header.login') }}
          </Link>
        </div>
      </nav>
    </header>

    <!-- ============ HERO ============ -->
    <section class="relative overflow-hidden">
      <div class="pointer-events-none absolute inset-0">
        <div class="absolute -top-32 left-1/4 h-96 w-96 rounded-full bg-emerald-200/40 blur-3xl dark:bg-emerald-500/10"></div>
        <div class="absolute bottom-0 right-0 h-96 w-96 rounded-full bg-slate-200/50 blur-3xl dark:bg-slate-700/20"></div>
      </div>

      <div class="relative mx-auto max-w-7xl px-4 pb-16 pt-14 sm:px-6 lg:px-8 lg:pb-24 lg:pt-20">
        <div class="mx-auto max-w-3xl text-center">
          <span class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-4 py-1.5 text-xs font-black uppercase tracking-widest text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
            <MapMarkerRadius :size="14" />
            {{ t('presentation.hero.badge') }}
          </span>
          <h1 class="mt-6 text-3xl font-black leading-tight tracking-tight text-slate-950 dark:text-white sm:text-5xl lg:text-6xl">
            {{ t('presentation.hero.title_1') }}
            <span class="bg-gradient-to-r from-emerald-600 to-teal-500 bg-clip-text text-transparent">{{ t('presentation.hero.title_2') }}</span>
            {{ t('presentation.hero.title_3') }}
          </h1>
          <p class="mx-auto mt-6 max-w-2xl text-lg leading-8 text-slate-600 dark:text-slate-300">
            {{ t('presentation.hero.subtitle') }}
          </p>
          <div class="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row">
            <a href="#contact" class="w-full rounded-full bg-emerald-600 px-8 py-4 text-base font-black text-white shadow-xl shadow-emerald-500/25 transition hover:-translate-y-0.5 hover:bg-emerald-700 sm:w-auto">
              {{ t('presentation.hero.cta_demo') }}
            </a>
            <a href="#fonctionnalites" class="w-full rounded-full border border-slate-300 bg-white px-8 py-4 text-base font-black text-slate-700 transition hover:border-emerald-300 hover:bg-emerald-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 sm:w-auto">
              {{ t('presentation.hero.cta_features') }}
            </a>
          </div>
          <p class="mt-6 text-sm font-semibold text-slate-400 dark:text-slate-500">
            {{ t('presentation.hero.doc_hint') }} <Link :href="'/documentation'" class="text-emerald-600 underline-offset-2 hover:underline dark:text-emerald-400">{{ t('presentation.hero.doc_link') }}</Link>
          </p>
        </div>

        <!-- Visuel principal -->
        <div class="relative mx-auto mt-16 max-w-5xl">
          <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl shadow-slate-900/10 dark:border-slate-700 dark:bg-slate-900">
            <div class="flex items-center gap-1.5 border-b border-slate-100 bg-slate-50 px-4 py-3 dark:border-slate-800 dark:bg-slate-800">
              <span class="h-3 w-3 rounded-full bg-rose-300"></span>
              <span class="h-3 w-3 rounded-full bg-amber-300"></span>
              <span class="h-3 w-3 rounded-full bg-emerald-300"></span>
              <span class="ml-3 truncate text-xs font-bold text-slate-400">{{ t('presentation.hero.mockup_label') }}</span>
            </div>
            <img src="/images/help/help-seller-ticketing.png" alt="TIKETI — Billetterie" class="w-full" />
          </div>

          <!-- Badges flottants -->
          <div class="absolute -left-4 top-8 hidden rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-xl dark:border-slate-700 dark:bg-slate-900 lg:block">
            <div class="flex items-center gap-2">
              <span class="grid h-9 w-9 place-items-center rounded-xl bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                <TicketConfirmation :size="20" />
              </span>
              <div>
                <div class="text-sm font-black">{{ t('presentation.hero.badge_sale_title') }}</div>
                <div class="text-xs font-semibold text-slate-400">{{ t('presentation.hero.badge_sale_sub') }}</div>
              </div>
            </div>
          </div>
          <div class="absolute -right-4 bottom-10 hidden rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-xl dark:border-slate-700 dark:bg-slate-900 lg:block">
            <div class="flex items-center gap-2">
              <span class="grid h-9 w-9 place-items-center rounded-xl bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                <Qrcode :size="20" />
              </span>
              <div>
                <div class="text-sm font-black">{{ t('presentation.hero.badge_qr_title') }}</div>
                <div class="text-xs font-semibold text-slate-400">{{ t('presentation.hero.badge_qr_sub') }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ============ FONCTIONNALITÉS ============ -->
    <section id="fonctionnalites" class="border-t border-slate-100 bg-slate-50 py-20 dark:border-slate-800 dark:bg-slate-900/40">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-2xl text-center">
          <span class="text-xs font-black uppercase tracking-widest text-emerald-600 dark:text-emerald-400">{{ t('presentation.features.eyebrow') }}</span>
          <h2 class="mt-3 text-3xl font-black tracking-tight text-slate-950 dark:text-white sm:text-4xl">
            {{ t('presentation.features.title') }}
          </h2>
          <p class="mt-4 text-lg leading-8 text-slate-600 dark:text-slate-300">
            {{ t('presentation.features.subtitle') }}
          </p>
        </div>

        <div class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          <div v-for="feature in features" :key="feature.title" class="group rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:border-emerald-300 hover:shadow-lg dark:border-slate-800 dark:bg-slate-900">
            <span class="grid h-12 w-12 place-items-center rounded-xl bg-emerald-100 text-emerald-700 transition group-hover:scale-110 dark:bg-emerald-950/40 dark:text-emerald-300">
              <component :is="feature.icon" :size="26" />
            </span>
            <h3 class="mt-5 text-lg font-black text-slate-950 dark:text-white">{{ feature.title }}</h3>
            <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ feature.desc }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- ============ CAS D'USAGE ILLUSTRÉS ============ -->
    <section id="billetterie" class="py-20">
      <div class="mx-auto max-w-7xl space-y-20 px-4 sm:px-6 lg:px-8">
        <div v-for="(useCase, index) in useCases" :key="useCase.id" class="grid items-center gap-10 lg:grid-cols-2">
          <!-- Image -->
          <div :class="index % 2 === 1 ? 'lg:order-2' : ''">
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-900/5 dark:border-slate-700 dark:bg-slate-900">
              <div class="flex items-center gap-1.5 border-b border-slate-100 bg-slate-50 px-4 py-2.5 dark:border-slate-800 dark:bg-slate-800">
                <span class="h-2.5 w-2.5 rounded-full bg-rose-300"></span>
                <span class="h-2.5 w-2.5 rounded-full bg-amber-300"></span>
                <span class="h-2.5 w-2.5 rounded-full bg-emerald-300"></span>
              </div>
              <img :src="useCase.image" :alt="useCase.alt" class="w-full" />
            </div>
          </div>

          <!-- Texte -->
          <div :class="index % 2 === 1 ? 'lg:order-1' : ''">
            <span class="inline-flex rounded-full bg-emerald-50 px-3 py-1 text-xs font-black uppercase tracking-widest text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
              {{ useCase.tag }}
            </span>
            <h2 class="mt-4 text-3xl font-black tracking-tight text-slate-950 dark:text-white">
              {{ useCase.title }}
            </h2>
            <ul class="mt-6 space-y-3">
              <li v-for="point in useCase.points" :key="point" class="flex items-start gap-3">
                <CheckCircle :size="22" class="mt-0.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                <span class="text-base leading-7 text-slate-600 dark:text-slate-300">{{ point }}</span>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </section>

    <!-- ============ CORRESPONDANCES ============ -->
    <section id="correspondances" class="border-t border-slate-100 bg-slate-50 py-20 dark:border-slate-800 dark:bg-slate-900/40">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="grid items-center gap-12 lg:grid-cols-2">
          <div>
            <span class="inline-flex rounded-full bg-emerald-50 px-3 py-1 text-xs font-black uppercase tracking-widest text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
              {{ connections.eyebrow }}
            </span>
            <h2 class="mt-4 text-3xl font-black tracking-tight text-slate-950 dark:text-white sm:text-4xl">
              {{ connections.title }}
            </h2>
            <p class="mt-4 text-lg leading-8 text-slate-600 dark:text-slate-300">
              {{ connections.text }}
            </p>
            <dl class="mt-8 grid gap-4 sm:grid-cols-2">
              <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <dt class="flex items-center gap-2 font-black text-slate-900 dark:text-white">
                  <SwapHorizontal :size="18" class="text-emerald-600 dark:text-emerald-400" />
                  {{ connections.cards[0].title }}
                </dt>
                <dd class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ connections.cards[0].desc }}</dd>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <dt class="flex items-center gap-2 font-black text-slate-900 dark:text-white">
                  <ShieldCheck :size="18" class="text-emerald-600 dark:text-emerald-400" />
                  {{ connections.cards[1].title }}
                </dt>
                <dd class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ connections.cards[1].desc }}</dd>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <dt class="flex items-center gap-2 font-black text-slate-900 dark:text-white">
                  <CheckCircle :size="18" class="text-emerald-600 dark:text-emerald-400" />
                  {{ connections.cards[2].title }}
                </dt>
                <dd class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ connections.cards[2].desc }}</dd>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <dt class="flex items-center gap-2 font-black text-slate-900 dark:text-white">
                  <MapMarkerRadius :size="18" class="text-emerald-600 dark:text-emerald-400" />
                  {{ connections.cards[3].title }}
                </dt>
                <dd class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ connections.cards[3].desc }}</dd>
              </div>
            </dl>
          </div>

          <!-- Parcours passager -->
          <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-xl shadow-slate-900/5 dark:border-slate-800 dark:bg-slate-900 sm:p-8">
            <div class="flex items-center justify-between border-b border-slate-100 pb-5 dark:border-slate-800">
              <div>
                <p class="text-xs font-black uppercase tracking-widest text-emerald-600 dark:text-emerald-400">{{ connections.passenger_title }}</p>
                <p class="mt-1 font-black text-slate-900 dark:text-white">{{ connections.passenger_route }}</p>
              </div>
              <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-black text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">{{ connections.one_ticket }}</span>
            </div>
            <ol class="mt-7 space-y-3">
              <li class="flex gap-4 rounded-2xl bg-slate-50 p-4 dark:bg-slate-800">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-emerald-600 text-sm font-black text-white">1</span>
                <div>
                  <p class="font-black text-slate-900 dark:text-white">{{ connections.steps[0].title }}</p>
                  <p class="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ connections.steps[0].desc }}</p>
                </div>
              </li>
              <li class="ml-4 h-5 border-l-2 border-dashed border-emerald-300 dark:border-emerald-800"></li>
              <li class="flex gap-4 rounded-2xl bg-emerald-50 p-4 ring-2 ring-emerald-200 dark:bg-emerald-950/40 dark:ring-emerald-800">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-emerald-600 text-sm font-black text-white">2</span>
                <div>
                  <p class="font-black text-slate-900 dark:text-white">{{ connections.steps[1].title }}</p>
                  <p class="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ connections.steps[1].desc }}</p>
                </div>
              </li>
              <li class="ml-4 h-5 border-l-2 border-dashed border-emerald-300 dark:border-emerald-800"></li>
              <li class="flex gap-4 rounded-2xl bg-slate-50 p-4 dark:bg-slate-800">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-emerald-600 text-sm font-black text-white">3</span>
                <div>
                  <p class="font-black text-slate-900 dark:text-white">{{ connections.steps[2].title }}</p>
                  <p class="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ connections.steps[2].desc }}</p>
                </div>
              </li>
            </ol>
          </div>
        </div>
      </div>
    </section>

    <!-- ============ TIKETI CONTROL ============ -->
    <section id="tiketi-control" class="overflow-hidden bg-slate-950 py-20 text-white">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="grid items-center gap-12 lg:grid-cols-2">
          <!-- Mockup téléphone -->
          <div class="relative mx-auto w-full max-w-[260px]">
            <div class="absolute inset-8 rounded-full bg-emerald-500/20 blur-3xl"></div>
            <div class="relative rounded-[2.6rem] border-[10px] border-slate-800 bg-slate-50 p-4 shadow-2xl shadow-emerald-950/60">
              <div class="mx-auto mb-4 h-1.5 w-20 rounded-full bg-slate-300"></div>
              <div class="rounded-3xl bg-white p-4 text-slate-900 shadow-inner">
                <div class="flex items-center justify-between">
                  <div>
                    <p class="text-xs font-black uppercase tracking-wider text-emerald-600">{{ control.app_title }}</p>
                    <p class="mt-1 text-lg font-black">{{ control.app_subtitle }}</p>
                  </div>
                  <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-[10px] font-black text-emerald-700">{{ control.online }}</span>
                </div>
                <div class="mt-5 grid aspect-square place-items-center rounded-3xl bg-slate-950">
                  <div class="relative h-32 w-32 rounded-2xl border-2 border-emerald-400">
                    <span class="absolute -left-1 -top-1 h-8 w-8 border-l-4 border-t-4 border-white"></span>
                    <span class="absolute -right-1 -top-1 h-8 w-8 border-r-4 border-t-4 border-white"></span>
                    <span class="absolute -bottom-1 -left-1 h-8 w-8 border-b-4 border-l-4 border-white"></span>
                    <span class="absolute -bottom-1 -right-1 h-8 w-8 border-b-4 border-r-4 border-white"></span>
                    <span class="absolute left-3 right-3 top-1/2 h-0.5 bg-emerald-400 shadow-[0_0_16px_#34d399]"></span>
                  </div>
                </div>
                <div class="mt-4 rounded-2xl bg-emerald-50 p-4 ring-1 ring-emerald-100 dark:ring-emerald-800">
                  <div class="flex items-center gap-3">
                    <span class="grid h-9 w-9 place-items-center rounded-full bg-emerald-500 text-lg font-black text-white">✓</span>
                    <div>
                      <p class="font-black text-emerald-900">{{ control.valid_title }}</p>
                      <p class="text-xs text-emerald-700">{{ control.valid_seat }}</p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div>
            <span class="inline-flex items-center rounded-full bg-emerald-400/10 px-4 py-1.5 text-xs font-black uppercase tracking-widest text-emerald-300 ring-1 ring-emerald-400/20">
              {{ control.eyebrow }}
            </span>
            <h2 class="mt-6 text-3xl font-black tracking-tight sm:text-5xl">{{ control.title }}</h2>
            <p class="mt-6 max-w-2xl text-lg leading-8 text-slate-300">
              {{ control.text }}
            </p>
            <div class="mt-10 grid gap-5 sm:grid-cols-2">
              <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                <p class="font-black text-emerald-300">{{ control.cards[0].title }}</p>
                <p class="mt-2 text-sm leading-6 text-slate-300">{{ control.cards[0].desc }}</p>
              </div>
              <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                <p class="font-black text-emerald-300">{{ control.cards[1].title }}</p>
                <p class="mt-2 text-sm leading-6 text-slate-300">{{ control.cards[1].desc }}</p>
              </div>
              <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                <p class="font-black text-emerald-300">{{ control.cards[2].title }}</p>
                <p class="mt-2 text-sm leading-6 text-slate-300">{{ control.cards[2].desc }}</p>
              </div>
              <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                <p class="font-black text-emerald-300">{{ control.cards[3].title }}</p>
                <p class="mt-2 text-sm leading-6 text-slate-300">{{ control.cards[3].desc }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ============ RÔLES / ÉQUIPES ============ -->
    <section id="roles" class="border-t border-slate-100 bg-slate-50 py-20 dark:border-slate-800 dark:bg-slate-900/40">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-2xl text-center">
          <span class="text-xs font-black uppercase tracking-widest text-emerald-600 dark:text-emerald-400">{{ t('presentation.roles.eyebrow') }}</span>
          <h2 class="mt-3 text-3xl font-black tracking-tight text-slate-950 dark:text-white sm:text-4xl">
            {{ t('presentation.roles.title') }}
          </h2>
          <p class="mt-4 text-lg leading-8 text-slate-600 dark:text-slate-300">
            {{ t('presentation.roles.subtitle') }}
          </p>
        </div>

        <div class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
          <div v-for="role in roles" :key="role.name" class="rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <span class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
              <component :is="role.icon" :size="24" />
            </span>
            <h3 class="mt-4 text-base font-black text-slate-950 dark:text-white">{{ role.name }}</h3>
            <p class="mt-1.5 text-sm leading-6 text-slate-500 dark:text-slate-400">{{ role.desc }}</p>
          </div>
        </div>

        <!-- Fidélisation OKOHI -->
        <div id="fidelite" class="mt-16 overflow-hidden rounded-3xl border border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30">
          <div class="grid items-center gap-10 p-8 lg:grid-cols-2 lg:p-12">
            <div>
              <img src="/images/okohi-logo.png" alt="OKOHI Logo" class="h-14 w-auto" />
              <h3 class="mt-5 text-2xl font-black text-slate-950 dark:text-white sm:text-3xl">
                {{ loyalty.title }}
              </h3>
              <p class="mt-3 text-base leading-7 text-slate-600 dark:text-slate-300">
                {{ loyalty.text }}
              </p>
              <ul class="mt-6 space-y-4">
                <li class="flex items-start gap-3">
                  <Qrcode :size="22" class="mt-0.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                  <div>
                    <p class="font-black text-slate-900 dark:text-white">{{ loyalty.items[0].title }}</p>
                    <p class="text-sm leading-6 text-slate-600 dark:text-slate-300">{{ loyalty.items[0].desc }}</p>
                  </div>
                </li>
                <li class="flex items-start gap-3">
                  <CheckCircle :size="22" class="mt-0.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                  <div>
                    <p class="font-black text-slate-900 dark:text-white">{{ loyalty.items[1].title }}</p>
                    <p class="text-sm leading-6 text-slate-600 dark:text-slate-300">{{ loyalty.items[1].desc }}</p>
                  </div>
                </li>
                <li class="flex items-start gap-3">
                  <ChartLine :size="22" class="mt-0.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                  <div>
                    <p class="font-black text-slate-900 dark:text-white">{{ loyalty.items[2].title }}</p>
                    <p class="text-sm leading-6 text-slate-600 dark:text-slate-300">{{ loyalty.items[2].desc }}</p>
                  </div>
                </li>
              </ul>
              <a
                href="https://play.google.com/store/apps/details?id=com.anohou.okohi"
                target="_blank"
                rel="noopener noreferrer"
                class="mt-8 inline-flex items-center gap-3 rounded-2xl bg-slate-900 px-6 py-3 text-sm font-semibold text-white shadow-xl transition hover:scale-105 dark:bg-white dark:text-slate-900"
              >
                <svg class="h-6 w-6 fill-current" viewBox="0 0 24 24">
                  <path d="M5,3L13.5,12L5,21V3M17.66,10.12L20.33,11.5C21,11.84 21,12.16 20.33,12.5L17.66,13.88L14.73,12.3L17.66,10.12M13.5,12L14.73,12.3L4.66,21.64C4.47,21.89 4.23,22 4,22A1,1 0 0,1 3,21V3C3,2.77 3.08,2.54 3.23,2.36L13.5,12Z" />
                </svg>
                <span class="text-left">
                  <span class="block text-[10px] uppercase leading-none opacity-70">{{ loyalty.play_badge }}</span>
                  <span class="mt-1 block text-base leading-none">{{ loyalty.play_store }}</span>
                </span>
              </a>
            </div>

            <div class="relative flex items-center justify-center lg:justify-end">
              <div class="relative overflow-hidden rounded-3xl bg-emerald-50 p-8 shadow-2xl ring-1 ring-slate-900/10 dark:bg-emerald-900/20 dark:ring-white/10 lg:p-12">
                <img src="/images/loyalty-card.png" alt="Carte de fidélité OKOHI" class="w-[20rem] rounded-3xl shadow-2xl sm:w-[24rem]" />
                <div class="absolute right-8 top-8 flex items-center gap-2 rounded-full border border-white/20 bg-white/90 px-4 py-2 shadow-lg backdrop-blur-md dark:bg-slate-800/90">
                  <span class="flex h-2 w-2 rounded-full bg-emerald-500"></span>
                  <span class="text-xs font-bold text-slate-700 dark:text-slate-200">{{ loyalty.synced }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ============ POURQUOI TIKETI ============ -->
    <section class="bg-emerald-700 py-20 text-white">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-2xl text-center">
          <span class="text-xs font-black uppercase tracking-widest text-emerald-200">{{ why.eyebrow }}</span>
          <h2 class="mt-3 text-3xl font-black tracking-tight sm:text-4xl">{{ why.title }}</h2>
        </div>
        <dl class="mx-auto mt-14 grid max-w-5xl gap-8 lg:grid-cols-3">
          <div class="flex flex-col items-center text-center">
            <div class="rounded-full bg-white/10 p-4 ring-1 ring-white/20">
              <Timer :size="28" />
            </div>
            <dt class="mt-5 text-xl font-black">{{ why.items[0].title }}</dt>
            <dd class="mt-3 text-base leading-7 text-emerald-100">{{ why.items[0].desc }}</dd>
          </div>
          <div class="flex flex-col items-center text-center">
            <div class="rounded-full bg-white/10 p-4 ring-1 ring-white/20">
              <TrendingUp :size="28" />
            </div>
            <dt class="mt-5 text-xl font-black">{{ why.items[1].title }}</dt>
            <dd class="mt-3 text-base leading-7 text-emerald-100">{{ why.items[1].desc }}</dd>
          </div>
          <div class="flex flex-col items-center text-center">
            <div class="rounded-full bg-white/10 p-4 ring-1 ring-white/20">
              <ShieldCheck :size="28" />
            </div>
            <dt class="mt-5 text-xl font-black">{{ why.items[2].title }}</dt>
            <dd class="mt-3 text-base leading-7 text-emerald-100">{{ why.items[2].desc }}</dd>
          </div>
        </dl>
      </div>
    </section>

    <!-- ============ FAQ ============ -->
    <section class="py-20">
      <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
        <div class="text-center">
          <span class="text-xs font-black uppercase tracking-widest text-emerald-600 dark:text-emerald-400">{{ t('presentation.faq.eyebrow') }}</span>
          <h2 class="mt-3 text-3xl font-black tracking-tight text-slate-950 dark:text-white">{{ t('presentation.faq.title') }}</h2>
        </div>
        <div class="mt-10 space-y-3">
          <details v-for="faq in faqs" :key="faq.q" class="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm open:border-emerald-300 dark:border-slate-800 dark:bg-slate-900">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-base font-black text-slate-900 dark:text-white">
              {{ faq.q }}
              <ChevronDown :size="22" class="shrink-0 text-emerald-600 transition group-open:rotate-180" />
            </summary>
            <p class="mt-3 text-sm leading-7 text-slate-600 dark:text-slate-300">{{ faq.a }}</p>
          </details>
        </div>
      </div>
    </section>

    <!-- ============ CTA / CONTACT ============ -->
    <section id="contact" class="border-t border-slate-100 bg-slate-950 py-20 text-white dark:border-slate-800">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-2xl text-center">
          <h2 class="text-3xl font-black tracking-tight sm:text-4xl">{{ t('presentation.contact.title') }}</h2>
          <p class="mt-4 text-lg leading-8 text-slate-300">
            {{ t('presentation.contact.text') }}
          </p>
        </div>

        <div class="mx-auto mt-12 grid max-w-5xl gap-8 lg:grid-cols-3">
          <!-- Colonne 1/3 : coordonnées + actions -->
          <div class="space-y-5">
            <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
              <div class="text-xs font-bold uppercase tracking-widest text-slate-400">{{ t('presentation.contact.coordinates') }}</div>
              <div class="mt-4 space-y-3">
                <a
                  :href="`mailto:${CONTACT_EMAIL}`"
                  class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 p-3.5 transition hover:border-emerald-500/60 hover:bg-white/10"
                >
                  <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-emerald-500/15 text-emerald-300">
                    <svg class="h-5 w-5 fill-current" viewBox="0 0 24 24"><path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4-8 5-8-5V6l8 5 8-5v2z"/></svg>
                  </span>
                  <span class="min-w-0">
                    <span class="block text-[10px] font-bold uppercase tracking-widest text-slate-400">{{ t('presentation.contact.email_label') }}</span>
                    <span class="mt-0.5 block truncate font-black text-white">{{ t('presentation.contact.email_value') }}</span>
                  </span>
                </a>
                <a
                  :href="whatsappLink"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 p-3.5 transition hover:border-emerald-500/60 hover:bg-white/10"
                >
                  <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-emerald-500/15 text-emerald-300">
                    <PhoneInTalk :size="20" />
                  </span>
                  <span class="min-w-0">
                    <span class="block text-[10px] font-bold uppercase tracking-widest text-slate-400">{{ t('presentation.contact.whatsapp_label') }}</span>
                    <span class="mt-0.5 block truncate font-black text-white">{{ t('presentation.contact.whatsapp_value') }}</span>
                  </span>
                </a>
              </div>
            </div>

            <div class="flex flex-col gap-3">
              <a
                :href="whatsappLink"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center justify-center gap-2 rounded-full bg-emerald-600 px-6 py-3.5 text-sm font-black text-white shadow-lg shadow-emerald-500/25 transition hover:bg-emerald-700"
              >
                <PhoneInTalk :size="19" />
                {{ t('presentation.contact.cta_whatsapp') }}
              </a>
              <Link
                :href="'/documentation'"
                class="inline-flex items-center justify-center gap-2 rounded-full border border-slate-600 px-6 py-3.5 text-sm font-black text-slate-200 transition hover:border-emerald-500 hover:text-emerald-400"
              >
                {{ t('presentation.contact.cta_doc') }}
              </Link>
            </div>
          </div>

          <!-- Colonne 2/3 : formulaire -->
          <form
            id="contact-form"
            class="rounded-2xl border border-white/10 bg-white/5 p-6 sm:p-8 lg:col-span-2"
            @submit.prevent="submitContact"
          >
            <div class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
              <div class="sm:col-span-2">
                <label for="company" class="block text-sm font-bold text-slate-200">{{ t('presentation.contact.company') }}</label>
                <input id="company" v-model="contactForm.company" type="text" :placeholder="t('presentation.contact.company_ph')" class="mt-2 w-full rounded-xl border-0 bg-white/10 px-4 py-3 text-white placeholder:text-slate-400 focus:ring-2 focus:ring-emerald-500" />
              </div>
              <div>
                <label for="email" class="block text-sm font-bold text-slate-200">{{ t('presentation.contact.email') }}</label>
                <input id="email" v-model="contactForm.email" type="email" :placeholder="t('presentation.contact.email_ph')" class="mt-2 w-full rounded-xl border-0 bg-white/10 px-4 py-3 text-white placeholder:text-slate-400 focus:ring-2 focus:ring-emerald-500" />
              </div>
              <div>
                <label for="phone" class="block text-sm font-bold text-slate-200">{{ t('presentation.contact.phone') }}</label>
                <input id="phone" v-model="contactForm.phone" type="tel" :placeholder="t('presentation.contact.phone_ph')" class="mt-2 w-full rounded-xl border-0 bg-white/10 px-4 py-3 text-white placeholder:text-slate-400 focus:ring-2 focus:ring-emerald-500" />
              </div>
              <div class="sm:col-span-2">
                <label for="message" class="block text-sm font-bold text-slate-200">{{ t('presentation.contact.message') }}</label>
                <textarea id="message" v-model="contactForm.message" rows="5" :placeholder="t('presentation.contact.message_ph')" class="mt-2 w-full rounded-xl border-0 bg-white/10 px-4 py-3 text-white placeholder:text-slate-400 focus:ring-2 focus:ring-emerald-500"></textarea>
              </div>
            </div>
            <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
              <button
                type="submit"
                class="inline-flex items-center justify-center gap-2 rounded-full bg-emerald-600 px-8 py-3.5 text-sm font-black text-white shadow-lg shadow-emerald-500/25 transition hover:bg-emerald-700"
              >
                <svg class="h-5 w-5 fill-current" viewBox="0 0 24 24"><path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4-8 5-8-5V6l8 5 8-5v2z"/></svg>
                {{ t('presentation.contact.send') }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </section>

    <!-- ============ FOOTER ============ -->
    <footer class="border-t border-slate-800 bg-slate-950 py-10 text-slate-400">
      <div class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-6 px-4 sm:px-6 lg:flex-row lg:px-8">
        <div class="flex items-center gap-3">
          <img src="/images/logo-white.png" alt="TIKÊTI Logo" class="h-10 w-auto object-contain" />
          <span class="text-sm font-bold text-slate-300">{{ t('presentation.footer.tagline') }}</span>
        </div>
        <div class="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-sm font-semibold">
          <a href="#fonctionnalites" class="transition hover:text-emerald-400">{{ t('presentation.footer.features') }}</a>
          <Link :href="'/documentation'" class="transition hover:text-emerald-400">{{ t('presentation.footer.doc') }}</Link>
          <a href="#contact" class="transition hover:text-emerald-400">{{ t('presentation.footer.contact') }}</a>
        </div>
        <p class="text-xs text-slate-500">&copy; {{ new Date().getFullYear() }} TIKETI. {{ t('presentation.footer.rights') }}</p>
      </div>
    </footer>
  </div>
</template>
