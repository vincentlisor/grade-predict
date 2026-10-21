
from pathlib import Path
import json

ROOT = Path(__file__).resolve().parent

PRELIM_MODEL = ROOT / "prelim_model.json"
MIDTERM_MODEL = ROOT / "midterm_model.json"


def load_model(path):
    if not path.exists():
        raise FileNotFoundError(f"Model not found: {path}")
    return json.loads(path.read_text(encoding="utf-8"))


def read_gwa(prompt):
    while True:
        try:
            value = float(input(prompt))
            if 1.00 <= value <= 5.00:
                return value
            print("GWA must be between 1.00 and 5.00.")
        except ValueError:
            print("Enter a valid number, e.g. 1.50.")


def predict(model_data, values):
    result = model_data["intercept"]

    for feature, value in values.items():
        result += model_data["coefficients"][feature] * value

    return round(max(1.00, min(5.00, result)), 2)


def screen_gwa(predicted_gwa):
    if 1.00 <= predicted_gwa <= 1.25:
        return "Possible President's Lister (PL)"
    if 1.25 < predicted_gwa <= 1.75:
        return "Possible Dean's Lister (DL)"
    return "Unlikely to qualify based on predicted GWA"


def main():
    print("=== GWA PREDICTION TEST ===")
    print("1. Prelim-only prediction")
    print("2. Updated Midterm prediction")

    choice = input("Choose stage (1 or 2): ").strip()

    if choice == "1":
        model = load_model(PRELIM_MODEL)
        prelim = read_gwa("Enter Prelim GWA: ")
        predicted = predict(model, {"prelim_gwa": prelim})
        stage = "Prelim-only"

    elif choice == "2":
        model = load_model(MIDTERM_MODEL)
        prelim = read_gwa("Enter Prelim GWA: ")
        midterm = read_gwa("Enter Midterm GWA: ")
        predicted = predict(
            model,
            {"prelim_gwa": prelim, "midterm_gwa": midterm}
        )
        stage = "Prelim + Midterm"

    else:
        print("Invalid choice. Run the script again.")
        return

    print("\n=== PREDICTION RESULT ===")
    print(f"Stage: {stage}")
    print(f"Predicted Final GWA: {predicted:.2f}")
    print(f"Screening: {screen_gwa(predicted)}")
    print("\nWARNING: Dummy-data test only.")
    print("This is not final eligibility verification.")
    print("Subject-grade disqualification must be checked separately.")


if __name__ == "__main__":
    main()
