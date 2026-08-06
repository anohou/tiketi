import { createI18n } from 'vue-i18n';

const frModules = import.meta.glob('./locales/fr/*.json', { eager: true });
const enModules = import.meta.glob('./locales/en/*.json', { eager: true });

const mergeMessages = (modules) => Object.values(modules)
    .map((module) => module.default)
    .reduce((acc, messages) => ({ ...acc, ...messages }), {});

export const SUPPORTED_LOCALES = ['fr', 'en'];

export const i18n = createI18n({
    legacy: false,
    globalInjection: true,
    locale: 'fr',
    fallbackLocale: 'en',
    messages: {
        fr: mergeMessages(frModules),
        en: mergeMessages(enModules),
    },
});

export const setLocale = (locale) => {
    const normalized = SUPPORTED_LOCALES.includes(locale) ? locale : 'fr';
    i18n.global.locale.value = normalized;
    if (typeof document !== 'undefined') {
        document.documentElement.lang = normalized;
    }
    return normalized;
};
