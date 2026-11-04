from pydantic import BaseModel, Field


class PredictionInput(BaseModel):
    incidents: int = Field(ge=0)
    total_marks_deducted: int = Field(ge=0)
    avg_per_week: float = Field(ge=0)


class PredictionOutput(BaseModel):
    risk_level: str
    confidence: float
    model_version: str
