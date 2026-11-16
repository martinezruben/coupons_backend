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
                sans: ['Figtree', 'system-ui', '-apple-system', '"Segoe UI"', 'Roboto', 'sans-serif'],
                display: ['"Bricolage Grotesque"', 'Figtree', 'system-ui', 'sans-serif'],
                mono: ['"JetBrains Mono"', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'Consolas', 'monospace'],
            },
            fontSize: {
                '2xs': ['0.625rem', { lineHeight: '1rem' }],
            },
            colors: {
                canvas: '#F6F7F2',
                /** Neutros (gray-50…950 del sistema); `snow` y `slate` son alias para no tocar las vistas. */
                snow: { 25: '#ffffff', 50: '#F6F7F2', 100: '#ECEFE8', 200: '#E2E6DC', 300: '#CBD3C6', 400: '#8E9B93', 500: '#5B6B63', 600: '#44544C', 700: '#2D4038', 800: '#1B2F27', 900: '#10241E', 950: '#0A1914' },
                slate: { 25: '#ffffff', 50: '#F6F7F2', 100: '#ECEFE8', 200: '#E2E6DC', 300: '#CBD3C6', 400: '#8E9B93', 500: '#5B6B63', 600: '#44544C', 700: '#2D4038', 800: '#1B2F27', 900: '#10241E', 950: '#0A1914' },
                /** `blue`, el único acento de marca. */
                primary: { 25: '#F4FAF6', 50: '#E7F2EC', 100: '#CFE5DA', 200: '#A6CDB9', 300: '#6FA58B', 400: '#3C7A62', 500: '#1E5646', 600: '#0F3D33', 700: '#0A2B24', 800: '#07201B', 900: '#041510' },
                /** Estado: éxito, peligro, advertencia, información; `purple` para etiquetas. */
                emerald: { 25: '#F4FAF6', 50: '#E3F4E8', 100: '#CDEBD6', 200: '#A5DAB6', 300: '#74C58D', 400: '#3FAE68', 500: '#1E9E5A', 600: '#0B6B3A', 700: '#0B6B3A', 800: '#0B4F2C', 900: '#073A20' },
                green: { 25: '#F4FAF6', 50: '#E3F4E8', 100: '#CDEBD6', 200: '#A5DAB6', 300: '#74C58D', 400: '#3FAE68', 500: '#1E9E5A', 600: '#0B6B3A', 700: '#0B6B3A', 800: '#0B4F2C', 900: '#073A20' },
                rose: { 25: '#FFF7F6', 50: '#FDE7E4', 100: '#FAD0CB', 200: '#F5AFA7', 300: '#EE867B', 400: '#E15B4E', 500: '#C93A2D', 600: '#A3261B', 700: '#A3261B', 800: '#82190F', 900: '#600F08' },
                red: { 25: '#FFF7F6', 50: '#FDE7E4', 100: '#FAD0CB', 200: '#F5AFA7', 300: '#EE867B', 400: '#E15B4E', 500: '#C93A2D', 600: '#A3261B', 700: '#A3261B', 800: '#82190F', 900: '#600F08' },
                amber: { 25: '#FFF9ED', 50: '#FFF0D1', 100: '#FFE3A8', 200: '#FACB6B', 300: '#EFB03A', 400: '#D8941A', 500: '#B97708', 600: '#8A4B00', 700: '#8A4B00', 800: '#6E3C00', 900: '#4F2B00' },
                yellow: { 25: '#FFF9ED', 50: '#FFF0D1', 100: '#FFE3A8', 200: '#FACB6B', 300: '#EFB03A', 400: '#D8941A', 500: '#B97708', 600: '#8A4B00', 700: '#8A4B00', 800: '#6E3C00', 900: '#4F2B00' },
                lime: { 50: '#F6FCDD', 100: '#EDF9B8', 200: '#E3F78F', 300: '#D4F76B', 400: '#C2E955', 500: '#A8CE3A' },
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
