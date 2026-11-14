# Davao Food Finder

A small server-rendered site using HTML, CSS, JavaScript, PHP, DeepSeek R1, GPT-4o mini, and Playwright. The hybrid pipeline is: **User input → GPT-4o mini query planning & intent extraction → Playwright active web scraping → GPT-4o mini structuring of raw Playwright output → DeepSeek-R1 reasoning & curation → GPT-4o mini final user reply**. PHP handles the UI/API and 2025 local dataset, while the Playwright cache limits repeated scans to 12 hours.

## Requirements
- PHP 8.1 or newer with the `curl` and `mbstring` extensions enabled (e.g., XAMPP `C:\xampp\php\php.exe`)
- Composer, to install the PHP dependencies
- Node.js and Playwright, to actively scrape current public web pages
- An OpenAI API key (`OPENAI_API_KEY`) for **GPT-4o mini** (Stages 1, 3, and 5)
- Ollama with a **DeepSeek R1** model (`deepseek-r1:8b`) for free local reasoning (Stage 4), or a hosted `DEEPSEEK_API_KEY`
- The `zip` and `xml` PHP extensions if you want to read Excel files (`.xlsx` or `.xls`)

---

## Run the App in Terminal (Windows PowerShell)

### Part A: One-Time Dependency Setup
Run these once when setting up the project for the first time:

```powershell
# 1. Install Playwright & Chromium in the workspace root (Thesis20261028-main):
Set-Location 'C:\Users\Rodelia M. Estenzo\OneDrive\Documents\Davao-Curated-Food-Finder-main'
npm.cmd install
npx.cmd playwright install chromium

# 2. Install PHP dependencies inside davao_food_finder (Composer must be installed):
Set-Location 'C:\Users\Rodelia M. Estenzo\OneDrive\Documents\Davao-Curated-Food-Finder-main\davao_food_finder'
composer install

# 3. Download the local DeepSeek R1 model in Ollama:
ollama pull deepseek-r1:8b
```

---

### Part B: Starting the App (Step-by-Step)

> **Important:** Set environment variables (`OPENAI_API_KEY`, `OPENAI_MODEL`, etc.) and start the PHP server in the **exact same PowerShell window** so the PHP server inherits them.

#### Step 1 — Start Ollama for DeepSeek R1 (Terminal 1)
Open a PowerShell window and make sure Ollama is running with the `deepseek-r1:8b` model installed:

```powershell
# Check installed models (confirm deepseek-r1:8b is listed):
ollama list

# Start the Ollama local server (listens on http://127.0.0.1:11434):
ollama serve
```
- **Note if you see `bind: Only one usage of each socket address ... is normally permitted`:** That means Ollama is **already running** in the background (check the llama icon in your Windows system tray). You do not need to run `ollama serve` again—just leave Ollama running.
- **To stop Ollama anytime:** Right-click the Ollama tray icon → **Quit Ollama**, or run:
  ```powershell
  Stop-Process -Name "ollama app", "ollama" -Force -ErrorAction SilentlyContinue
  ```

#### Step 2 — Navigate to the App Folder & Set OpenAI Key + Models (Terminal 2)
Open a **second PowerShell window** (keep this window open for Steps 2–4):

```powershell
# 1. Go to the davao_food_finder directory:
Set-Location 'C:\Users\cyber\OneDrive\Documents\Thesis20261028-main\davao_food_finder'

# 2. Set your OpenAI API key (keep on server only; never commit to git):
$env:OPENAI_API_KEY = "sk-proj-YOUR_OPENAI_API_KEY_HERE"

# 3. Set the OpenAI model (defaults to gpt-4o-mini if omitted):
$env:OPENAI_MODEL = "gpt-4o-mini"

# 4. Set the local Ollama DeepSeek model & URL (defaults shown below):
$env:DEEPSEEK_BASE_URL = "http://127.0.0.1:11434"
$env:DEEPSEEK_MODEL = "deepseek-r1:8b"
```
*(Optional)*:
- If your PC has limited RAM/CPU and `deepseek-r1:8b` is slow, pull `ollama pull deepseek-r1:1.5b` and set `$env:DEEPSEEK_MODEL = "deepseek-r1:1.5b"`.
- If using the hosted DeepSeek API instead of local Ollama, set `$env:DEEPSEEK_API_KEY = "sk-..."`, `$env:DEEPSEEK_BASE_URL = "https://api.deepseek.com"`, and `$env:DEEPSEEK_MODEL = "deepseek-reasoner"`.

#### Step 3 — Verify All 5 Pipeline Stages Before Launching (Same Terminal 2)
Still inside **Terminal 2**, run the diagnostic script to confirm GPT-4o mini, Playwright, and Ollama DeepSeek R1 all report `[OK]`:

