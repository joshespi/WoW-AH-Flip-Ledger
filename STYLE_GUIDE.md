# WoW Ledger style guide

The ledger uses Laravel Blade, Tailwind, Livewire, and Chart.js. Dark is the default. Light and System are available from the theme switcher.

## Color and type

Theme roles live in [app.css](src/resources/css/app.css). The Tailwind palette in [tailwind.config.js](src/tailwind.config.js) supplies colors for existing utility classes and the shared theme.

| Role | Dark | Use |
| --- | --- | --- |
| `--background` | `#0D1015` | Page canvas |
| `--surface` | `#1B2029` | Cards and dialogs |
| `--surface-elevated` | `#222A35` | Table headers and interactive hover states |
| `--surface-inset` | `#131820` | Navigation, fields, and subdued panels |
| `--border` / `--border-control` | `#2B3442` / `#626F81` | Panel separators and interactive boundaries |
| `--primary` / `--primary-highlight` | `#E9B64E` / `#FFD987` | Primary actions, with the highlight on hover |
| `--success` / `--danger` | `#4FD3A0` / `#F07878` | Positive and negative values |
| `--text` / `--text-secondary` | `#F2F0E8` / `#A5ADB9` | Main text and supporting labels |
| `--text-muted` / `--focus` | `#929DAC` / `#FFD987` | Footnotes and keyboard focus |

Use Inter for interface copy and JetBrains Mono with tabular numerals for financial values. Use `.page-title` for page headings, `.card-title` for sections, and `.note` for supporting explanations. Use the text labels and signs provided by `<x-chip>`, `<x-money pnl>`, and `<x-pct>` to convey status and direction.

## Layout and components

- Use `<x-app-layout>` for authenticated pages and `<x-section>` for titled panels. Standard cards use 16 px padding, increasing to 24 px on wider screens.
- Use `<x-stat-tile>` inside `.metric-grid` for dashboard figures. The `featured` prop emphasizes the main metric. Keep units and any valuation caveats visible.
- Reuse the primary, secondary, and danger button components and the shared `.field` class. Use `.seg` with `aria-pressed` or `aria-current` for current ranges and tabs.
- Put wide `.data-table` tables in a named `.table-scroll` region with `tabindex="0"`. Use `.num` on numeric columns and retain the table caption, scope, and sort state. Add `.data-table-sticky` where the header should stay visible during vertical scrolling.
- Use `.empty` for empty results, `<x-input-error>` for field errors, and a delayed Livewire status message for changing results. Use `<x-modal>` with its heading ID as `labelledby`.
- Charts use theme tokens and distinguish series through labels, line patterns, marker shapes, or a zero baseline. Motion follows the user's reduced-motion preference.

## Branding

Use the supplied wide wordmark on the public landing page and the compact emblem for navigation, account screens, favicons, and install icons. Gold identifies the ledger and primary actions. Teal and violet retain their existing game-specific meanings in the interface.
