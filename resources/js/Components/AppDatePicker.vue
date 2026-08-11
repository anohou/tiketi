<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useAttrs, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import CalendarMonth from 'vue-material-design-icons/CalendarMonth.vue';
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue';
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
  modelValue: { type: String, default: '' },
  min: { type: String, default: '' },
  max: { type: String, default: '' },
  placeholder: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
  required: { type: Boolean, default: false },
  clearable: { type: Boolean, default: true },
  id: { type: String, default: undefined },
  allowedDates: { type: Array, default: null },
});

const emit = defineEmits(['update:modelValue', 'change']);
const attrs = useAttrs();
const controlAttrs = () => {
  const { class: _class, ...rest } = attrs;
  return rest;
};
const { locale } = useI18n();
const trigger = ref(null);
const panel = ref(null);
const open = ref(false);
const panelStyle = ref({});

const parseIsoDate = (value) => {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return null;
  const [year, month, day] = value.split('-').map(Number);
  const date = new Date(year, month - 1, day);
  return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day
    ? date
    : null;
};

const toIsoDate = (date) => [
  date.getFullYear(),
  String(date.getMonth() + 1).padStart(2, '0'),
  String(date.getDate()).padStart(2, '0'),
].join('-');

const today = () => {
  const value = new Date();
  return new Date(value.getFullYear(), value.getMonth(), value.getDate());
};

const initialView = () => {
  const selected = parseIsoDate(props.modelValue);
  const minimum = parseIsoDate(props.min);
  const maximum = parseIsoDate(props.max);
  const base = selected || today();

  if (minimum && base < minimum) return new Date(minimum.getFullYear(), minimum.getMonth(), 1);
  if (maximum && base > maximum) return new Date(maximum.getFullYear(), maximum.getMonth(), 1);

  return new Date(base.getFullYear(), base.getMonth(), 1);
};

const viewDate = ref(initialView());
const selectedDate = computed(() => parseIsoDate(props.modelValue));
const localeCode = computed(() => locale.value?.startsWith('en') ? 'en-US' : 'fr-FR');
const labels = computed(() => localeCode.value === 'en-US'
  ? { choose: 'Choose a date', today: 'Today', clear: 'Clear', previous: 'Previous month', next: 'Next month' }
  : { choose: 'Choisir une date', today: 'Aujourd’hui', clear: 'Effacer', previous: 'Mois précédent', next: 'Mois suivant' });
const displayValue = computed(() => selectedDate.value
  ? new Intl.DateTimeFormat(localeCode.value, { day: '2-digit', month: 'long', year: 'numeric' }).format(selectedDate.value)
  : '');
const monthNames = computed(() => Array.from({ length: 12 }, (_, month) =>
  new Intl.DateTimeFormat(localeCode.value, { month: 'long' }).format(new Date(2024, month, 1))));
const weekdayNames = computed(() => Array.from({ length: 7 }, (_, index) =>
  new Intl.DateTimeFormat(localeCode.value, { weekday: 'short' }).format(new Date(2024, 0, 1 + index)).replace('.', '')));
const allowedDateSet = computed(() => props.allowedDates ? new Set(props.allowedDates) : null);

const minDate = computed(() => parseIsoDate(props.min));
const maxDate = computed(() => parseIsoDate(props.max));
const minYear = computed(() => minDate.value?.getFullYear() ?? today().getFullYear() - 100);
const maxYear = computed(() => maxDate.value?.getFullYear() ?? today().getFullYear() + 20);
const years = computed(() => Array.from({ length: maxYear.value - minYear.value + 1 }, (_, index) => minYear.value + index));

const isDisabledDate = (date) => {
  if (minDate.value && date < minDate.value) return true;
  if (maxDate.value && date > maxDate.value) return true;
  return allowedDateSet.value ? !allowedDateSet.value.has(toIsoDate(date)) : false;
};

const calendarDays = computed(() => {
  const year = viewDate.value.getFullYear();
  const month = viewDate.value.getMonth();
  const firstWeekday = (new Date(year, month, 1).getDay() + 6) % 7;
  const start = new Date(year, month, 1 - firstWeekday);
  const currentToday = today();

  return Array.from({ length: 42 }, (_, index) => {
    const date = new Date(start.getFullYear(), start.getMonth(), start.getDate() + index);
    const iso = toIsoDate(date);
    return {
      date,
      iso,
      day: date.getDate(),
      currentMonth: date.getMonth() === month,
      disabled: isDisabledDate(date),
      selected: props.modelValue === iso,
      today: toIsoDate(currentToday) === iso,
    };
  });
});

