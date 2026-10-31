# Research Design, Metric Definitions, & Evaluation Protocol (`v3.0`)

This document formalizes the experimental design, study objective mapping, mathematical metric definitions, volatility & ablation protocols, blind human annotation protocol, usability (SUS) instrument, and statistical testing pipeline for **Davao Food Finder** (`v3.0`).

---

## 1. Alignment With the Four Thesis Objectives

1. **Objective 1 — Data Volatility Resilience (`evaluation/run_volatility_experiment.php`):**
   - Runtime reconciliation (`reconcileVolatilityWithEvidence()` in `src/web_reasoner.php`) cross-checks static 2025 CSV records (`ph_restos_2025.csv`, `ph_reviews_2025.csv`) against live/updated web evidence to detect **permanent or temporary closures**, **menu price drift**, **operating hours changes**, and **customer rating shifts**.
   - Validated empirically across **15 controlled counterfactual & temporal Davao City scenarios** (`evaluation/volatility_cases.json`) comparing `STATIC-CSV-BASELINE`, `STALE-CACHE-12H` (>12h outdated snapshot), `ABLATION-NO-LOOP-FRESH`, and `AGENTIC-LOOP-FRESH`.
2. **Objective 2 — Resolving Categorical & Lexical Disambiguation Errors:**
   - Formalized via `disambiguation_schema` in `evaluation/ground_truth.json` covering six subtypes:
     - `lexical_polysemy`: Surface tokens matching unrelated restaurant brands (`"green"` -> `Greenwich`/`Green Coffee` vs. healthy salads; `"24"` -> `24 Chicken` vs. 24-hour dining; `"tokyo"` -> `Tokyo Waffle` vs. Japanese; `"durian"` -> `Durian Dub Burgers` vs. durian desserts/pasalubong).
     - `category_confusion`: Regional food-style modifiers in Davao City (`"Cebu puso BBQ in Davao City"`) vs. out-of-scope cities, and beverage vs. meal category boundaries.
     - `spelling_synonym`: Davao Bisaya/Taglish vernacular (`kan-anan`, `karinderya`, `sinugba`, `sutukil`, `kinilaw`, `painitan`, `kapehan`) and phonetic culinary variants (`sangyup`, `shwarma`, `briyani`, `ramyun`).
     - `mixed_intent`: Multi-category group/neighborhood queries.
     - `anaphoric_followup`: Pronominal and comparative coreference (`"those"`, `"them"`, `"the cheaper ones under ₱180"`).
     - `underspecified_ambiguous`: Open-ended mood/landmark queries (`"date night"`, `"hungry near Ateneo Roxas"`).
3. **Objective 3 — High-Precision Recommendations for Complex Intent:**
   - Evaluated on **72 total benchmark cases** (`62` in-scope single-turn, disambiguation, numeric price-ceiling, & multi-turn cases + `10` out-of-scope boundary checks) partitioned into `train` (12), `dev` (15), and held-out `test` (45) splits.
   - Tested with **tie-corrected Friedman omnibus tests**, **pairwise two-sided Wilcoxon signed-rank tests with Holm-Bonferroni correction**, and **95% seeded bootstrap confidence intervals** (`B = 2000`).
4. **Objective 4 — Numeric Price Ceilings, Thematic Keywords, Autonomous Iterative Loop, & Usability (SUS):**
   - **Verified Numeric Peso Price Extraction (`extractPesoAmountsFromText()` & `enforceNumericPriceCeiling()`):** Extracts real peso prices (`₱` / `PHP` / `pesos`) from customer reviews and live web updates into `price_evidence` without fabricating blank CSV `price_range` fields.
   - **True Autonomous Iterative Critique-and-Refinement Loop (`runAutonomousRefinementLoop()`):**
     - *Pass 1 (Draft):* Initial shortlist retrieval & candidate ranking.
     - *Autonomous Critic (`critiqueSinglePlace()`):* Audits top candidates for closures, polysemy traps, hard numeric `max_price_php` violations, hours/rating conflicts, dietary conflicts, and prior-turn exclusions.
     - *Pass 2 (Re-Retrieval & Self-Repair):* Triggers full-corpus re-retrieval (`$applyHardCritiqueMasks = true`) and repairs both ranked cards and natural-language summaries, logging `initial_compliance_rate`, `final_compliance_rate`, `violations_detected`, `violations_repaired`, and `re_retrieved`.
   - **Standardized Human Usability & SUS Portal (`evaluation/usability.php`):** Administers 6 interactive tasks and the official **10-item System Usability Scale (SUS, Brooke 1996, 0–100)**, recording Task Completion Rate, Time-on-Task, Perceived Relevance (`1–5`), and Price/Source Trust (`1–5`).

---

## 2. Controlled Experimental Conditions

All conditions run against identical SHA-256 verified CSV files, splits, and seeds:

