// Tailwind (CDN) theme: colours sampled from the WaveSpace design mockups.
tailwind.config = {
  theme: {
    extend: {
      colors: {
        cream: '#FAF5E8',   // page background
        paper: '#FEFCF5',   // nav, cards, inputs
        sand: '#E6D6B0',    // beach on the map
        sea: '#8AC3D5',     // water on the map
        ocean: '#065E7F',   // brand teal: wordmark, pins, primary buttons, access-code card
        ink: '#0C1C2A',     // headings / body text
        coral: '#E9834D',   // call-to-action buttons, selected pin
        muted: '#586572',   // secondary text
        line: '#E6DFCB',    // card borders
        tile: '#D8D0BD',    // option-tile borders
        pill: '#ECE4D1',    // active nav tab, empty pin
        mist: '#D8E6E2',    // selected list row
        sage: '#E4EBE4',    // selected option tile
      },
      fontFamily: {
        sans: ['Hind', 'system-ui', '-apple-system', 'Segoe UI', 'sans-serif'],
      },
    },
  },
};
