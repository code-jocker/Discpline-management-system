"""
SBMS AI Risk Microservice
FastAPI + scikit-learn risk classification for student behavior profiles.

Endpoints:
  POST /predict        – Predict risk level for a student's feature vector
  GET  /health         – Liveness check
  GET  /model-info     – Model metadata and feature importance
"""

from __future__ import annotations

import os
import time
from pathlib import Path
from typing import Optional

import joblib
import numpy as np
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
MODEL_PATH = Path(os.getenv("SBMS_MODEL_PATH", "/app/models/risk_model.pkl"))
CLASSES    = ["Excellent", "Warning", "Critical"]

app = FastAPI(
    title="SBMS AI Risk Service",
    description="Predicts student behavior risk level using a trained Random Forest model.",
    version="1.0.0",
)

# Lazy-loaded model cache
_model_cache: Optional[object] = None
_model_loaded_at: float = 0.0


def load_model() -> Optional[object]:
    """Load model from disk, refreshing the cache every 10 minutes."""
    global _model_cache, _model_loaded_at

    now = time.time()
    if _model_cache is not None and (now - _model_loaded_at) < 600:
        return _model_cache

    if MODEL_PATH.exists():
        try:
            _model_cache   = joblib.load(MODEL_PATH)
            _model_loaded_at = now
            return _model_cache
        except Exception as exc:
            print(f"[SBMS-AI] Failed to load model: {exc}")

    return None


def rule_based_predict(incidents: int, total_marks_deducted: int, avg_per_week: float) -> str:
    """Threshold-based fallback when no trained model is available."""
    if incidents >= 7 or total_marks_deducted >= 21 or avg_per_week >= 2.5:
        return "Critical"
    if incidents >= 3 or total_marks_deducted >= 9 or avg_per_week >= 0.5:
        return "Warning"
    return "Excellent"


# ---------------------------------------------------------------------------
# Schemas
# ---------------------------------------------------------------------------

class PredictionRequest(BaseModel):
    incidents:            int   = Field(..., ge=0, description="Total number of discipline incidents")
    total_marks_deducted: int   = Field(..., ge=0, description="Cumulative marks deducted across all terms")
    avg_per_week:         float = Field(..., ge=0.0, description="Average marks deducted per calendar week")


class PredictionResponse(BaseModel):
    risk_level:    str
    confidence:    float
    model_version: str
    source:        str   # "ml_model" | "rule_based"


# ---------------------------------------------------------------------------
# Endpoints
# ---------------------------------------------------------------------------

@app.get("/health", tags=["System"])
def health() -> dict:
    model = load_model()
    return {
        "status":       "ok",
        "model_loaded": model is not None,
        "model_path":   str(MODEL_PATH),
    }


@app.get("/model-info", tags=["System"])
def model_info() -> dict:
    model = load_model()
    if model is None:
        return {"status": "no_model", "message": "Model not trained yet. Using rule-based fallback."}

    info: dict = {
        "status":        "loaded",
        "classes":       CLASSES,
        "n_features":    3,
        "feature_names": ["incidents", "total_marks_deducted", "avg_per_week"],
    }

    # Feature importances for Random Forest
    if hasattr(model, "feature_importances_"):
        importances = model.feature_importances_.tolist()
        info["feature_importances"] = dict(
            zip(["incidents", "total_marks_deducted", "avg_per_week"], importances)
        )

    return info


@app.post("/predict", response_model=PredictionResponse, tags=["Prediction"])
def predict(request: PredictionRequest) -> PredictionResponse:
    features = np.array([[
        request.incidents,
        request.total_marks_deducted,
        request.avg_per_week,
    ]])

    model = load_model()

    if model is not None:
        try:
            risk_label = model.predict(features)[0]
            confidence = float(np.max(model.predict_proba(features)[0]))
            return PredictionResponse(
                risk_level=str(risk_label),
                confidence=round(confidence, 4),
                model_version="rf-v1",
                source="ml_model",
            )
        except Exception as exc:
            print(f"[SBMS-AI] Prediction error, falling back to rules: {exc}")

    # Rule-based fallback
    risk_label = rule_based_predict(
        request.incidents,
        request.total_marks_deducted,
        request.avg_per_week,
    )
    return PredictionResponse(
        risk_level=risk_label,
        confidence=1.0,
        model_version="rule-based-v1",
        source="rule_based",
    )
