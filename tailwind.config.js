import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                'deep-indigo':    '#1E2547',
                'neural-teal':    '#2A8C8C',
                'memory-violet':  '#6D6AF7',
                'cloud-white':    '#F5F7FB',
                'slate-brand':    '#5B6472',
            },
        },
    },

    plugins: [forms, typography],
};
