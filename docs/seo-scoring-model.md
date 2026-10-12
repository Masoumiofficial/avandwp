# AvandWP — SEO / AIO / GEO Scoring Model

**Date:** 2026-10-12 · **Evaluator:** `AvandWP_Quality_Lab` + `AvandWP_Criteria`
**Criteria:** 37 · **Profiles:** 3 (SEO 19/91, AIO 10/62, GEO 8/53)

This document is the authoritative description of what the plugin's three scores
mean. The same criteria table is rendered inside the plugin, generated from the
same registry, so the UI cannot drift from this file.

---

## 1. What the scores are — and what they are not

The scores are **internal checklists**. Each one answers a single question:

| Profile | Label | Question it answers |
|---|---|---|
| SEO | سئو | Is the content structurally and technically ready for a classic search engine? |
| AIO | کیفیت پاسخ برای هوش مصنوعی | Can a system answer a user's question directly from this page? |
| GEO | آمادگی استناد در موتورهای مولد | Is this page written so that a generative system *could* quote it? |

**Not claimed:** that a high score produces rankings, traffic, or citations. The
evaluation is not official and AIO/GEO are not standardised metrics; the plugin
defines them, and the definition is printed next to every score and included in
every report as `summary.disclaimer`:

> امتیاز ۱۰۰ تنها به این معناست که همه معیارهای قابل ارزیابیِ تعریف‌شده در این
> افزونه درست بوده‌اند. این عدد تضمین رتبه در موتورهای جستجو یا استناد توسط
> سیستم‌های هوش مصنوعی نیست.

A lint policy fails the build if the quality report loses that disclaimer.

---

## 2. How a score is computed

```
facts      = Content_Analyzer::analyze( $html, $context )
per profile:
    earned = 0; possible = 0
    for each criterion:
        result = evaluate( facts )        → pass | partial | fail | unavailable
        if result == unavailable:  criterion leaves the denominator
        else:                      possible += weight
                                   pass    → earned += weight
                                   partial → earned += weight * PARTIAL_CREDIT
                                   fail    → earned += 0
    score = possible > 0 ? round( earned / possible * 100 ) : null
    if score == 100 and any criterion is partial → score = 99
```

Consequences, all deliberate:

* **Not evaluable ≠ failed.** If the user supplies no focus keyword, keyword
  criteria are `unavailable` and leave the denominator. The score then reflects
  the criteria that *could* be judged.
* **No data ⇒ no number.** A profile with nothing evaluable returns `null`, which
  the UI renders as "امتیازی محاسبه نشد". 0 would be a fabricated failure and 100
  a fabricated pass.
* **100 is arithmetically honest.** Any partial credit downgrades a perfect score
  to 99, so a displayed 100 means every applicable criterion passed outright.
* **Every criterion carries three fields into the report**: `evidence` (the
  measured value, e.g. "عنوان ۴۲ کاراکتر"), `explanation` (why that value
  produced that result) and `recommendation` (the single action that would change
  it). A criterion that cannot show evidence is not used to score.
* **A failing evaluator cannot inflate the score.** An exception inside one
  criterion is caught and that criterion becomes `unavailable` with the message
  preserved.

An aggregate number is also produced (`summary.overall` = mean of the evaluable
profile scores) for the "next action" line. It is a summary of the three
checklists, not a fourth industry metric, and it is never shown without them.

---

## 3. Profile: SEO — 19 criteria, total weight 91

| Weight band | Intent | Representative criteria |
|---|---|---|
| High (8–10) | What is most likely to decide whether the page can be understood and indexed | `<title>` present and within length, a single H1, indexability (no `noindex`), canonical correctness, content length relative to intent |
| Medium (4–7) | Coverage and semantics | focus keyword in title/H1/first paragraph/URL, secondary keyword coverage, heading hierarchy, internal links, external sources, image alt text, meta description quality, slug readability |
| Low (1–3) | Refinements | keyword density in a sensible range, list/table usage for scannable content, freshness signal via `updated_date`, schema presence where the SEO plugin does not already supply it |

Structural facts are measured, not guessed: heading counts come from the parsed
HTML, keyword presence from normalised Persian text matching (which handles Arabic
vs Persian `ی`/`ک`, ZWNJ and diacritics), and length from `Text_Helper::measure()`
with words, characters with spaces and characters without spaces tracked
separately.

