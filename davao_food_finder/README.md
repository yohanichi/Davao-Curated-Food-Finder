# Davao Food Finder

A small server-rendered site using HTML, CSS, JavaScript, PHP, paid DeepSeek Flash, GPT-4o mini, and Playwright. The hybrid pipeline is: **User input → GPT-4o mini query planning & intent extraction → Playwright active web scraping → GPT-4o mini structuring of raw Playwright output → DeepSeek Flash reasoning & curation → GPT-4o mini final user reply**. PHP handles the UI/API and 2025 local dataset, while the Playwright cache limits repeated scans to 12 hours.

## Requirements
- PHP 8.1 or newer with the `curl` and `mbstring` extensions enabled (e.g., XAMPP `C:\xampp\php\php.exe`)
- Composer, to install the PHP dependencies
- Node.js and Playwright, to actively scrape current public web pages
- Optional Brave Search API key (`BRAVE_SEARCH_API_KEY`) for supported candidate-URL discovery; Playwright still fetches and verifies the source pages
- An OpenAI API key (`OPENAI_API_KEY`) for **GPT-4o mini** (Stages 1, 3, and 5)
- A paid DeepSeek API key (`DEEPSEEK_API_KEY`) for **DeepSeek Flash** (Stage 4); local Ollama R1 is optional and must be explicitly selected
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

# 3. Optional: download the local DeepSeek R1 model for explicit local testing:
ollama pull deepseek-r1:8b
```

---

### Part B: Starting the App (Step-by-Step)

> **Important:** Set environment variables (`OPENAI_API_KEY`, `OPENAI_MODEL`, etc.) and start the PHP server in the **exact same PowerShell window** so the PHP server inherits them.

#### Step 1 — Optional local Ollama backend
The default pipeline uses the paid DeepSeek Flash API; Ollama is not needed unless you explicitly select `--deepseek-backend=local-ollama`.

```powershell
# Check installed models:
ollama list

# Start the Ollama local server (listens on http://127.0.0.1:11434):
ollama serve
```
- **Note if you see `bind: Only one usage of each socket address ... is normally permitted`:** That means Ollama is **already running** in the background (check the llama icon in your Windows system tray). You do not need to run `ollama serve` again—just leave Ollama running.

#### Step 2 — Configure API keys and start the app
Open a PowerShell window and keep it open for Steps 2–4:

```powershell
# 1. Go to the davao_food_finder directory:
Set-Location 'C:\Users\cyber\OneDrive\Documents\Thesis20261028-main\davao_food_finder'

# 2. Set your OpenAI API key (keep on server only; never commit to git):
$env:OPENAI_API_KEY = "sk-proj-YOUR_OPENAI_API_KEY_HERE"

# 3. Set the OpenAI model (defaults to gpt-4o-mini if omitted):
$env:OPENAI_MODEL = "gpt-4o-mini"

# 4. Set the paid DeepSeek API key and model:
$env:DEEPSEEK_API_KEY = "YOUR_DEEPSEEK_API_KEY_HERE"
$env:DEEPSEEK_API_MODEL = "deepseek-flash"

