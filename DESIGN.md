---
name: Renovo
description: Self-hosted tracking of a household's subscriptions and recurring bills.
colors:
  ledger-navy: "#0A2540"
  emerald-accent: "#00D084"
  emerald-hover: "#00B371"
  emerald-text: "#047857"
  emerald-soft: "#E6FAF2"
  page-mist: "#F8FAFC"
  card-white: "#FFFFFF"
  surface-slate-1: "#F1F5F9"
  surface-slate-2: "#E2E8F0"
  hairline: "#E2E8F0"
  control-edge: "#7C8BA1"
  field-edge: "#CBD5E1"
  ink-muted: "#4A5568"
  ink-faint: "#5A6A7E"
  ok: "#137333"
  ok-bg: "#E6F4EA"
  warn: "#B45309"
  warn-bg: "#FEF3C7"
  bad: "#B91C1C"
  bad-bg: "#FEE2E2"
  info: "#1D4ED8"
  info-bg: "#EFF6FF"
  brand-teal: "#0DAA9C"
  brand-blue: "#1069BB"
typography:
  kpi:
    fontFamily: "JetBrains Mono Variable, ui-monospace, SFMono-Regular, Menlo, Consolas, monospace"
    fontSize: "1.375rem"
    fontWeight: 700
    fontFeature: "tnum"
  title:
    fontFamily: "Plus Jakarta Sans Variable, system-ui, -apple-system, Segoe UI, Roboto, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 700
    lineHeight: 1.25
  heading:
    fontFamily: "Plus Jakarta Sans Variable, system-ui, -apple-system, Segoe UI, Roboto, sans-serif"
    fontSize: "1rem"
    fontWeight: 700
    lineHeight: 1.25
  body:
    fontFamily: "Plus Jakarta Sans Variable, system-ui, -apple-system, Segoe UI, Roboto, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.5
  dense:
    fontFamily: "Plus Jakarta Sans Variable, system-ui, -apple-system, Segoe UI, Roboto, sans-serif"
    fontSize: "0.8125rem"
    fontWeight: 400
  label:
    fontFamily: "Plus Jakarta Sans Variable, system-ui, -apple-system, Segoe UI, Roboto, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 500
  caption:
    fontFamily: "Plus Jakarta Sans Variable, system-ui, -apple-system, Segoe UI, Roboto, sans-serif"
    fontSize: "0.6875rem"
    fontWeight: 500
  figure:
    fontFamily: "JetBrains Mono Variable, ui-monospace, SFMono-Regular, Menlo, Consolas, monospace"
    fontFeature: "tnum"
rounded:
  small: "6px"
  control: "8px"
  button: "10px"
  card: "12px"
  modal: "14px"
  pill: "999px"
spacing:
  chip-y: "0.2rem"
  control-y: "0.55rem"
  control-x: "1rem"
  card: "1.25rem"
  stack: "1rem"
components:
  button-primary:
    backgroundColor: "{colors.emerald-accent}"
    textColor: "{colors.ledger-navy}"
    rounded: "{rounded.button}"
    padding: "0.55rem 1rem"
    typography: "{typography.body}"
  button-primary-hover:
    backgroundColor: "{colors.emerald-hover}"
  button-secondary:
    backgroundColor: "{colors.ledger-navy}"
    textColor: "{colors.card-white}"
    rounded: "{rounded.button}"
    padding: "0.55rem 1rem"
  button-quiet:
    backgroundColor: "{colors.surface-slate-1}"
    textColor: "{colors.ledger-navy}"
    rounded: "{rounded.button}"
    padding: "0.55rem 1rem"
  button-quiet-hover:
    backgroundColor: "{colors.surface-slate-2}"
  button-danger:
    backgroundColor: "{colors.bad-bg}"
    textColor: "{colors.bad}"
    rounded: "{rounded.button}"
    padding: "0.55rem 1rem"
  card:
    backgroundColor: "{colors.card-white}"
    rounded: "{rounded.card}"
    padding: "{spacing.card}"
  chip:
    backgroundColor: "{colors.surface-slate-1}"
    textColor: "{colors.ledger-navy}"
    rounded: "{rounded.pill}"
    padding: "0.2rem 0.55rem"
    typography: "{typography.label}"
  input:
    backgroundColor: "{colors.surface-slate-1}"
    textColor: "{colors.ledger-navy}"
    rounded: "{rounded.control}"
    padding: "0.55rem 0.7rem"
  nav-rail:
    backgroundColor: "{colors.ledger-navy}"
    width: "240px"
---

# Design System: Renovo

<!--
  Recorded from the shipped code, not designed here. The normative sources are
  assets/theme/tokens.json (every colour, per palette x theme), assets/css/tokens.css
  (shape and type) and the prototype in design-import/. If this file and those
  disagree, those win and this file is stale: re-run /impeccable document.
  Values below are the default palette (navy) in the light theme.