const canGoPrevious = computed(() => !minDate.value
  || new Date(viewDate.value.getFullYear(), viewDate.value.getMonth(), 0) >= minDate.value);
const canGoNext = computed(() => !maxDate.value
  || new Date(viewDate.value.getFullYear(), viewDate.value.getMonth() + 1, 1) <= maxDate.value);

const updatePosition = () => {
  if (!trigger.value || typeof window === 'undefined') return;
  const rect = trigger.value.getBoundingClientRect();
  const width = Math.min(336, window.innerWidth - 16);
  const estimatedHeight = 410;
  const left = Math.min(Math.max(8, rect.left), window.innerWidth - width - 8);
  const below = rect.bottom + 8;
  const top = below + estimatedHeight <= window.innerHeight
    ? below
    : Math.max(8, rect.top - estimatedHeight - 8);

  panelStyle.value = { left: `${left}px`, top: `${top}px`, width: `${width}px` };
};

const show = async () => {
  if (props.disabled) return;
  viewDate.value = initialView();
  open.value = true;
  await nextTick();
  updatePosition();
};

const hide = () => { open.value = false; };
const toggle = () => open.value ? hide() : show();

const selectDate = (day) => {
  if (day.disabled) return;
  emit('update:modelValue', day.iso);
  emit('change', day.iso);
  hide();
};

const clear = () => {
  emit('update:modelValue', '');
  emit('change', '');
  hide();
};

const selectToday = () => {
  const date = today();
  if (isDisabledDate(date)) return;
  selectDate({ iso: toIsoDate(date), disabled: false });
};

const changeMonth = (delta) => {
  viewDate.value = new Date(viewDate.value.getFullYear(), viewDate.value.getMonth() + delta, 1);
};

const clampViewDate = (date) => {
  const monthStart = new Date(date.getFullYear(), date.getMonth(), 1);
  const monthEnd = new Date(date.getFullYear(), date.getMonth() + 1, 0);
  if (minDate.value && monthEnd < minDate.value) {
    return new Date(minDate.value.getFullYear(), minDate.value.getMonth(), 1);
  }
  if (maxDate.value && monthStart > maxDate.value) {
    return new Date(maxDate.value.getFullYear(), maxDate.value.getMonth(), 1);
  }
  return monthStart;
};

const setMonth = (event) => {
  viewDate.value = clampViewDate(new Date(viewDate.value.getFullYear(), Number(event.target.value), 1));
};

const setYear = (event) => {
  viewDate.value = clampViewDate(new Date(Number(event.target.value), viewDate.value.getMonth(), 1));
};

const handlePointerDown = (event) => {
  if (!open.value || trigger.value?.contains(event.target) || panel.value?.contains(event.target)) return;
  hide();
};

const handleKeydown = (event) => {
  if (event.key === 'Escape') hide();
};

watch(() => props.modelValue, (value) => {
  const selected = parseIsoDate(value);
  if (selected) viewDate.value = new Date(selected.getFullYear(), selected.getMonth(), 1);
});

onMounted(() => {
  document.addEventListener('pointerdown', handlePointerDown);
  document.addEventListener('keydown', handleKeydown);
  window.addEventListener('resize', updatePosition);
  document.addEventListener('scroll', hide, true);
});

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', handlePointerDown);
  document.removeEventListener('keydown', handleKeydown);
  window.removeEventListener('resize', updatePosition);
  document.removeEventListener('scroll', hide, true);
});
</script>

