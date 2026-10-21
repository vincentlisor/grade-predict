
from pathlib import Path

import numpy as np
import pandas as pd

ROOT = Path(__file__).resolve().parent
OUTPUT_PATH = ROOT / "dummy_historical_gwa_training.csv"

# Reproducible synthetic data for pipeline testing only.
rng = np.random.default_rng(42)
ROWS = 1000

prelim_gwa = rng.uniform(1.00, 3.00, ROWS)
midterm_gwa = (
    0.65 * prelim_gwa
    + 0.35 * rng.uniform(1.00, 3.00, ROWS)
    + rng.normal(0, 0.12, ROWS)
)
midterm_gwa = np.clip(midterm_gwa, 1.00, 5.00)

actual_final_gwa = (
    0.40 * prelim_gwa
    + 0.60 * midterm_gwa
    + rng.normal(0, 0.15, ROWS)
)
actual_final_gwa = np.clip(actual_final_gwa, 1.00, 5.00)

# Round to typical two-decimal GWA values.
df = pd.DataFrame({
    "dummy_student_id": [f"DUMMY-{i:04d}" for i in range(1, ROWS + 1)],
    "prelim_gwa": np.round(prelim_gwa, 2),
    "midterm_gwa": np.round(midterm_gwa, 2),
    "actual_final_gwa": np.round(actual_final_gwa, 2),
})

df.to_csv(OUTPUT_PATH, index=False)

print(f"Generated {len(df)} synthetic records.")
print(f"Saved to: {OUTPUT_PATH}")
print("\nFirst 5 rows:")
print(df.head().to_string(index=False))
print("\nWARNING: Synthetic data only. Not evidence of real-world model accuracy.")
