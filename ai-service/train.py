"""
SBMS Risk Model Training Script
Trains a RandomForestClassifier on discipline feature data.

Usage:
  python train.py                         # Uses /app/data/training_data.csv
  python train.py /path/to/custom.csv     # Custom dataset path

Output: /app/models/risk_model.pkl
"""

from __future__ import annotations

import sys
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
from sklearn.ensemble import RandomForestClassifier
from sklearn.metrics import classification_report, confusion_matrix
from sklearn.model_selection import cross_val_score, train_test_split
from sklearn.preprocessing import LabelEncoder

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------
DEFAULT_DATA_PATH  = Path("/app/data/training_data.csv")
DEFAULT_MODEL_PATH = Path("/app/models/risk_model.pkl")
FEATURES           = ["incidents", "total_marks_deducted", "avg_per_week"]
TARGET             = "risk_level"
RANDOM_STATE       = 42


def train(data_path: Path = DEFAULT_DATA_PATH, model_path: Path = DEFAULT_MODEL_PATH) -> None:
    print(f"[SBMS-Train] Loading data from: {data_path}")

    if not data_path.exists():
        print(f"[SBMS-Train] ERROR: Training data not found at {data_path}")
        sys.exit(1)

    df = pd.read_csv(data_path)
    print(f"[SBMS-Train] Loaded {len(df)} rows, columns: {list(df.columns)}")

    # Validate columns
    missing = [c for c in FEATURES + [TARGET] if c not in df.columns]
    if missing:
        print(f"[SBMS-Train] ERROR: Missing columns: {missing}")
        sys.exit(1)

    df = df.dropna(subset=FEATURES + [TARGET])
    print(f"[SBMS-Train] After drop-na: {len(df)} rows")

    X = df[FEATURES].astype(float).values
    y = df[TARGET].values

    # Encode labels preserving order: Excellent=0, Warning=1, Critical=2
    label_order = ["Excellent", "Warning", "Critical"]
    le = LabelEncoder()
    le.fit(label_order)
    y_enc = le.transform(y)

    print(f"[SBMS-Train] Class distribution:\n{pd.Series(y).value_counts().to_string()}")

    # Cross-validation
    clf_cv = RandomForestClassifier(
        n_estimators=200, max_depth=8, min_samples_split=3,
        class_weight="balanced", random_state=RANDOM_STATE, n_jobs=-1,
    )
    cv_scores = cross_val_score(clf_cv, X, y_enc, cv=5, scoring="accuracy")
    print(f"[SBMS-Train] CV accuracy: {cv_scores.mean():.3f} ± {cv_scores.std():.3f}")

    # Final train/test split for reporting
    X_train, X_test, y_train, y_test = train_test_split(
        X, y_enc, test_size=0.2, stratify=y_enc, random_state=RANDOM_STATE
    )
    clf = RandomForestClassifier(
        n_estimators=200, max_depth=8, min_samples_split=3,
        class_weight="balanced", random_state=RANDOM_STATE, n_jobs=-1,
    )
    clf.fit(X_train, y_train)

    y_pred = clf.predict(X_test)
    print("\n[SBMS-Train] Test set classification report:")
    print(classification_report(y_test, y_pred, target_names=le.classes_))
    print("[SBMS-Train] Confusion matrix:")
    print(confusion_matrix(y_test, y_pred))

    # Feature importances
    importances = dict(zip(FEATURES, clf.feature_importances_))
    print("\n[SBMS-Train] Feature importances:")
    for feat, imp in sorted(importances.items(), key=lambda x: -x[1]):
        print(f"  {feat}: {imp:.4f}")

    # Retrain on full dataset for the final model
    clf.fit(X, y_enc)
    # Patch the classes_ attribute so predict() returns original string labels
    clf.classes_ = le.classes_

    # Custom predict wrapper so model.predict() returns string labels
    class LabeledForest:
        def __init__(self, estimator: RandomForestClassifier, classes: list[str]) -> None:
            self._clf     = estimator
            self._classes = classes

        def predict(self, X: np.ndarray) -> np.ndarray:  # type: ignore[override]
            indices = self._clf.predict(X)
            return np.array([self._classes[int(i)] for i in indices])

        def predict_proba(self, X: np.ndarray) -> np.ndarray:
            return self._clf.predict_proba(X)

        @property
        def feature_importances_(self) -> np.ndarray:
            return self._clf.feature_importances_

    labeled_model = LabeledForest(clf, list(le.classes_))

    model_path.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump(labeled_model, model_path)
    print(f"\n[SBMS-Train] Model saved to: {model_path}")


if __name__ == "__main__":
    dp = Path(sys.argv[1]) if len(sys.argv) > 1 else DEFAULT_DATA_PATH
    train(data_path=dp)
