/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ["./*.php", "./admin/*.php", "./assets/js/**/*.js"],
  theme: {
    extend: {
      colors: {
        vinotinto: '#942934',
        vinotintoClaro: '#d32f57',
        verde: '#685f2f',
        verdeClaro: '#a08e43',
        naranja: '#e96510',
        naranjaClaro: '#f39322',
      },
      keyframes: {
        'toast-in': {
          '0%': { transform: 'translateY(30px)', opacity: '0' },
          '100%': { transform: 'translateY(0)', opacity: '1' },
        },
        'toast-out': {
          '0%': { opacity: '1' },
          '100%': { opacity: '0' },
        },
      },
      animation: {
        'toast-in': 'toast-in 0.5s ease-out',
        'toast-out': 'toast-out 0.5s ease-in forwards',
      },
    },
  },
  plugins: [],
};
