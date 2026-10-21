
import json
from datetime import datetime
from pathlib import Path

import pandas as pd
from sklearn.linear_model import LinearRegression
from sklearn.metrics import mean_absolute_error, r2_score
from sklearn.model_selection import train_test_split

ROOT = Path(__file__).resolve().parents[1]
CSV_PATH = ROOT / "dummy_historical_gwa_training.csv"
MODEL_PATH = ROOT / "ml" / "gwa_prediction_model.json"
METRICS_PATH = ROOT / "ml" / "gwa_prediction_metrics.json"


def main():
    if not CSV_PATH.exists():
        raise FileNotFoundError(f"Dataset not found: {CSV_PATH}")

    df = pd.read_csv(CSV_PATH)
    features = ["prelim_gwa", "midterm_gwa"]
    target = "actual_final_gwa"

    data = df[features + [target]].apply(pd.to_numeric, errors="coerce").dropna()

    if len(data) < 20:
        raise ValueError("At least 20 valid records are required.")

    X = data[features]
    y = data[target]

    X_train, X_test, y_train, y_test = train_test_split(
        X, y, test_size=0.2, random_state=42
    )

    evaluation_model = LinearRegression()
    evaluation_model.fit(X_train, y_train)
    predictions = evaluation_model.predict(X_test)

    metrics = {
        "model": "Linear Regression",
        "mae": round(float(mean_absolute_error(y_test, predictions)), 4),
        "r2": round(float(r2_score(y_test, predictions)), 4),
        "sample_size": len(data),
        "train_size": len(X_train),
        "test_size": len(X_test),
        "trained_at": datetime.now().isoformat(timespec="seconds"),
        "data_source": CSV_PATH.name,
        "synthetic_data": True
    }

    model = LinearRegression()
    model.fit(X, y)

    payload = {
        "model": "Linear Regression",
        "features": features,
        "intercept": float(model.intercept_),
        "coefficients": dict(zip(features, map(float, model.coef_))),
        "sample_size": len(data),
        "trained_at": metrics["trained_at"],
        "synthetic_data": True
    }

    MODEL_PATH.write_text(json.dumps(payload, indent=2), encoding="utf-8")
    METRICS_PATH.write_text(json.dumps(metrics, indent=2), encoding="utf-8")

    print(json.dumps(metrics, indent=2))
    print(f"Model saved to: {MODEL_PATH}")


if __name__ == "__main__":
    main()