# 5. Optional: use Brave Search API for URL discovery (Playwright still fetches the source pages):
$env:BRAVE_SEARCH_API_KEY = "YOUR_BRAVE_SEARCH_API_KEY_HERE"
```
The DeepSeek API key is required. The app fails explicitly if it is missing rather than falling back to Ollama. `deepseek-flash` is the default paid model; `DEEPSEEK_API_MODEL` can select another supported DeepSeek API model.
The Brave Search API key is optional and may incur charges under Brave's current plan. Without it, the scraper makes at most one Brave browser-search attempt per request and continues discovery through Bing and DuckDuckGo. A Brave `429` is not retried; its `Retry-After` value is saved as a local cooldown. Brave Search API results only identify candidate URLs; a result is included as live evidence only after Playwright fetches its source page.

#### Step 3 — Probe Provider and Scraper Readiness (Same Terminal 2)
Still inside the configured PowerShell window, run the independent component probes for GPT-4o mini, Playwright, and paid DeepSeek Flash:

```powershell
& 'C:\xampp\php\php.exe' .\test_pipeline.php
```
These are separate health probes, not proof that all five stages succeeded in the same request. For request-level stage verification, use `evaluation\run_volatility_experiment.php --live-provider=hybrid`.

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
You can paste this block into PowerShell to configure the paid APIs and start the app:

```powershell
Set-Location 'C:\Users\cyber\OneDrive\Documents\Thesis20261028-main\davao_food_finder'
$env:OPENAI_API_KEY = "sk-proj-YOUR_OPENAI_API_KEY_HERE"
$env:OPENAI_MODEL = "gpt-4o-mini"
$env:DEEPSEEK_API_KEY = "YOUR_DEEPSEEK_API_KEY_HERE"
$env:DEEPSEEK_API_MODEL = "deepseek-flash"
& 'C:\xampp\php\php.exe' .\test_pipeline.php
& 'C:\xampp\php\php.exe' -d extension=gd -S 127.0.0.1:8080 -t .
```

On **macOS/Linux**, run from a single terminal:
```bash
cd davao_food_finder
composer install
export OPENAI_API_KEY="sk-proj-YOUR_OPENAI_API_KEY_HERE"
export OPENAI_MODEL="gpt-4o-mini"
export DEEPSEEK_API_KEY="YOUR_DEEPSEEK_API_KEY_HERE"
export DEEPSEEK_API_MODEL="deepseek-flash"
php test_pipeline.php
php -S 127.0.0.1:8080 -t .
```

## What works
- Restaurant discovery starts with optional area and per-person budget filters, one-tap dining prompts, or a free-text request. Known ambiguous terms such as “green”, “24”, “Tokyo”, and “matcha” trigger a short choice prompt before search; multi-turn follow-ups such as “show me more”, “what about Japanese food?”, or “similar but cheaper” remain supported.
- **Near me (within 5 km)** is opt-in and requests browser location only after the user selects it. Exact coordinates are sent to this app's API for distance calculation and are not written to the evidence cache or sent to map providers. To avoid rate-limiting from concurrent duplicate map requests, Photon makes one targeted lookup using the requested category or brand (or the user query when no category is available). If fewer than eight nearby places are found, a rounded bounding box around the 5 km search area may be sent to OpenStreetMap Nominatim for additional map locations. Exact distance filtering still happens on this app's server. Matching mapped venues do not need a local dataset record. Branch cards use OSM district/street details to distinguish locations; when OSM has no street or district, the card shows that branch's map-pin coordinates instead of implying a street address. Local dataset ratings are shown only for confidently matched branches. Only Davao City food venues inside the radius are shown. When more than 20 nearby results are available, **Load more** reveals additional OpenStreetMap-mapped places in batches of 20 without repeating the live web search; these added places are not live-web verified. Distances are approximate straight-line estimates; HTTPS (or localhost) and browser location permission are required.
- **Map Finds (regular searches):** Category-specific, non-location searches may include up to 20 additional matches from the local Davao OpenStreetMap snapshot. These are shown separately from live-web and 2025-dataset results, only when map tags support the requested cuisine/dish and any requested area. Searches depending on price, ratings, hours, ambiance, or themes do not receive map-only matches because the map snapshot cannot verify those constraints. Map finds carry no inferred ratings, reviews, or prices and are not sent to the reasoning/evaluation pipeline; their snapshot date and count are reported separately.
- **Known brand branches:** An explicitly recognized brand-only search can supplement live findings with up to 100 matching locations from the local OpenStreetMap snapshot. These are shown in a separate “Known branches from OpenStreetMap” section, not represented as live-web verified, and carry no inferred ratings, reviews, prices, hours, or current-status claims. Coverage depends on the snapshot and can be incomplete.
- **Public recommendation discovery:** Live search also uses bounded food-blog and public community-post queries. Facebook, TikTok, Reddit, and Instagram pages are not opened by the scraper; matching public search-result snippets may be used as leads and are labeled “Public post snippet.” Results from the food-blog query are labeled as blog-search results, not independently verified as articles. Private content and logged-in accounts are not scraped.
- Result cards explain visible matches, link to sources when available, label local records as coming from the 2025 dataset, and distinguish a reported price within budget from a price that could not be verified. Category-colored icons replace illustrative photos and are based on each place's listed type. Search summaries show the live-evidence cache timestamp/age when available, the automatic criteria-review status, and response time. Cached web details and older dataset records may be stale; confirm prices, hours, and business status directly.
- **5-Stage Hybrid AI & Web Pipeline:** (1) `GPT-4o mini` interprets user intent & plans the web search query → (2) candidate URLs come from the Brave Search API when `BRAVE_SEARCH_API_KEY` is configured, or from browser-based Brave/Bing and HTTP Bing/DuckDuckGo discovery otherwise. Brave browser search is limited to one query per request; `429` responses are not retried, and the provider's `Retry-After` value is respected across requests. `Playwright` fetches candidate source pages; API search snippets alone never count as live evidence. Near-me searches make one targeted Photon/OpenStreetMap lookup independently of the local dataset. Photon HTTP and Chromium attempts run sequentially rather than concurrently to reduce duplicate requests and rate limits. When Photon returns fewer than eight nearby places, one bounded Nominatim lookup may supply additional mapped venue metadata; regular searches may also use one Nominatim lookup if Photon is unavailable. Nominatim results are cached for 12h in `data/.web-cache`, and requests are limited to one per second application-wide. The Nominatim endpoint can be changed with `NOMINATIM_BASE_URL`; use is subject to the [Nominatim Usage Policy](https://operations.osmfoundation.org/policies/nominatim/). Nominatim supplies mapped venue metadata only, not reviews or search snippets → (3) `GPT-4o mini` structures every supported online venue into JSON, including venues absent from the dataset → (4) paid `DeepSeek Flash` reasons over constraints and review sentiment, prioritizes relevant online finds, and uses the 2025 local dataset only for supplementary options → (5) `GPT-4o mini` delivers the grounded final reply with source links alongside any relevant local dataset cards. Review mentions can surface restaurants whose names do not contain the requested food; places supported only by negative or unclear food-specific reviews are not presented as positive matches. Place listings include links and OpenStreetMap contributor attribution.
- **Degraded-mode behavior:** If the hybrid live pipeline fails during an ordinary interactive search, the app may show up to five clearly labeled local-dataset matches instead of an empty answer. Those matches are not presented as live-verified. Near-me searches still require verifiable map coordinates and never substitute unlocated local records; evaluation requests remain strict and record the pipeline failure.
- **Reasoner parse recovery:** The hosted DeepSeek reasoner uses a 4,096-token output budget and concise structured fields. If the response is malformed or truncated (`finish_reason=length`), the hybrid pipeline makes one zero-temperature JSON-only retry, increasing the retry budget to 8,192 tokens for truncation. If that also fails, it reports the finish reason and response length without logging the generated response content.
- Recommendations are limited to Davao City. Requests for another location receive a brief clarification instead of AI or dataset recommendations; online recommendations are only displayed when their city is verified as Davao City.
- The app defaults to `gpt-4o-mini` for OpenAI stages and the paid `deepseek-flash` API for reasoning. Both providers incur token API charges; DeepSeek Flash cost metrics use conservative peak-rate estimates.
- Local Ollama `deepseek-r1:8b` remains available only when explicitly selected with `--deepseek-backend=local-ollama`; it is not an automatic fallback.
- The page footer displays the active pipeline and updates after each successful AI response to confirm the exact models actually executed.
- Results remain grouped under the query that produced them. Straightforward restaurant requests show results without repetitive canned replies; OpenAI can respond conversationally when an answer is useful.
- CSVs are scanned in a streaming manner; imported place records are cached in `data/.places-cache.json` to avoid re-reading large files every query. The cache is rebuilt when dataset file names, sizes, or modified times change. To force a rebuild, delete that cache file.
- Dataset city filtering requires the detected `City` field to contain `Davao City`. Other Davao Region cities are excluded.
- Restaurant details show dataset ratings/reviewer counts and a Google Maps search or verified OpenStreetMap place link. Price and address details show “not available” if the dataset doesn't contain them.
- If OpenAI or web search is unconfigured or unavailable, relevant local matching is still shown as a fallback.

Specific menu-item searches, such as fries, require local records to mention the item in the place type, name, description, or review evidence; their Playwright query also includes relevant menu terms and brands such as Potato Corner. Mixed searches such as “fries and burgers” do not apply a single-item-only filter. Specific named-dish searches, such as Bulcachong or Balbacua (also spelled “balbakwa”), likewise require relevant evidence and are not padded with unrelated popular chains. Unresolved ambiguous searches no longer fall back to unrelated local recommendations. Broader requests such as chicken or burgers can include chains whose category matches the request.

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
- `deepseek`: Agentic intent parsing + review-enriched retrieval + Playwright volatility reconciliation + paid DeepSeek Flash + Pass 2 autonomous loop
- `gpt-4o-mini`: Agentic intent parsing + review-enriched retrieval + Playwright volatility reconciliation + GPT-4o mini + Pass 2 autonomous loop
- `hybrid`: Full 5-stage agentic pipeline (GPT-4o mini query planner → Playwright active web scraper → GPT-4o mini raw evidence structurer → paid DeepSeek Flash reasoner & curator → GPT-4o mini final user reply → Pass 2 autonomous critique & repair)

```powershell
Set-Location 'C:\Users\cyber\OneDrive\Documents\Thesis20261028-main\davao_food_finder'

