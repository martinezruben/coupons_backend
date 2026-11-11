import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './app/Http/Controllers/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Public Sans', 'Figtree', ...defaultTheme.fontFamily.sans],
                mono: ['IBM Plex Mono', ...defaultTheme.fontFamily.mono],
            },
            fontSize: {
                '2xs': ['0.6875rem', { lineHeight: '1rem' }],
            },
            colors: {
                /**
                 * Sistema de diseño "Backend Admin" (Claude Design):
                 * fondo neutro cálido-frío, una sola tinta de acento,
                 * el color se reserva para estado/acción, no para el cromo.
                 */
                canvas: '#f2f3f6',
                snow: {
                    25: '#fbfcfe',
                    50: '#f6f7f9',
                    100: '#f2f3f6',
                    200: '#dcdee2',
                    300: '#cbced3',
                    400: '#9498a2',
                    500: '#6d727b',
                    600: '#505560',
                    700: '#2e333d',
                    800: '#1b1f29',
                    900: '#0e1218',
                },
                slate: {
                    50: '#f6f7f9',
                    100: '#f2f3f6',
                    200: '#dcdee2',
                    300: '#cbced3',
                    400: '#9498a2',
                    500: '#6d727b',
                    600: '#505560',
                    700: '#2e333d',
                    800: '#1b1f29',
                    900: '#0e1218',
                },
                primary: {
                    25: '#f2f7ff',
                    50: '#eaf3ff',
                    100: '#d7e6fc',
                    200: '#bdd3f2',
                    300: '#9ab9e8',
                    400: '#719ad6',
                    500: '#4b7bc0',
                    600: '#3a69ad',
                    700: '#1e4b8d',
                    800: '#15396d',
                    900: '#072754',
                },
                /** Acento secundario (botones alternativos, charts) */
                hope: {
                    teal: '#0d9488',
                    cyan: '#06b6d4',
                },
            },
            keyframes: {
                actIn: {
                    '0%': { opacity: '0', transform: 'translateY(6px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
            },
            animation: {
                'act-in': 'actIn 0.38s ease-out both',
            },
            boxShadow: {
                snow: '0 1px 2px 0 rgb(15 23 42 / 0.04), 0 1px 3px 0 rgb(15 23 42 / 0.06)',
                'snow-md': '0 4px 6px -1px rgb(15 23 42 / 0.06), 0 2px 4px -2px rgb(15 23 42 / 0.05)',
                'snow-lg': '0 10px 15px -3px rgb(15 23 42 / 0.06), 0 4px 6px -4px rgb(15 23 42 / 0.05)',
                hope: '0 1px 3px 0 rgb(37 99 235 / 0.08), 0 4px 12px -2px rgb(15 23 42 / 0.06)',
                'hope-card': '0 1px 2px 0 rgb(15 23 42 / 0.04), 0 8px 24px -4px rgb(15 23 42 / 0.08)',
            },
            scale: {
                '125': '1.25',
                '150': '1.5',
            },
        },
    },

    plugins: [
        forms({
            strategy: 'class',
        }),
    ],
};
