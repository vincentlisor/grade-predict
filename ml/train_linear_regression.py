from __future__ import annotations

import json
from datetime import datetime
from pathlib import Path

import pandas as pd
from sklearn.linear_model import LinearRegression
from sklearn.metrics import mean_absolute_error, r2_score
from sklearn.model_selection import train_test_split

ROOT = Path(__file__).resolve().parents[1]
CSV_PATH = ROOT / "ml" / "historical_dummy_raw_scores.csv"
MODEL_PATH = ROOT / "ml" / "linear_model.json"
METRICS_PATH = ROOT / "ml" / "linear_model_metrics.json"
COMPONENTS = ["attendance", "recitation", "activities", "quizzes", "project", "exam"]


def main() -> None:
    if not CSV_PATH.exists():
        raise FileNotFoundError(f"Historical CSV not found: {CSV_PATH}")

    df = pd.read_csv(CSV_PATH)
    required = [f"{c}_{suffix}" for c in COMPONENTS for suffix in ("score", "total")] + ["actual_final_grade"]
    missing = [column for column in required if column not in df.columns]
    if missing:
        raise ValueError("CSV is missing columns: " + ", ".join(missing))

    for component in COMPONENTS:
        total = pd.to_numeric(df[f"{component}_total"], errors="coerce")
        score = pd.to_numeric(df[f"{component}_score"], errors="coerce")
        if (total <= 0).any():
            raise ValueError(f"{component}_total must be greater than zero for every record.")
        df[component] = score / total * 100

    X = df[COMPONENTS].apply(pd.to_numeric, errors="coerce")
    y = pd.to_numeric(df["actual_final_grade"], errors="coerce")
    clean = pd.concat([X, y.rename("target")], axis=1).dropna()
    if len(clean) < 20:
        raise ValueError(f"Need at least 20 complete historical records; found {len(clean)}.")

    X = clean[COMPONENTS]
    y = clean["target"]
    x_train, x_test, y_train, y_test = train_test_split(X, y, test_size=0.2, random_state=42)

    evaluation_model = LinearRegression()
    evaluation_model.fit(x_train, y_train)
    test_predictions = evaluation_model.predict(x_test)
    metrics = {
        "model": "Linear Regression",
        "mae": round(float(mean_absolute_error(y_test, test_predictions)), 4),
        "r2": round(float(r2_score(y_test, test_predictions)), 4),
        "sample_size": int(len(clean)),
        "train_size": int(len(x_train)),
        "test_size": int(len(x_test)),
        "trained_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "data_source": CSV_PATH.name,
        "note": "Synthetic dummy data for development/testing only; not validated school records."
    }

    # After evaluation, fit the production coefficients using all available rows.
    production_model = LinearRegression()
    production_model.fit(X, y)
    model_payload = {
        "model": "Linear Regression",
        "features": COMPONENTS,
        "intercept": float(production_model.intercept_),
        "coefficients": {name: float(value) for name, value in zip(COMPONENTS, production_model.coef_)},
        "trained_at": metrics["trained_at"],
        "sample_size": int(len(clean)),
        "data_source": CSV_PATH.name,
        "synthetic_data": True
    }
    MODEL_PATH.write_text(json.dumps(model_payload, indent=2), encoding="utf-8")
    METRICS_PATH.write_text(json.dumps(metrics, indent=2), encoding="utf-8")
    print(json.dumps({"metrics": metrics, "model_file": str(MODEL_PATH)}, indent=2))


if __name__ == "__main__":
    main()