-->

## Overview

**Creative North Star: "The Household Ledger"**

Renovo is a tidy, trustworthy account book for a household. Quiet surfaces carry
exact figures; one bright mark, the palette's accent, points at the thing that
needs doing. It is an operating tool used week in, week out, so restraint is the
craft: scanability and consistency come before expression, and the brand lives
in small exact details rather than in decoration.

The page is a pale mist with white cards on it, each edged by a hairline and an
almost invisible shadow. A dark navy rail holds the navigation on desktop; below
768px it becomes a tab bar along the bottom. Every figure is set in a
monospaced face with tabular digits, so columns of money line up like a ledger.

Five palettes (navy, paper, midnight, ocean, forest), each in light and dark,
are swapped by an attribute on the root, never by recompiling. Every palette and
theme is held to WCAG AA by a test that reads the same token file the build
does. Every byte is served by the instance: no CDN fonts, scripts or icons.

**Key Characteristics:**
- Calm, flat-leaning surfaces; tonal layering does most of the depth.
- One accent action per screen; everything else is quiet.
- Figures in JetBrains Mono with tabular digits; words in Plus Jakarta Sans.
- Status in four semantic pairs (ok, warn, bad, info), each a colour on its tint.
- A fixed type scale; 11px is the floor for new text.
- Same shell, cards, chips, switches and tab-links on every screen.

## Colors

A navy-ink, slate-neutral palette with a single bright accent, and status colours
that always come as a colour on its own pale tint.

### Primary
- **Emerald Accent** (#00D084): the one action a screen is about (the primary
  button), the active navigation marker, a featured card's top edge, chart
  emphasis. Text on it is Ledger Navy, never white (white is 2.0:1).
