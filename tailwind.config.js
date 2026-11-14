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
            /**
             * Sistema de diseño «Tabler/UI» (artefacto de diseño del proyecto): paneles densos,
             * tipografía del sistema, neutros sin tinte y un único acento azul.
             * Valores tomados de sus tokens OKLCH (docs/DESIGN.md).
             */
            fontFamily: {
                sans: ['system-ui', '-apple-system', 'BlinkMacSystemFont', '"San Francisco"', '"Segoe UI"', 'Roboto', '"Helvetica Neue"', 'sans-serif'],
                mono: ['ui-monospace', 'SFMono-Regular', 'Menlo', 'Monaco', 'Consolas', '"Liberation Mono"', '"Courier New"', 'monospace'],
            },
            fontSize: {
                '2xs': ['0.625rem', { lineHeight: '1rem' }],
            },
            colors: {
                canvas: '#fafafa',
                /** Neutros (gray-50…950 del sistema); `snow` y `slate` son alias para no tocar las vistas. */
                snow: { 25: '#ffffff', 50: '#fafafa', 100: '#f5f5f5', 200: '#e5e5e5', 300: '#d4d4d4', 400: '#a3a3a3', 500: '#737373', 600: '#525252', 700: '#404040', 800: '#262626', 900: '#171717', 950: '#0a0a0a' },
                slate: { 50: '#fafafa', 100: '#f5f5f5', 200: '#e5e5e5', 300: '#d4d4d4', 400: '#a3a3a3', 500: '#737373', 600: '#525252', 700: '#404040', 800: '#262626', 900: '#171717', 950: '#0a0a0a' },
                /** `blue`, el único acento de marca. */
                primary: { 25: '#f5fbff', 50: '#e9f5ff', 100: '#d1eaff', 200: '#b1dbff', 300: '#76b0f7', 400: '#4996f0', 500: '#2b82e3', 600: '#066fd1', 700: '#0057b2', 800: '#00428b', 900: '#002e61' },
                /** Estado: éxito, peligro, advertencia, información; `purple` para etiquetas. */
                emerald: { 25: '#f6fcf6', 50: '#e9f9e9', 100: '#d1f3d1', 200: '#b0e9b2', 300: '#87d88c', 400: '#6bd875', 500: '#4dc65b', 600: '#2fb344', 700: '#119830', 800: '#147c28', 900: '#1d6026' },
                green: { 25: '#f6fcf6', 50: '#e9f9e9', 100: '#d1f3d1', 200: '#b0e9b2', 300: '#87d88c', 400: '#6bd875', 500: '#4dc65b', 600: '#2fb344', 700: '#119830', 800: '#147c28', 900: '#1d6026' },
                rose: { 25: '#fff7f6', 50: '#ffedea', 100: '#ffdad4', 200: '#ffbfb7', 300: '#ff948b', 400: '#f96c64', 500: '#e9524d', 600: '#d63939', 700: '#b62025', 800: '#90171b', 900: '#681516' },
                red: { 25: '#fff7f6', 50: '#ffedea', 100: '#ffdad4', 200: '#ffbfb7', 300: '#ff948b', 400: '#f96c64', 500: '#e9524d', 600: '#d63939', 700: '#b62025', 800: '#90171b', 900: '#681516' },
                amber: { 25: '#fff9f3', 50: '#fef1e3', 100: '#fee3c5', 200: '#fbcf9b', 300: '#fcd681', 400: '#fbc34e', 500: '#ffb339', 600: '#f59f00', 700: '#d78700', 800: '#b47300', 900: '#90611f' },
                yellow: { 25: '#fff9f3', 50: '#fef1e3', 100: '#fee3c5', 200: '#fbcf9b', 300: '#fcd681', 400: '#fbc34e', 500: '#ffb339', 600: '#f59f00', 700: '#d78700', 800: '#b47300', 900: '#90611f' },
                sky: { 25: '#f6fbff', 50: '#eaf5ff', 100: '#d3ebff', 200: '#b4ddff', 300: '#8ec7fb', 400: '#74bfff', 500: '#59acf3', 600: '#4299e1', 700: '#2d80c3', 800: '#25689e', 900: '#245178' },
                violet: { 25: '#fef7ff', 50: '#fdedff', 100: '#fcdbff', 200: '#f7c0ff', 300: '#e195f4', 400: '#d16eea', 500: '#c055db', 600: '#ae3ec9', 700: '#9226ab', 800: '#731c87', 900: '#531761' },
                hope: {
                    teal: '#0ca678',
                    cyan: '#17a2b8',
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
            /** shadow-xs / shadow-sm / shadow / shadow-lg / shadow-xl del sistema */
            boxShadow: {
                snow: '0 1px 2px 0 rgba(18,18,23,0.05)',
                'snow-md': '0 2px 4px -1px rgba(18,18,23,0.06), 0 4px 6px -1px rgba(18,18,23,0.08)',
                'snow-lg': '0 4px 6px -2px rgba(18,18,23,0.05), 0 10px 15px -3px rgba(18,18,23,0.08)',
                hope: '0 1px 3px 0 rgba(18,18,23,0.1), 0 1px 2px 0 rgba(18,18,23,0.06)',
                'hope-card': '0 1px 3px 0 rgba(18,18,23,0.1), 0 1px 2px 0 rgba(18,18,23,0.06)',
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