```powershell
& 'C:\xampp\php\php.exe' .\test_pipeline.php
```

#### Step 4 — Start the PHP Server & Open the App (Same Terminal 2)
In that **same Terminal 2 window**, launch the built-in PHP server:

```powershell
& 'C:\xampp\php\php.exe' -d extension=gd -S 127.0.0.1:8080 -t .
```

Now open **[http://127.0.0.1:8080/Main.html](http://127.0.0.1:8080/Main.html)** in your browser:
- Keep **Terminal 2** open while using the app; press **Ctrl+C** to stop the PHP server.
- Do **not** open `Main.html` as a `file://` URL or via a static Live Server extension—searches require PHP to execute `api.php`.
- If port `8080` is already in use, change `8080` to another port (e.g. `127.0.0.1:8081`) and open that port in your browser.

---

### Quick Copy-Paste Launch Block (PowerShell)
Once Ollama is running (`deepseek-r1:8b` pulled), you can paste this single block into PowerShell to configure and start everything at once:

```powershell
Set-Location 'C:\Users\cyber\OneDrive\Documents\Thesis20261028-main\davao_food_finder'
$env:OPENAI_API_KEY = "sk-proj-YOUR_OPENAI_API_KEY_HERE"
$env:OPENAI_MODEL = "gpt-4o-mini"
$env:DEEPSEEK_BASE_URL = "http://127.0.0.1:11434"
$env:DEEPSEEK_MODEL = "deepseek-r1:8b"
& 'C:\xampp\php\php.exe' .\test_pipeline.php
& 'C:\xampp\php\php.exe' -d extension=gd -S 127.0.0.1:8080 -t .
```

On **macOS/Linux**, run from a single terminal (with `ollama serve` running separately):
```bash
cd davao_food_finder
composer install
export OPENAI_API_KEY="sk-proj-YOUR_OPENAI_API_KEY_HERE"
export OPENAI_MODEL="gpt-4o-mini"
export DEEPSEEK_BASE_URL="http://127.0.0.1:11434"
export DEEPSEEK_MODEL="deepseek-r1:8b"
php test_pipeline.php
php -S 127.0.0.1:8080 -t .
```

## What works
- Restaurant discovery starts with optional area and per-person budget filters, one-tap dining prompts, or a free-text request. Known ambiguous terms such as “green”, “24”, “Tokyo”, and “matcha” trigger a short choice prompt before search; multi-turn follow-ups such as “show me more”, “what about Japanese food?”, or “similar but cheaper” remain supported.
- **Near me (within 5 km)** is opt-in and requests browser location only after the user selects it. Coordinates are sent to this app's API for distance calculation, never to Photon or the web-search query, and are not written to the evidence cache. Photon searches the requested place plus broad restaurant, cafe, fast-food, and food-court categories independently of the local dataset; coffee searches also look up Starbucks locations because broad cafe searches omit them. Matching mapped venues do not need a local dataset record. Distance filtering happens on this app's server; branch cards use OSM district/street details to distinguish locations. Local dataset ratings are shown only for confidently matched branches. Only Davao City food venues inside the radius are shown. Distances are approximate straight-line estimates; HTTPS (or localhost) and browser location permission are required.
- Result cards explain visible matches, link to sources when available, label local records as coming from the 2025 dataset, and distinguish a reported price within budget from a price that could not be verified. Category-colored icons replace illustrative photos and are based on each place's listed type. Search summaries show the live-evidence cache timestamp/age when available, the automatic criteria-review status, and response time. Cached web details and older dataset records may be stale; confirm prices, hours, and business status directly.
- **5-Stage Hybrid AI & Web Pipeline:** (1) `GPT-4o mini` interprets user intent & plans the web search query → (2) `Playwright` searches general, branch/location, and food-specific review queries across Brave, DuckDuckGo, and Bing; near-me searches also query broad Photon/OpenStreetMap food-place categories independently of the local dataset (cached for 12h in `data/.web-cache`) → (3) `GPT-4o mini` structures every supported online venue into JSON, including venues absent from the dataset → (4) `DeepSeek R1` reasons over constraints and review sentiment, prioritizes relevant online finds, and uses the 2025 local dataset only for supplementary options → (5) `GPT-4o mini` delivers the grounded final reply with source links alongside any relevant local dataset cards. Review mentions can surface restaurants whose names do not contain the requested food; places supported only by negative or unclear food-specific reviews are not presented as positive matches. Place listings include links and OpenStreetMap contributor attribution.
- Recommendations are limited to Davao City. Requests for another location receive a brief clarification instead of AI or dataset recommendations; online recommendations are only displayed when their city is verified as Davao City.
- The app defaults to `gpt-4o-mini` for OpenAI stages and `deepseek-r1:8b` (via local Ollama) for reasoning. OpenAI stages incur standard token API charges, while local Ollama inference is free.
- The page footer displays the active pipeline and updates after each successful AI response to confirm the exact models executed (`gpt-4o-mini+deepseek-r1:8b`).
- Results remain grouped under the query that produced them. Straightforward restaurant requests show results without repetitive canned replies; OpenAI can respond conversationally when an answer is useful.
- CSVs are scanned in a streaming manner; imported place records are cached in `data/.places-cache.json` to avoid re-reading large files every query. The cache is rebuilt when dataset file names, sizes, or modified times change. To force a rebuild, delete that cache file.
- Dataset city filtering requires the detected `City` field to contain `Davao City`. Other Davao Region cities are excluded.
- Restaurant details show dataset ratings/reviewer counts and a Google Maps search or verified OpenStreetMap place link. Price and address details show “not available” if the dataset doesn't contain them.
- If OpenAI or web search is unconfigured or unavailable, relevant local matching is still shown as a fallback.

Specific named-dish searches, such as Bulcachong or Balbacua (also spelled “balbakwa”), require local records to mention that dish and are not padded with unrelated popular chains. Unresolved ambiguous searches no longer fall back to unrelated local recommendations. Broader requests such as chicken or burgers can still include chains whose category matches the request.

## Hyperparameter & Agentic Research Evaluation (`v3.0`)

See [`evaluation/RESEARCH_PROTOCOL.md`](evaluation/RESEARCH_PROTOCOL.md) for full mathematical metric definitions, alignment with the four study objectives (`Volatility Resilience`, `Categorical/Lexical Disambiguation`, `Complex Intent Efficacy`, and `Numeric Price Ceilings + Autonomous Iterative Loop + Usability SUS`), statistical testing formulas, and human study protocols.

### Benchmark Datasets (`evaluation/ground_truth.json` & `evaluation/volatility_cases.json`)
- **Primary Retrieval, Disambiguation, & Price-Ceiling Benchmark (`evaluation/ground_truth.json`):** Contains **72 test cases** (**62 in-scope single-turn, disambiguation, numeric peso price-ceiling, & multi-turn cases** + **10 out-of-scope boundary checks** across neighboring Davao Region cities like Tagum, Panabo, Digos, Samal, and Mati plus national cities like Cebu and Manila), partitioned into `train` (12), `dev` (15), and held-out `test` (45) splits, with formal `disambiguation_schema` subtypes (`lexical_polysemy`, `category_confusion`, `spelling_synonym`, `mixed_intent`, `anaphoric_followup`, `underspecified_ambiguous`).
- **Controlled Data Volatility Benchmark (`evaluation/volatility_cases.json`):** Contains **15 paired Davao City temporal/counterfactual scenarios** testing permanent/temporary closures, menu price hikes above user ceilings, shortened operating hours, rating drops, and stale 12h cache hits vs. fresh web snapshots.

Rebuild or verify dataset labels anytime with:

```powershell
& 'C:\xampp\php\php.exe' .\evaluation\build_ground_truth.php
```

Interactive research portals (when running `php -S 127.0.0.1:8080 -t .`):
- **Evaluation & Volatility Dashboard:** [http://127.0.0.1:8080/evaluation/dashboard.php](http://127.0.0.1:8080/evaluation/dashboard.php)
- **Blind Human Annotation & Kappa Tool:** [http://127.0.0.1:8080/evaluation/annotate.php](http://127.0.0.1:8080/evaluation/annotate.php)
- **Human Usability & 10-Item SUS Portal:** [http://127.0.0.1:8080/evaluation/usability.php](http://127.0.0.1:8080/evaluation/usability.php)

### Running Controlled System Comparisons, Ablations, & Volatility Experiments
The CLI evaluation runner (`evaluation/run_evaluation.php`) supports seven controlled conditions:
- `baseline`: Deterministic lexical local retrieval + scope guard (no synonym normalization, no anti-polysemy guard, no iterative loop)
- `ablation-no-loop`: Single-pass retrieval without Pass 2 autonomous critique/re-retrieval
- `agentic-local`: Deterministic local-and-evidence grounded agentic engine with 2-pass Autonomous Iterative Critique-and-Refinement Loop
- `non-agentic`: Single-pass LLM over raw lexical shortlist without Playwright live web search, query reformulation, or iterative loop
- `deepseek`: Agentic intent parsing + review-enriched retrieval + Playwright volatility reconciliation + DeepSeek R1 + Pass 2 autonomous loop
- `gpt-4o-mini`: Agentic intent parsing + review-enriched retrieval + Playwright volatility reconciliation + GPT-4o mini + Pass 2 autonomous loop
- `hybrid`: Full 5-stage agentic pipeline (GPT-4o mini query planner → Playwright active web scraper → GPT-4o mini raw evidence structurer → DeepSeek-R1 reasoner & curator → GPT-4o mini final user reply → Pass 2 autonomous critique & repair)

```powershell
Set-Location 'C:\Users\cyber\OneDrive\Documents\Thesis20261028-main\davao_food_finder'

# Run the automated 52-assertion unit & integration test suite:
& 'C:\xampp\php\php.exe' .\evaluation\test_evaluation_suite.php

# Run Objective 1 Controlled Data Volatility Experiment (Static CSV vs Stale 12h Cache vs Ablation vs Agentic Loop):
& 'C:\xampp\php\php.exe' .\evaluation\run_volatility_experiment.php --local-cli

# Run Controlled 3-System Ablation & Agentic Loop Benchmark on held-out test split (45 cases x 3 trials):
& 'C:\xampp\php\php.exe' .\evaluation\run_evaluation.php --local-ablation --split=test --trials=3 --seed=42

# Run Full Live LLM Multi-System Comparison (when PHP server + Ollama / OPENAI_API_KEY are active):
& 'C:\xampp\php\php.exe' .\evaluation\run_evaluation.php --research --split=test --trials=3 --seed=42
```

### Reported Metrics, Latency Decomposition, & Reproducibility
- **Ranking & Intent Matching (Combined Web+Local, Local-only, and Web-only):** `Precision@5`, `Recall@5`, `Recall@10`, graded `NDCG@5`, and `MRR`, each reported with seeded 95% bootstrap confidence intervals.
- **Constraint, Scope, Dialogue, & Grounding Validation:** Multi-constraint adherence rate, Davao-only scope rejection accuracy, multi-turn follow-up resolution rate, factual correctness against CSV ground truth, evidence faithfulness/source support, repeat-run Jaccard consistency, error rate, and estimated API cost per query. Qualitative error counts track duplicate results, wrong locations, missing sources, fabricated details, and unsupported claims.
- **Component & Cache-State Latency:** Separate timings for Dataset Load + Ranking (`dataset_total_ms`), Playwright Crawl (`playwright_ms`), and AI Inference (`ai_inference_ms`), plus end-to-end `Mean`, `P50 (Median)`, `P95`, and `P99` stratified by cold-cache (`miss`) vs. warm-cache (`hit`) using `--cache-policy=default|cold|warm|none`.
- **Statistical Significance:** Tie-corrected **Friedman omnibus tests** across configurations (`Q = [12 / (n k (k + 1)) * sum(R_j^2) - 3n(k + 1)] / C`), followed by **pairwise two-sided Wilcoxon signed-rank post-hoc tests** with **Holm-Bonferroni step-down adjusted p-values** and effect size `r`.
- **Reproducibility Artifacts:** Every run writes a full JSON manifest (dataset & ground-truth SHA-256 hashes, PHP/OS version, seed, cache policy, timestamps, per-trial outputs) and CSV summary table to `evaluation/results/`.

## Notes
- DeepSeek and GPT-4o mini are benchmarked with the same PHP API, Playwright evidence cache, temperatures, prompt styles, and ground-truth labels. DeepSeek defaults to local Ollama model `deepseek-r1:8b`; GPT-4o mini uses `OPENAI_API_KEY` and defaults to hosted `gpt-4o-mini`.
- Put restaurant and review datasets in `data/`. CSV files are read directly; Excel files (`.xlsx`/`.xls`) require the PHP extensions listed above. The included `ph_restos_2025.csv` and `ph_reviews_2025.csv` files are already in `data/`.
- The review CSV is aggregated by `StoreId` and can take a little time on the first request. After the cache is built, normal requests should be faster.
- If you have multiple copies of the same restaurant/review dataset in `data/`, review counts can be double-counted. Prefer keeping one current version of each dataset.
- Web information is generated from live search and displayed with source links. Local result cards remain grounded in the supplied dataset; the app does not fabricate missing prices, addresses, or review scores.
- For numeric price ceilings, the agentic iterative flow runs targeted menu/price searches for up to three relevant local candidates without a verified price, then uses matching web evidence in the existing ceiling filter. Verified prices above the ceiling are excluded and the price source is linked on the result; places whose prices remain unknown may still appear as **Not verified**. Web menu prices are indicative and should be confirmed with the restaurant.