# Run the automated 52-assertion unit & integration test suite:
& 'C:\xampp\php\php.exe' .\evaluation\test_evaluation_suite.php

# Ask for live requests (interactive until an empty line); hybrid is the default:
& 'C:\xampp\php\php.exe' .\evaluation\run_volatility_experiment.php --local-cli --live-provider=hybrid

# Exercise the live DeepSeek agentic route with Playwright retrieval:
& 'C:\xampp\php\php.exe' .\evaluation\run_volatility_experiment.php --local-cli --live-provider=deepseek

# Exercise the live GPT-4o mini agentic route with Playwright retrieval:
& 'C:\xampp\php\php.exe' .\evaluation\run_volatility_experiment.php --local-cli --live-provider=gpt-4o-mini

# Run the 15 controlled volatility scenarios only when explicitly needed:
& 'C:\xampp\php\php.exe' .\evaluation\run_volatility_experiment.php --controlled-scenarios --local-cli

# Run Controlled 3-System Ablation & Agentic Loop Benchmark on held-out test split (45 cases x 3 trials):
& 'C:\xampp\php\php.exe' .\evaluation\run_evaluation.php --local-ablation --split=test --trials=3 --seed=42

# Run Full Live LLM Multi-System Comparison (when PHP server + DEEPSEEK_API_KEY + OPENAI_API_KEY are active):
& 'C:\xampp\php\php.exe' .\evaluation\run_evaluation.php --research --split=test --trials=3 --seed=42