<template>
  <div class="relative w-full" :class="attrs.class">
    <button
      :id="id"
      ref="trigger"
      type="button"
      :disabled="disabled"
      :aria-expanded="open"
      :aria-required="required"
      aria-haspopup="dialog"
      v-bind="controlAttrs()"
      class="flex min-h-10 w-full items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 text-left text-sm text-slate-900 shadow-sm outline-none transition hover:border-slate-300 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:border-slate-600 dark:focus:border-emerald-400 dark:focus:ring-emerald-400/10"
      @click="toggle"
    >
      <span :class="displayValue ? 'font-semibold' : 'text-slate-400 dark:text-slate-500'">
        {{ displayValue || placeholder || labels.choose }}
      </span>
      <CalendarMonth :size="20" class="shrink-0 text-slate-500 dark:text-slate-400" />
    </button>

    <Teleport to="body">
      <Transition enter-active-class="transition duration-150 ease-out" enter-from-class="translate-y-1 scale-95 opacity-0" enter-to-class="translate-y-0 scale-100 opacity-100" leave-active-class="transition duration-100 ease-in" leave-from-class="opacity-100" leave-to-class="opacity-0">
        <div
          v-if="open"
          ref="panel"
          role="dialog"
          aria-modal="false"
          :style="panelStyle"
          class="fixed z-[10000] rounded-2xl border border-slate-200 bg-white p-4 shadow-2xl shadow-slate-900/20 dark:border-slate-700 dark:bg-slate-900"
        >
          <div class="flex items-center gap-2">
            <button type="button" :aria-label="labels.previous" :disabled="!canGoPrevious" class="rounded-lg p-2 text-slate-600 transition hover:bg-slate-100 disabled:opacity-30 dark:text-slate-300 dark:hover:bg-slate-800" @click="changeMonth(-1)">
              <ChevronLeft :size="20" />
            </button>
            <div class="grid flex-1 grid-cols-[1fr_auto] gap-2">
              <select :value="viewDate.getMonth()" class="rounded-lg border-slate-200 bg-slate-50 py-1.5 pl-2 pr-7 text-sm font-bold capitalize text-slate-800 focus:border-emerald-500 focus:ring-emerald-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" @change="setMonth">
                <option v-for="(month, index) in monthNames" :key="month" :value="index">{{ month }}</option>
              </select>
              <select :value="viewDate.getFullYear()" class="rounded-lg border-slate-200 bg-slate-50 py-1.5 pl-2 pr-7 text-sm font-bold text-slate-800 focus:border-emerald-500 focus:ring-emerald-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" @change="setYear">
                <option v-for="year in years" :key="year" :value="year">{{ year }}</option>
              </select>
            </div>
            <button type="button" :aria-label="labels.next" :disabled="!canGoNext" class="rounded-lg p-2 text-slate-600 transition hover:bg-slate-100 disabled:opacity-30 dark:text-slate-300 dark:hover:bg-slate-800" @click="changeMonth(1)">
              <ChevronRight :size="20" />
            </button>
          </div>

          <div class="mt-4 grid grid-cols-7 gap-1 text-center">
            <div v-for="weekday in weekdayNames" :key="weekday" class="py-1 text-[10px] font-black uppercase tracking-wide text-slate-400">{{ weekday }}</div>
            <button
              v-for="day in calendarDays"
              :key="day.iso"
              type="button"
              :disabled="day.disabled"
              class="relative flex aspect-square items-center justify-center rounded-xl text-sm font-semibold transition"
              :class="[
                day.selected ? 'bg-emerald-600 text-white shadow-md shadow-emerald-600/25' : '',
                !day.selected && day.currentMonth ? 'text-slate-800 hover:bg-emerald-50 hover:text-emerald-700 dark:text-slate-100 dark:hover:bg-emerald-950/50 dark:hover:text-emerald-300' : '',
                !day.selected && !day.currentMonth ? 'text-slate-300 hover:bg-slate-50 dark:text-slate-600 dark:hover:bg-slate-800' : '',
                day.disabled ? 'cursor-not-allowed opacity-25' : '',
              ]"
              @click="selectDate(day)"
            >
              {{ day.day }}
              <span v-if="day.today && !day.selected" class="absolute bottom-1 h-1 w-1 rounded-full bg-emerald-500"></span>
            </button>
          </div>

          <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-3 dark:border-slate-800">
            <button v-if="clearable" type="button" class="rounded-lg px-3 py-1.5 text-xs font-bold text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800" @click="clear">{{ labels.clear }}</button>
            <span v-else></span>
            <button type="button" :disabled="isDisabledDate(today())" class="rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-700 hover:bg-emerald-100 disabled:opacity-40 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-950/70" @click="selectToday">{{ labels.today }}</button>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
