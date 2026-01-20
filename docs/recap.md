Vinlytics – Project Recap

1. Product Idea

Vinlytics is evolving from a simple valuation checker into a price + risk intelligence platform for UK vehicles, with particular strength for auction and trade users.
• Baseline Vinlytics: quick valuation with lightweight AI commentary (free / low cost).
• Premium Vinlytics: valuation plus a Risk Profile driven by MOT history patterns, defect frequency, mileage/age context, and future risk indicators.
• Core positioning: “Don’t just check the price — check the risk.”

Commercial direction
• Free teaser (basic valuation / partial insight)
• One-off paid report (~£2.99–£3.99)
• Trade subscriptions (dealer / auction tiers ~£29–£199+)

⸻

2. Data & Pipeline Design

Primary goal so far: take large DVSA MOT JSON datasets and convert them into analytics-friendly, scalable Parquet datasets, then derive: 1. A vehicle registry (one row per VRM with aggregates) 2. Model-level insights (risk and fault patterns by make/model, age, mileage)

Core Parquet Outputs

All outputs are snapshot-based and shard-aware for scale.

Vehicles Parquet
• registration
• make / model
• fuel_type
• colour
• engine_cc
• first_used_date
• manufacture_date / manufacture_year
• last_mot_date
• shard, snapshot, source filename

Tests Parquet
• registration
• mot_test_number
• test_date
• test_result
• odometer_value / unit
• expiry_date
• regmark_time_of_test
• data_source
• shard, snapshot, source filename

Defects Parquet
• registration
• mot_test_number
• test_date / test_result
• odometer_value / unit
• expiry_date
• defect_text
• defect_type (dangerous / major / minor)
• dangerous flag
• shard, snapshot, source filename

These parquet files form the canonical analytics contract for the platform.

⸻

3. Repository / Filesystem Layout

The project now separates raw data, analytics outputs, and build logic clearly:
• /parquet/mot_flat/
• vehicles/
• tests/
• defects/
• /insights/
• /parquet/
• /csv/
• /scripts/
• shell orchestration scripts
• /scripts/sql/ containing DuckDB build SQL templates

This structure supports repeatable snapshot builds and future automation.

⸻

4. Coding Work Completed

A. Flattening DVSA MOT Data → Parquet

Shell scripts now:
• Read raw DVSA MOT JSON / NDJSON (including .gz)
• Flatten nested motTests and defect arrays
• Emit vehicles, tests, and defects parquet datasets
• Support:
• snapshot tagging
• sharding
• selective phase execution (vehicles/tests/defects)
• memory/thread tuning

This gives a reproducible, scalable ingestion layer.

⸻

B. Registry Build (Parquet → Registry Table)

DuckDB SQL builds a vehicle registry table by:
• Normalising VRMs (uppercase, strip spaces)
• Aggregating MOT tests:
• total tests
• pass / fail counts
• last test date and result
• last odometer reading
• Aggregating defects:
• total defects
• dangerous / major / minor counts
• Joining vehicle metadata
• Producing one row per vehicle

A placeholder risk_score_baseline column is currently populated with a static value, ready for future scoring logic.

⸻

C. Model-Level Insight Builds

A templated DuckDB SQL pipeline now produces model risk insights, including:
• Normalised risk summaries per make/model
• Top fault types by:
• vehicle age bands
• mileage bands
• age + mileage combinations

Outputs are written to Parquet (and optionally CSV) under /insights/, ready for:
• API consumption
• dashboarding
• downstream ML / LLM summarisation

⸻

5. Database Work (Application Side)

A MySQL development schema (vinlytic_dev) exists containing:
• vehicle records
• MOT tests and advisories
• valuation and risk-related tables

This schema represents the application runtime model, complementary to the parquet/DuckDB analytics layer.

⸻

6. Execution & Debugging So Far
   • Registry imports are being executed in time-boxed, chunked runs (prefix limits, chunk sizes).
   • A duplicate primary key error was encountered when inserting into registry_vehicles_new.

This indicates that:
• The same vehicle_key can be generated more than once
• Possible causes include:
• duplicate source rows for the same VRM within a snapshot
• shard overlap
• insufficient uniqueness in key composition

This is a known blocker to resolve before full-scale reliable imports.

⸻

7. Current State Summary
   • Product direction: clearly defined around valuation + risk intelligence
   • Pricing & positioning: freemium → paid report → trade subscription ladder
   • Data contracts: vehicles/tests/defects parquet schemas established
   • Build system: working flatten, registry, and insight pipelines in place
   • Known issue: registry deduplication / primary key strategy needs refinement

Overall, the project has moved from concept into a solid, scalable data foundation, with the next phase focused on data integrity fixes and first real risk-scoring logic.
