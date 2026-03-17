import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './app/Livewire/**/*.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand: {
                    lime:   '#CCFF00',
                    dark:   '#0f0f0f',
                    card:   '#1a1a1a',
                    border: '#2a2a2a',
                    muted:  '#999999',
                },
                live: '#FF3B30',
            },
        },
    },
    plugins: [],
};
