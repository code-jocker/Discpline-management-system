from fastapi import FastAPI

from .model_service import ModelService
from .schemas import PredictionInput, PredictionOutput

app = FastAPI(title="SBMS AI Risk Service", version="1.0.0")
service = ModelService()


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


@app.post("/predict", response_model=PredictionOutput)
def predict(payload: PredictionInput) -> PredictionOutput:
    result = service.predict(
        incidents=payload.incidents,
        total_marks_deducted=payload.total_marks_deducted,
        avg_per_week=payload.avg_per_week,
    )
    return PredictionOutput(**result)