# Start a local API server and verify the fixed real-request set using paid DeepSeek:
& 'C:\xampp\php\php.exe' .\evaluation\run_live_pipeline_verification.php --start-local-server --port=8080 --deepseek-backend=local-ollama

# Or select hosted DeepSeek (DEEPSEEK_API_KEY must already be set in this shell):
& 'C:\xampp\php\php.exe' .\evaluation\run_live_pipeline_verification.php --start-local-server --port=8080 --deepseek-backend=hosted-api

# Alternatively use an already-running API server with automatic backend selection:
& 'C:\xampp\php\php.exe' .\evaluation\run_live_pipeline_verification.php --api-url=http://127.0.0.1:8080/api.php

# Run the focused report/acceptance tests:
& 'C:\xampp\php\php.exe' .\evaluation\test_live_pipeline_verification.php
```

The verification runner makes direct POST requests to `api.php` using the `hybrid` provider, agentic refinement, the autonomous loop, and Playwright cache bypass. `--start-local-server` starts a temporary local PHP server inheriting the current shell environment; `--deepseek-backend=local-ollama|hosted-api` selects the backend without putting keys on the command line. Set `OPENAI_API_KEY` and `DEEPSEEK_API_KEY` in that shell. Hosted DeepSeek Flash is the default; local Ollama is opt-in and requires its model and service. It writes timestamped JSON and CSV reports to `evaluation/results/`. Failed requests remain in the report; a full-pipeline success requires all five observed stages, the requested backend to match the actually observed backend, a live Playwright cache miss with evidence and source URLs, and a usable final response. The final fixed request uses an intentionally extreme ₱1/person ceiling to measure loop violation detection/repair; detection is reported as observed and is not assumed. These options enable a correctly configured live run but cannot guarantee 4/4 if a provider, search engine, or API call fails.

`run_volatility_experiment.php` defaults to interactive live requests instead of replaying the 15 controlled scenarios. `--live-provider=hybrid` verifies all five stages; `deepseek` and `gpt-4o-mini` exercise their respective live provider routes. Hybrid retrieval requires a real Playwright Chromium search/page result, not only an HTTP search snippet or cache hit. The final GPT response is called only after usable DeepSeek reasoning; if an earlier stage fails, the request returns an explicit incomplete-pipeline error instead of a GPT-only answer. The paid API is the default and uses `deepseek-flash`; set `DEEPSEEK_API_KEY` before running. Use `--deepseek-backend=local-ollama` only to explicitly select local R1 8B. The verifier records browser execution and checks the model/backend actually returned. Enter an empty line to finish and write the timestamped JSON/CSV report. Use `--controlled-scenarios` to explicitly run the old `volatility_cases.json` benchmark; it cannot be combined with `--live-provider` or `--deepseek-backend`.

### Reported Metrics, Latency Decomposition, & Reproducibility
- **Ranking & Intent Matching (Combined Web+Local, Local-only, and Web-only):** `Precision@5`, `Recall@5`, `Recall@10`, graded `NDCG@5`, and `MRR`, each reported with seeded 95% bootstrap confidence intervals.
- **Constraint, Scope, Dialogue, & Grounding Validation:** Multi-constraint adherence rate, Davao-only scope rejection accuracy, multi-turn follow-up resolution rate, factual correctness against CSV ground truth, evidence faithfulness/source support, repeat-run Jaccard consistency, error rate, and estimated API cost per query. Qualitative error counts track duplicate results, wrong locations, missing sources, fabricated details, and unsupported claims.
- **Component & Cache-State Latency:** Separate timings for Dataset Load + Ranking (`dataset_total_ms`), Playwright Crawl (`playwright_ms`), and AI Inference (`ai_inference_ms`), plus end-to-end `Mean`, `P50 (Median)`, `P95`, and `P99` stratified by cold-cache (`miss`) vs. warm-cache (`hit`) using `--cache-policy=default|cold|warm|none`.
- **Statistical Significance:** Tie-corrected **Friedman omnibus tests** across configurations (`Q = [12 / (n k (k + 1)) * sum(R_j^2) - 3n(k + 1)] / C`), followed by **pairwise two-sided Wilcoxon signed-rank post-hoc tests** with **Holm-Bonferroni step-down adjusted p-values** and effect size `r`.
- **Reproducibility Artifacts:** Every run writes a full JSON manifest (dataset & ground-truth SHA-256 hashes, PHP/OS version, seed, cache policy, timestamps, per-trial outputs) and CSV summary table to `evaluation/results/`.

## Notes
- DeepSeek and GPT-4o mini are benchmarked with the same PHP API, Playwright evidence cache, temperatures, prompt styles, and ground-truth labels. DeepSeek defaults to paid API model `deepseek-flash`; GPT-4o mini uses `OPENAI_API_KEY` and defaults to hosted `gpt-4o-mini`.
- Put restaurant and review datasets in `data/`. CSV files are read directly; Excel files (`.xlsx`/`.xls`) require the PHP extensions listed above. The included `ph_restos_2025.csv` and `ph_reviews_2025.csv` files are already in `data/`.
- The review CSV is aggregated by `StoreId` and can take a little time on the first request. After the cache is built, normal requests should be faster.
- If you have multiple copies of the same restaurant/review dataset in `data/`, review counts can be double-counted. Prefer keeping one current version of each dataset.
- Web information is generated from live search and displayed with source links. Local result cards remain grounded in the supplied dataset; the app does not fabricate missing prices, addresses, or review scores.
- For numeric price ceilings, the agentic iterative flow runs targeted menu/price searches for up to three relevant local candidates without a verified price, then uses matching web evidence in the existing ceiling filter. Verified prices above the ceiling are excluded and the price source is linked on the result; places whose prices remain unknown may still appear as **Not verified**. Web menu prices are indicative and should be confirmed with the restaurant.
