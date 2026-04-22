# AI Planning Prompt: PHP Translation Toolkit Framework

Use this prompt as-is with Claude, ChatGPT, or any capable LLM. It is designed to constrain the AI to concrete, verifiable outputs and prevent speculative or hallucinated planning.

---

## The Prompt

```
I am a solo PHP developer planning to build an open-source, modular PHP framework
for building computer-assisted translation (CAT) tools. Think of it as "Laravel for
translation tooling" — a set of composable Composer packages that developers can use
independently or together to build their own translation products.

This is NOT a CAT tool itself. It is a framework of standalone, framework-agnostic
PHP packages (no Laravel/Symfony dependency in core packages) that provide the
building blocks: file format parsing, segmentation, translation memory,
terminology management, quality assurance, and machine translation integration.

A separate Laravel-based reference application will be built on top of the framework
to demonstrate a full CAT tool.

## What exists today (verified April 2026)

- PHP/Composer has NO reusable packages for: TMX parsing, TBX parsing,
  SRX segmentation, translation memory engines, CAT-grade file filters
  (DOCX/PPTX/XLSX with inline tag preservation), terminology engines,
  or translation QA checks.
- MateCat is an open-source PHP CAT tool but is monolithic — its components
  are not extractable as standalone packages.
- The Python ecosystem has Translate Toolkit (by Translate House) and
  the Java ecosystem has Okapi Framework. PHP has nothing equivalent.
- Relevant PHP packages that DO exist: matecat/xliff-parser (XLIFF parsing,
  coupled to MateCat), Label305/DocxExtractor (basic DOCX text
  extraction/injection with skeleton approach, limited scope),
  wyndow/fuzzywuzzy (general fuzzy string matching), PHP built-in
  levenshtein() and similar_text() (byte-level, not UTF-8 safe).

## My constraints

- Solo developer, 5-10 hours/week alongside freelance translation work.
- I have professional experience using Wordfast, Wordfast Pro, Trados Studio,
  memoQ, and Phrase as a translator, so I understand the user-facing behavior
  of these tools deeply.
- Tech stack for framework packages: PHP 8.2+, no framework dependencies,
  distributed as Composer packages.
- Tech stack for the reference app: Laravel + Inertia.js + React.
- Target languages I work with include English, Hindi, and Urdu, so the
  framework must handle RTL text, non-Latin scripts, and Unicode correctly
  from day one.

## What I need from you

Do NOT give me a general overview of what CAT tools are or how they work.
I already know this. Do NOT speculate about features or timelines. Do NOT
suggest technologies or packages without verifying they exist and are maintained.

Instead, produce the following concrete deliverables:

### 1. Package Inventory

List every Composer package I need to create, with:
- Exact proposed package name (vendor/package format)
- One-sentence purpose
- Dependencies on other packages in this framework (if any)
- External PHP dependencies required (only packages that exist on Packagist
  and are actively maintained — state the package name and verify it exists)

### 2. Core Data Models

Define the PHP classes/interfaces for the central data structures that all
packages will share. These are:

- Segment (a single translatable text unit)
- SegmentPair (source + target segment)
- BilingualDocument (collection of segment pairs with file metadata)
- InlineCode (representation of formatting tags within segments)
- TranslationUnit (a TM entry)
- MatchResult (result of a TM lookup)
- QualityIssue (result of a QA check)

For each, provide:
- The PHP interface or class definition with typed properties
- Brief justification for each property (why it is needed)
- Do NOT include implementation logic, only the shape of the data

### 3. Contract Interfaces

Define the PHP interfaces that module implementations must satisfy:

- FileFilterInterface
- SegmentationEngineInterface
- TranslationMemoryInterface
- TerminologyProviderInterface
- QualityCheckInterface
- MachineTranslationInterface

For each, provide:
- The PHP interface definition with method signatures and return types
- Brief explanation of what each method does
- Note any design tradeoffs or decisions that need to be made

### 4. Phase 1 Scope

Define the minimum set of packages and features I should build first to
have a working end-to-end prototype (file in → segments displayed →
translations entered → file out). Be specific about:

- Which packages from the inventory
- Which features within each package (not "TM support" but
  "exact match lookup against SQLite-backed storage with TMX import")
- What I should explicitly NOT build yet and why
- A dependency order (what must be built before what)

### 5. Risks and Hard Problems

Identify the 3-5 specific technical problems that are most likely to
cause me to get stuck or make architectural mistakes I will regret later.
For each:
- What the problem is
- Why it is hard
- What decision I need to make now vs. what I can defer
- If applicable, how Okapi Framework or Translate Toolkit solved it

Do NOT pad this section with generic risks like "scope creep" or
"burnout." I want technical risks specific to this domain.
```

---

## How to Use This Prompt

1. **Use it in a fresh conversation** — no prior context to contaminate the output.
2. **Follow up aggressively** — if the AI lists a Composer package as a dependency, ask it to confirm the package exists with a link. If it defines an interface, ask it to justify every method.
3. **Challenge the Phase 1 scope** — if it includes more than 4-5 packages, push back. The goal is the smallest possible working vertical slice.
4. **Save the output as your project spec** — this becomes the document you refer back to as you build. Update it as decisions change.

## Red Flags to Watch For

If the AI does any of the following, push back or regenerate:

- **Suggests packages that don't exist** — e.g., "use `php-translation/tmx-parser`" (this does not exist)
- **Defines interfaces with 15+ methods** — a sign of over-engineering. Good interfaces have 3-7 methods.
- **Includes "nice to have" features in Phase 1** — fuzzy matching, terminology, QA, and MT are all Phase 2+. Phase 1 is: parse a file, show segments, save translations, rebuild the file.
- **Recommends building a custom editor framework** — the editor is a React component. It does not need its own abstraction layer in Phase 1.
- **Uses vague language** — "robust," "scalable," "comprehensive." Ask for specifics.
