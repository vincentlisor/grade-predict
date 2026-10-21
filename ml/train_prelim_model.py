
from pathlib import Path
import json
from datetime import datetime

import pandas as pd
from sklearn.linear_model import LinearRegression
from sklearn.model_selection import train_test_split
from sklearn.metrics import mean_absolute_error, r2_score

ROOT = Path(__file__).resolve().parent
DATA_PATH = ROOT / "dummy_historical_gwa_training.csv"
MODEL_PATH = ROOT / "prelim_model.json"
METRICS_PATH = ROOT / "prelim_model_metrics.json"

if not DATA_PATH.exists():
    raise FileNotFoundError(f"Dataset not found: {DATA_PATH}")

df = pd.read_csv(DATA_PATH)

required = ["prelim_gwa", "actual_final_gwa"]
if not all(col in df.columns for col in required):
    raise ValueError(f"Dataset must contain columns: {required}")

df = df[required].dropna()

X = df[["prelim_gwa"]]
y = df["actual_final_gwa"]

X_train, X_test, y_train, y_test = train_test_split(
    X, y, test_size=0.20, random_state=42
)

model = LinearRegression()
model.fit(X_train, y_train)

predictions = model.predict(X_test)

model_data = {
    "model": "Linear Regression",
    "stage": "Prelim-only",
    "features": ["prelim_gwa"],
    "target": "actual_final_gwa",
    "intercept": float(model.intercept_),
    "coefficients": {
        "prelim_gwa": float(model.coef_[0])
    },
    "gwa_min": 1.00,
    "gwa_max": 5.00,
    "synthetic_data": True,
    "trained_at": datetime.now().isoformat(timespec="seconds")
}

metrics = {
    "model": "Linear Regression",
    "stage": "Prelim-only",
    "sample_size": int(len(df)),
    "train_size": int(len(X_train)),
    "test_size": int(len(X_test)),
    "mae": round(float(mean_absolute_error(y_test, predictions)), 4),
    "r2": round(float(r2_score(y_test, predictions)), 4),
    "data_source": DATA_PATH.name,
    "synthetic_data": True,
    "trained_at": model_data["trained_at"]
}

MODEL_PATH.write_text(json.dumps(model_data, indent=2), encoding="utf-8")
METRICS_PATH.write_text(json.dumps(metrics, indent=2), encoding="utf-8")

print(json.dumps(metrics, indent=2))
print(f"\nModel saved to: {MODEL_PATH}")
print(f"Metrics saved to: {METRICS_PATH}")
print("\nWARNING: Synthetic-data metrics are for pipeline testing only.")
