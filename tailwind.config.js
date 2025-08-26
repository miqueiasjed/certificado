/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./resources/**/*.blade.php",
    "./resources/**/*.js",
    "./resources/**/*.vue",
  ],
  theme: {
    extend: {
      colors: {
        'primary-green': '#059669',
        'light-green': '#10b981',
        'dark-green': '#047857',
      }
    },
  },
  plugins: [],
}
