import { computed, ref } from 'vue';

const STORAGE_KEY = 'tiketi-theme';

const theme = ref('light');
let initialized = false;

const applyTheme = (value) => {
    if (typeof document === 'undefined') {
        return;
    }

    const isDark = value === 'dark';

    document.documentElement.classList.toggle('dark', isDark);
    document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
};

export const initializeTheme = () => {
    if (initialized || typeof window === 'undefined') {
        return theme.value;
    }

    initialized = true;

    const storedTheme = window.localStorage.getItem(STORAGE_KEY);
    const preferredTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';

    theme.value = storedTheme === 'dark' || storedTheme === 'light'
        ? storedTheme
        : preferredTheme;

    applyTheme(theme.value);

    return theme.value;
};

export const setTheme = (value) => {
    theme.value = value === 'dark' ? 'dark' : 'light';

    if (typeof window !== 'undefined') {
        window.localStorage.setItem(STORAGE_KEY, theme.value);
    }

    applyTheme(theme.value);
};

export const toggleTheme = () => {
    setTheme(theme.value === 'dark' ? 'light' : 'dark');
};

export const useTheme = () => {
    initializeTheme();

    return {
        theme: computed(() => theme.value),
        isDark: computed(() => theme.value === 'dark'),
        initializeTheme,
        setTheme,
        toggleTheme,
    };
};