- **Emerald Text** (#047857): accent-coloured text and links, where the bright
  accent would fail contrast.
- **Emerald Soft** (#E6FAF2): the accent's pale tint behind selected states.

### Secondary
- **Ledger Navy** (#0A2540): body text, the navigation rail, and the secondary
  (ink-filled) button for a notable action that is not the screen's main one.

### Neutral
- **Page Mist** (#F8FAFC): the page background.
- **Card White** (#FFFFFF): cards and the top bar.
- **Slate Surfaces** (#F1F5F9, #E2E8F0): quiet buttons, fields, chips, hover
  steps.
- **Hairline** (#E2E8F0): card and table borders.
- **Control Edge** (#7C8BA1): the edge of a chip filter or a switch track
  (held to 3:1). **Field Edge** (#CBD5E1) is the lighter edge of a text field,
  deliberately below 3:1; the field's fill and label find it.
- **Muted / Faint Ink** (#4A5568, #5A6A7E): secondary and tertiary text.

### Status
- **OK** (#137333 on #E6F4EA), **Warn** (#B45309 on #FEF3C7), **Bad** (#B91C1C on
  #FEE2E2), **Info** (#1D4ED8 on #EFF6FF): budget states, alerts, badges.

### Named Rules
**The Token Rule.** A colour is written down once, in `assets/theme/tokens.json`,
for the base or for every palette. Never a literal in a stylesheet, template or
chart; every new pair it is drawn in goes into `ThemeContrastTest`.

**The Accent Ink Rule.** A filled accent surface carries `--accent-ink`; accent
text is `--accent-text`. Never white on the accent.

**The Mark-Only Gradient Rule.** The teal-to-blue gradient (#0DAA9C to #1069BB)
belongs to the logo mark alone. Interface elements take the palette's flat
accent, which is what lets five palettes exist.

## Typography

**Body Font:** Plus Jakarta Sans Variable (with system-ui, Segoe UI, Roboto)
**Figures Font:** JetBrains Mono Variable (with ui-monospace, Menlo, Consolas)

**Character:** A friendly, open grotesque for words, paired with an exact
monospace for every amount, count and date in a table: the words are warm, the
numbers are a ledger.

### Hierarchy
- **KPI** (bold 700, 1.375rem, figures face): the headline numbers on the
  dashboard and summary strips.
- **Title** (bold 700, 1.125rem, 1.25): page titles (`h1`).
- **Heading** (bold 700, 1rem, 1.25): card and section headings (`h2`).
- **Body** (400, 0.875rem, 1.5): everything else; headings use `text-wrap: balance`.
- **Dense** (400, 0.8125rem): compact lists and tables.
- **Label** (500, 0.75rem): field labels, chips, table headers.
- **Caption** (500, 0.6875rem): the floor for readable text. The only things
  below it are a few uppercase micro-labels (0.625rem): the rail's tagline and
  section headings, the auth mark's tagline and three calendar labels.

### Named Rules
**The Figure Rule.** An amount, count or date in a table takes the `.num` class,
and `.num` is the only rule that names the figures face.

**The Fixed Scale Rule.** Sizes come from `--size-*` in `assets/css/tokens.css`.
A new heading lands on a size that already exists; 11px is the floor for new
text.

## Layout

A fixed shell on every signed-in page: a 240px rail down the left, a 64px top bar
across the rest, and the content column, which is the part that scrolls. Below
768px the rail becomes a tab bar along the bottom with a raised add button.
Content is cards stacked 1rem apart with 1.25rem of padding; forms are flat
fieldsets of fields under a legend. Lists offer comfortable and compact density.
Breakpoints in use: 640, 720, 768 (the shell switch), 860, 900 and 1440px.
Mobile-first; check every screen at 375, 768 and 1280px, light and dark.

## Elevation & Depth

Flat by default, with tonal layering doing the work: mist page, white card,
slate fields and quiet buttons inside it. Cards carry one barely-there shadow in
light (`0 4px 6px -1px rgba(0,0,0,0.04), 0 2px 4px -1px rgba(0,0,0,0.02)`) and
none at all in dark, where the surface steps alone separate the layers. Dialogs
sit over a navy backdrop (rgba(15,23,42,0.55)) and drop their inner cards'
shadow.

### Named Rules
**The Hairline-First Rule.** A card is found by its 1px hairline and its surface
step, not by its shadow. Never add a heavier shadow, a glow or a coloured shadow.

## Shapes

Softly rounded and graded by size: 12px on a card, 14px on a modal, 10px on a
button, 8px on a field or a nav item, 6px on small inner elements, and a full
pill on chips and badges. A single radius everywhere would make a chip look like
a card that failed to grow. Focus is a 2px ring in `--focus-ring`, offset 2px,
held to 3:1 on every surface (and `currentColor` in forced-colours mode).

## Components

Refined and restrained: four button roles with one job each, cards that recede,
and fields that are quiet until focused.

### Buttons
- **Shape:** gently rounded (10px), 0.55rem by 1rem, semibold body text.
- **Primary:** Emerald Accent fill with Ledger Navy ink. One per screen.
- **Secondary:** the ink fill (Ledger Navy; near-white in dark) for a notable
  action that is not the screen's main one, such as the top bar's quick-add.
- **Quiet:** a slate tint; what a bare `.button` is. Everything else.
- **Danger:** Bad on its tint; the border appears on hover. Deleting, revoking.
- **Outline:** for a card's own actions.

### Chips
- **Style:** slate fill, hairline edge, full pill, label size, 0.2rem by 0.55rem.
- **Use:** tags, filters and small status labels; never a primary control.

### Cards / Containers
- **Corner Style:** 12px.
- **Background:** Card White on Page Mist.
- **Shadow Strategy:** see Elevation; the hairline leads.
- **Featured:** a 3px accent top edge, the only accent a card takes.
- **Internal Padding:** 1.25rem. Each card has a fixed section id a redirect can
  land on.

### Inputs / Fields
- **Style:** slate fill, light Field Edge, 8px corners, 0.55rem by 0.7rem.
- **Focus:** the 2px focus ring.
- **Error:** a `.field-error` message under the field, in Bad.

### Navigation
- **Rail:** the palette's rail colour (Ledger Navy by default), muted text,
  medium-weight body size, 8px corners. "You are here" is one treatment
  everywhere: the accent's soft fill and a 3px accent bar on the leading edge,
  decided on the server from the route.
- **Mobile:** a bottom tab bar; the add button raised in the ink fill.
- **Tabs:** tabs are links (`settings/_tabs.twig`), not script-driven panels.

### Account Rows
The settings idiom: a row with a label, a description and a control (switch,
select or button) at its end, grouped in a card. Reuse it for any new setting.

## Do's and Don'ts

### Do:
- **Do** reuse the shell, cards, `account-rows`, chips, switches and tab-links
  for any new screen, following the prototype in `design-import/`.
- **Do** keep one primary (accent) button per screen.
- **Do** mark every figure with `.num`.
- **Do** add a new colour to `tokens.json` for every palette, and its pairs to
  `ThemeContrastTest`; add a new icon to `assets/theme/icons.json`.
- **Do** check light and dark, every palette that changes the rail (navy, paper,
  ocean), and 375/768/1280px.

### Don't:
- **Don't** load a font, stylesheet, script or icon from any other host.
- **Don't** write a colour literal in CSS, a template or a chart.
- **Don't** use the logo's gradient on an interface element.
- **Don't** put white text on the accent.
- **Don't** add inline styles, scripts or handlers (the CSP refuses them); use
  `data-css-*` and `data-*` behaviours.
- **Don't** set new text below 11px, or invent a size outside the scale.
- **Don't** add glows, heavy or coloured shadows, or gradient text.
