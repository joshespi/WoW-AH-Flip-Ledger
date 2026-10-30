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
            // Self-hosted variable fonts (@fontsource, bundled by Vite): nothing third-party at runtime.
            fontFamily: {
                sans: ['"Inter Variable"', ...defaultTheme.fontFamily.sans],
                mono: ['"JetBrains Mono Variable"', ...defaultTheme.fontFamily.mono],
            },

            // The whole app is written against `gray` and `indigo`; remapping them reskins every view.
            // gray: cool ink (900 is the logo tile, #1c1d22). indigo: auction-house gold, the one accent.
            colors: {
                gray: {
                    50: '#f6f6f8',
                    100: '#eeeff2',
                    200: '#dcdee4',
                    300: '#c3c6cf',
                    400: '#9a9eab',
                    500: '#737886',
                    600: '#555a68',
                    700: '#3b3f4b',
                    800: '#26282f',
                    900: '#1c1d22',
                    950: '#121317',
                },
                indigo: {
                    50: '#fdf8e8',
                    100: '#faedc2',
                    200: '#f5dc8a',
                    300: '#efc753',
                    400: '#e6b02c',
                    500: '#d4961a',
                    600: '#b97a12',
                    700: '#965d12',
                    800: '#7a4a15',
                    900: '#653d16',
                },
            },

            boxShadow: {
                card: '0 1px 0 0 rgb(255 255 255 / 0.03) inset, 0 1px 2px 0 rgb(0 0 0 / 0.25)',
            },
        },
    },

    plugins: [forms],
};
