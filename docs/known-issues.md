6. Execution & Debugging So Far
   • Registry imports are being executed in time‑boxed, chunked runs (prefix limits, chunk sizes).
   • A duplicate primary key error was encountered when inserting into registry_vehicles_new.

This indicates that:
• The same vehicle_key can be generated more than once
• Possible causes include:
• duplicate source rows for the same VRM within a snapshot
• shard overlap
• insufficient uniqueness in key composition

This is a known blocker to resolve before full‑scale reliable imports.