`updated_date` is compared only when the caller supplies it (the post's modified
date); otherwise freshness is `unavailable` rather than assumed.

---

## 4. Profile: AIO — 10 criteria, total weight 62

Every AIO criterion exists because it changes whether an answer engine can lift a
usable answer from the page:

| Criterion (intent) | What is measured |
|---|---|
| Direct definition early | Is the core term defined in a self-contained sentence near the top? |
| Self-contained sections | Does each section make sense without the previous one? |
| Question-shaped headings | Are H2/H3 phrased as the questions users ask? |
| Short answers | Are there short, quotable answer blocks (roughly 40–60 words)? |
| Explicit lists and steps | Are procedures expressed as lists rather than prose? |
| FAQ coverage | Are the obvious follow-up questions answered and labelled? |
| Terminology consistency | Are entities and terms used consistently, with nothing referred to by three names? |
| No unresolved references | Are pronouns/"as mentioned above" avoided in favour of explicit subjects? |
| Cross-checks | Does the page contradict itself in numbers or claims? |
| Machine-readable structure | Are headings, lists and tables used so structure is inferable without CSS? |

These criteria are defined by this plugin. They are declared in the UI as such:
AIO is not an official, universal standard.

---

## 5. Profile: GEO — 8 criteria, total weight 53

"Could a generative system quote this accurately?"

| Criterion | What is measured |
|---|---|
| Specific data | Are there concrete numbers, prices, dates or ranges rather than adjectives? |
| Sources with links | Are claims that need support linked to a source? |
| Named entities | Are brands, products, people, places named explicitly? |
| Author transparency | Is an author name/expertise available (`author_name`, `author_experience`)? |
| Quantified comparisons | Are comparisons expressed as measurable differences? |
| Time clarity | Are time references absolute ("در سال ۱۴۰۴") rather than relative ("اخیراً")? |
| Quote-ready sentences | Are key claims written as standalone sentences? |
| Unambiguous attribution | Is it clear *who* states each claim? |

The plugin states plainly, in the UI and in the docs, that **no score guarantees
that ChatGPT, Gemini, Perplexity or any other system will cite the page**. The
criteria measure quotability of the writing, which is the only part a plugin can
control.

---

## 6. Evidence example

A criterion's result is not a bare pass/fail. This is the shape the UI renders
(from the test suite's fixtures):

```json
{
  "id": "title_present",
  "profile": "seo",
  "label": "عنوان صفحه (Title) موجود و در محدوده طول باشد",
  "weight": 9,
  "severity": "critical",
  "auto_fixable": true,
  "result": "partial",
  "evidence": "عنوان ۷۸ کاراکتر است (محدوده پیشنهادی: ۳۰ تا ۶۰)",
  "explanation": "عنوان وجود دارد اما بلندتر از محدوده‌ای است که موتور جستجو کامل نمایش می‌دهد.",
  "recommendation": "عنوان را کوتاه‌تر کنید و کلمه کلیدی کانونی را به ابتدای آن منتقل کنید."
}
```

---

## 7. Relationship to the site audit score

`Site_Auditor` scores **pages**, not content quality: 20 checks with a total
weight of 123 across crawlability, indexability, canonicalisation, duplication,
titles/descriptions and image alt coverage. It reuses the same scoring rule
(weighted achieved ÷ weighted possible, `null` when nothing is evaluable) but its
checklist is different and it operates on fetched HTML rather than on the editor's
content.

One property is documented so it is not mistaken for a bug: the negative checks
(no `noindex`, no redirect chain, canonical present, images have alt text) pass by
absence on an empty document, so an empty page scores **25**, not 0. The audit
report always labels the number as an internal checklist.

---

## 8. Changing the model

* Weights and criteria live in one registry (`class-criteria.php`); the UI table
  and this document are generated from it.
* `avandwp_quality_criteria` filter lets a site add its own criteria without
  patching the plugin.
* Adding a criterion requires: an evaluator, a weight, a severity, an
  `auto_fixable` flag, a `measures` string, and a test that proves the score
  arithmetic still excludes non-evaluable criteria correctly. Criteria that cannot
  produce evidence must not be added — they would make the score unfalsifiable.
