# Milestone 4: Experimental Evaluation & Empirical Results

> **Generated Automatically**: 2026-10-31 10:54:30 UTC from `latest_run.json`
> **Evaluator Version**: 3.0.0 | **PHP**: 8.2.12 | **OS**: Windows

## 1. Experimental Setup & Reproducibility Manifest

| Parameter | Value |
| :--- | :--- |
| **Benchmark Split** | `test` |
| **In-Scope Retrieval Cases** | 39 |
| **Out-of-Scope Safety Cases** | 6 |
| **Trials per Configuration** | 1 |
| **PRNG Seed** | `42` |
| **Cache Policy** | `default` |
| **API Endpoint** | `local` |
| **Ground Truth Version** | `3.0.0` (`479d9432b824...`) |

## 2. Table 1: Ranking & Intent Matching Metrics

Combined Web + Local retrieval metrics with 95% deterministic bootstrap confidence intervals (n=39).

| Config ID | P@5 [95% CI] | NDCG@5 [95% CI] | MRR | R@5 [95% CI] | R@10 | Local P@5 | Web P@5 |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **DEEPSEEK-API-T0.2-STRICT** | 0.0% [0.0, 0.0] | 0.0% [0.0, 0.0] | 0.000 | 0.0% [0.0, 0.0] | 0.0% | 0.0% | N/A |

## 3. Table 2: Constraint Adherence, Safety, Faithfulness & Reliability

| Config ID | Constr Item% | Scope Acc% | Follow-up% | Faithful% | Fact Corr% | Consistency | Error Rate | Cost/Query |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **DEEPSEEK-API-T0.2-STRICT** | 0.0% | 0.0% | 0.0% | 0.0% | 0.0% | N/A (1x) | 100.0% | $0.000000 |

## 4. Table 3: Latency Decomposition & Cache Behavior

| Config ID | Mean (s) | P50 (s) | P95 (s) | P99 (s) | Cold P50 (s) | Warm P50 (s) | Dataset (ms) | PW (ms) | AI (ms) | Cache Hit/Miss |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **DEEPSEEK-API-T0.2-STRICT** | 0.12s | 0.12s | 0.14s | 0.15s | - | 0.12s | 21.8 | 0.8 | 0.7 | 38 / 0 |

## 5. Table 4: Qualitative Error & Grounding Audit Counts

| Config ID | Duplicates | Wrong Locs | Missing Src | Fabricated | Unsupported | Constr Fails | Scope Fails |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **DEEPSEEK-API-T0.2-STRICT** | 0 | 0 | 0 | 0 | 0 | 0 | 0 |

## 6. Table 5: Study Objectives Breakdown (Disambiguation, Price Ceilings, Iterative Loop)

| Config ID | Disambig Acc | Trap-Free% | PriceCeil Acc | Price NDCG@5 | Loop Init->Fin | Viol Repaired | Re-Retr% |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **DEEPSEEK-API-T0.2-STRICT** | 0.0% | 100.0% | 0.0% | 0.0% | disabled | - | - |

## 7. Error Reason Breakdown Per Provider

| Provider | Total Req | Total Err | Timeout | Ollama Unreach | Empty Resp | Invalid JSON | Schema Valid | PHP Except | HTTP Error |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **deepseek-api** | 45 | 45 | 0 | 0 | 0 | 0 | 0 | 0 | 45 |

## 9. Statistical Significance Analysis

## 10. Inter-Annotator Agreement

- **Source**: `rubric_criteria_audit` (n=80 overlapping items)
- **Cohen's Weighted Kappa**: `0.764` (substantial)
- **Fleiss' Kappa**: `0.664`

