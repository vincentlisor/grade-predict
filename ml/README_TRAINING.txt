LINEAR REGRESSION TRAINING (LOCAL COMPUTER)

1. Keep historical_dummy_raw_scores.csv in this folder.
2. Open a terminal in the project root (the folder containing this README's parent ml folder).
3. Install dependencies: python -m pip install -r ml/requirements.txt
4. Train: python ml/train_linear_regression.py
5. The script creates ml/linear_model.json (used by PHP predictions) and ml/linear_model_metrics.json (evaluation metrics).
6. Upload the generated linear_model.json to the same ml folder on your website after training.

The CSV currently contains synthetic dummy data. Use only for development/testing, not to claim real-world accuracy. The model is evaluated on an 80/20 holdout, then fitted again on all rows for the model used by the app.
