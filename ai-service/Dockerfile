FROM python:3.12-slim

WORKDIR /app

# Install dependencies
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy application source
COPY . .

# Create data directory for model persistence
RUN mkdir -p /app/data /app/models

EXPOSE 8000

# Train the model on startup if model doesn't exist, then launch API
CMD ["sh", "-c", "if [ ! -f /app/models/risk_model.pkl ]; then echo 'Training initial model...' && python train.py; fi && uvicorn main:app --host 0.0.0.0 --port 8000"]