1. **`baseline` (`BASELINE-LOCAL`):** Raw deterministic lexical retrieval over static CSV records + scope guard (`agentic = false`, no synonym expansion, no anti-polysemy guard, no iterative loop).
2. **`ablation-no-loop` (`ABLATION-NO-LOOP`):** Single-pass retrieval with intent parsing *without* Pass 2 autonomous critique/re-retrieval (`agentic = false`), isolating the exact contribution of the Autonomous Iterative Loop.
3. **`agentic-local` (`AGENTIC-LOOP-LOCAL`):** Grounded local-and-evidence agentic engine with full 2-pass Autonomous Iterative Critique-and-Refinement Loop (`agentic = true`), runnable deterministically offline (`--local-cli`) or via HTTP.
4. **`non-agentic` (`NON-AGENTIC-T0.2-STRICT`):** Single-pass LLM over raw shortlist without Playwright live crawl, multi-turn reformulation, or autonomous loop.
5. **`deepseek` / `gpt-4o-mini` / `hybrid`:** Live LLM reasoning pipelines (`DeepSeek R1`, `GPT-4o mini`, and 3-stage `Hybrid`) coupled with Playwright web evidence reconciliation and the Pass 2 Autonomous Iterative Critique-and-Refinement Loop.

---

## 3. Formal Metric Definitions

- **Precision@5:** $\text{P@5} = \frac{1}{5} \sum_{i=1}^{\min(5, |R|)} \mathbb{I}[\text{rel}(r_i) \ge 1]$ over unique normalized names.
- **Recall@K ($K \in \{5, 10\}$):** $\text{R@K} = \frac{1}{|G_+|} \sum_{i=1}^{\min(K, |R|)} \mathbb{I}[\text{rel}(r_i) \ge 1]$.
- **NDCG@5:** Using ordered relevance grades $g_i \in \{0, 1, 2, 3\}$:
  $$\text{DCG@5} = \sum_{i=1}^{\min(5, |R|)} \frac{2^{g_i} - 1}{\log_2(i + 1)}, \qquad \text{NDCG@5} = \frac{\text{DCG@5}}{\text{IDCG@5}}$$
- **Mean Reciprocal Rank (MRR):** $\text{RR} = \frac{1}{\text{rank}_1}$ of the first relevant unique item ($0$ if none).
- **Disambiguation Accuracy & Trap-Free Rate:** Fraction of disambiguation cases (`D001`–`D014`, `MT007`) achieving relevant Top-5 precision with zero polysemy/category `forbidden_names` traps (`disambiguation_trap_free === true`) and strict constraint satisfaction, reported overall and per subtype.
- **Hard Numeric Price-Ceiling Compliance Rate:** Fraction of explicit peso ceiling queries (`PC001`–`PC005`, `MT007`) where zero Top-5 venues exceed `max_price_php` and at least one verified budget-compliant venue is retrieved.
- **Data Volatility Resilience Pass Rate:** Fraction of volatility scenarios (`VOL001`–`VOL015`) where 100% of newly closed, price-hiked, early-closing, or rating-degraded venues are suppressed from Top-5 and valid open Davao City replacements achieve $\ge 60\%$ Top-5 precision.
- **System Usability Scale (SUS — Brooke, 1996):**
  $$\text{SUS} = 2.5 \times \left(\sum_{i \in \{1,3,5,7,9\}} (x_i - 1) + \sum_{j \in \{2,4,6,8,10\}} (5 - x_j)\right) \in [0, 100]$$

---

## 4. Academic Integrity & Human Study Boundaries

- **Inter-Rater Reliability (`evaluation/annotate.php`):** Until at least two independent human raters submit blind ratings in `evaluation/annotations/*.json`, `run_evaluation.php` explicitly labels agreement as `source = "rubric_criteria_audit"` rather than claiming human inter-annotator $\kappa$.
- **Human Usability Study (`evaluation/usability.php`):** Zero synthetic participant sessions are pre-populated (`source = "awaiting_human_participants"`, `n = 0` by default). Administer `usability.php` with real human participants before reporting empirical SUS scores in thesis prose.

---

## 5. Reproducible CLI Commands

```powershell
Set-Location 'C:\Users\cyber\OneDrive\Documents\Thesis20261028-main\Davao-Food-Finder-main\Davao-Food-Finder-main\davao_food_finder'

# 1. Rebuild & validate the 72-case v3.0 ground-truth benchmark
& 'C:\xampp\php\php.exe' .\evaluation\build_ground_truth.php

# 2. Run the 52-assertion unit & integration verification suite
& 'C:\xampp\php\php.exe' .\evaluation\test_evaluation_suite.php

# 3. Run Objective 1 Controlled Data Volatility Experiment (15 scenarios x 4 conditions)
& 'C:\xampp\php\php.exe' .\evaluation\run_volatility_experiment.php --local-cli

# 4. Run Controlled 3-System Ablation & Agentic Loop Benchmark (45 held-out test cases, 3 trials)
& 'C:\xampp\php\php.exe' .\evaluation\run_evaluation.php --local-ablation --split=test --trials=3 --seed=42

# 5. Run Full Live LLM Comparison (requires PHP server + Ollama DeepSeek R1 / OPENAI_API_KEY)
& 'C:\xampp\php\php.exe' .\evaluation\run_evaluation.php --research --split=test --trials=3 --seed=42
```
