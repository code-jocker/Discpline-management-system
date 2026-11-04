from __future__ import annotations

import os
import pickle
from pathlib import Path
from typing import Any


class ModelService:
    def __init__(self) -> None:
        self.model_path = Path(os.getenv("SBMS_MODEL_PATH", "models/risk_model.pkl"))
        self.model_version = os.getenv("SBMS_MODEL_VERSION", "phase1-threshold-ml")
        self.model = self._load_model()

    def _load_model(self) -> Any | None:
        if not self.model_path.exists():
            return None

        with self.model_path.open("rb") as handle:
            return pickle.load(handle)

    def predict(self, incidents: int, total_marks_deducted: int, avg_per_week: float) -> dict[str, Any]:
        if self.model is None:
            return self._fallback_prediction(incidents, total_marks_deducted, avg_per_week)

        features = [[incidents, total_marks_deducted, avg_per_week]]
        risk_level = self.model.predict(features)[0]
        confidence = 0.8

        if hasattr(self.model, "predict_proba"):
            probabilities = self.model.predict_proba(features)[0]
            confidence = float(max(probabilities))

        return {
            "risk_level": str(risk_level),
            "confidence": round(confidence, 4),
            "model_version": self.model_version,
        }

    def _fallback_prediction(self, incidents: int, total_marks_deducted: int, avg_per_week: float) -> dict[str, Any]:
        if incidents >= 8 or total_marks_deducted >= 40 or avg_per_week >= 4:
            risk = "Critical"
        elif incidents >= 3 or total_marks_deducted >= 15 or avg_per_week >= 1.5:
            risk = "Warning"
        else:
            risk = "Excellent"

        return {
            "risk_level": risk,
            "confidence": 0.72,
            "model_version": self.model_version + "-fallback",
        }
