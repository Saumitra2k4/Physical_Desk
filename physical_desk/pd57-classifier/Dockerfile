FROM python:3.11-slim

WORKDIR /app

# Install dependencies first for caching
COPY requirements.txt .
RUN pip install --no-cache-dir --extra-index-url https://download.pytorch.org/whl/cpu -r requirements.txt

# Pre-download the model at build time so startup is instant
RUN python -c "from transformers import AutoModelForSequenceClassification, AutoTokenizer; \
    AutoModelForSequenceClassification.from_pretrained('cross-encoder/nli-MiniLM2-L6-H768'); \
    AutoTokenizer.from_pretrained('cross-encoder/nli-MiniLM2-L6-H768')"

COPY . .

EXPOSE 5000

HEALTHCHECK --interval=30s --timeout=10s --retries=3 \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://localhost:5000/health')" || exit 1

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "5000", "--workers", "1"]
