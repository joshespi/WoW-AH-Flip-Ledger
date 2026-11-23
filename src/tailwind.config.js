import forms from '@tailwindcss/forms';
import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    // Class-based: the theme toggle (light / dark) sets `dark` on <html>.
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            screens: { xs: '480px' },
            // Self-hosted variable fonts (@fontsource, bundled by Vite): nothing third-party at runtime.
            fontFamily: {
                sans: ['"Inter Variable"', ...defaultTheme.fontFamily.sans],
                mono: ['"JetBrains Mono Variable"', ...defaultTheme.fontFamily.mono],
            },

            // The whole app is written against `gray` and `indigo`; remapping them reskins every view.
            // Keep the existing utility names for Blade views. Semantic roles live in app.css.
            colors: {
                gray: {
                    50: '#f2f0e8',
                    100: '#eae8e1',
                    200: '#dbdee4',
                    300: '#c4cbd4',
                    400: '#a5adb9',
                    500: '#667080',
                    600: '#535d6d',
                    700: '#3b4555',
                    800: '#2b3442',
                    900: '#1b2029',
                    950: '#0d1015',
                },
                indigo: {
                    50: '#fdf8e8',
                    100: '#faedc2',
                    200: '#f5dc8a',
                    300: '#ffd987',
                    400: '#e9b64e',
                    500: '#d4961a',
                    600: '#b97a12',
                    700: '#965d12',
                    800: '#7a4a15',
                    900: '#653d16',
                    950: '#3d240c',
                },
                amber: { 600: '#80530f' },
                green: { 400: '#4fd3a0', 600: '#06734f' },
                red: { 400: '#f07878', 600: '#b42536' },
            },

            boxShadow: {
                card: '0 1px 0 0 rgb(255 255 255 / 0.03) inset, 0 1px 2px 0 rgb(0 0 0 / 0.25)',
            },
        },
    },

    plugins: [forms],
};
