from __future__ import annotations

import pickle
from pathlib import Path

import pandas as pd
from sklearn.ensemble import RandomForestClassifier


ROOT = Path(__file__).resolve().parents[1]
DATASET = ROOT / "data" / "training_dataset.csv"
MODEL_PATH = ROOT / "models" / "risk_model.pkl"


def main() -> None:
    frame = pd.read_csv(DATASET)
    features = frame[["incidents", "total_marks_deducted", "avg_per_week"]]
    target = frame["risk_level"]

    model = RandomForestClassifier(
        n_estimators=120,
        max_depth=6,
        random_state=42,
    )
    model.fit(features, target)

    MODEL_PATH.parent.mkdir(parents=True, exist_ok=True)
    with MODEL_PATH.open("wb") as handle:
        pickle.dump(model, handle)

    print(f"Model trained and saved to {MODEL_PATH}")


if __name__ == "__main__":
    main()
